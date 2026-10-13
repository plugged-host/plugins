<?php

namespace PluggedHost\Ai\Services;

use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PluggedHost\Core\Services\NoprofitClient;
use PluggedHost\Core\Support\NoprofitLink;
use PluggedHost\Ai\Models\NeoConversation;
use PluggedHost\Ai\Services\Gateway\GatewayBusyException;
use PluggedHost\Ai\Services\Gateway\GatewayUnavailableException;
use PluggedHost\Ai\Services\Gateway\LimitReachedException;
use PluggedHost\Ai\Services\Gateway\NotLinkedException;
use PluggedHost\Ai\Support\NeoPreferences;
use Throwable;

/**
 * neo's side of noprofit.space's /api/v1/internal/neo/* API. noprofit.space holds the provider
 * keys and the usage limits; this class only builds signed requests (through core's
 * NoprofitClient) and turns the gateway's status codes into typed exceptions.
 *
 * The panel chat names its user's account; neo on Discord names a Discord account, which
 * noprofit.space meters as the account it is linked to, or on its own at a share of the limits.
 *
 * A chat turn names the model its user picked: 'premium' (noprofit.space's premium slot, counted
 * at a multiple of its tokens) for PlusPlus accounts that picked it, 'standard' otherwise.
 */
final class NeoGateway
{
    private const BASE = '/api/v1/internal/neo';

    /** The service neo on Discord's turns are recorded under on noprofit.space. */
    public const DISCORD_SERVICE = 'plugged-host-discord';

    public function __construct(private readonly NoprofitClient $client) {}

    public function configured(): bool
    {
        return $this->client->configured($this->secret());
    }

    /**
     * One model turn in the canonical chat shape.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @return array<string, mixed>
     *
     * @throws LimitReachedException|NotLinkedException|GatewayBusyException|GatewayUnavailableException
     */
    public function turn(User $user, NeoConversation $conversation, array $messages, array $tools, string $toolChoice): array
    {
        $response = $this->send('POST', '/turn', NoprofitLink::identity($user) + [
            'service' => 'plugged-host',
            'usage_percent' => self::usagePercent($user),
            'model' => self::modelFor($user),
            'conversation' => $conversation->uuid,
            'request_id' => (string) Str::uuid(),
            'messages' => $messages,
            'tools' => $tools,
            'tool_choice' => $toolChoice,
        ], (int) config('plugged-host-ai.gateway_timeout', 90));

        $data = (array) $response->json();

        if ($response->successful()) {
            $this->rememberAccount($user, $data);
            Cache::put($this->usageKey($user), ['linked' => true, 'limits' => $data['limits'] ?? null, 'premium_model' => $data['premium_model'] ?? null], 30);

            return $data;
        }

        $this->throwFor($response, $data);
    }

    /**
     * One model turn for neo on Discord, metered against that Discord account.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @return array<string, mixed>
     *
     * @throws LimitReachedException|GatewayBusyException|GatewayUnavailableException
     */
    public function discordTurn(string $discordId, string $conversation, array $messages, array $tools, string $toolChoice): array
    {
        $response = $this->send('POST', '/turn', [
            'discord_id' => $discordId,
            'service' => self::DISCORD_SERVICE,
            'usage_percent' => self::usagePercent(self::panelUserForDiscord($discordId)),
            'conversation' => 'discord:' . $conversation,
            'request_id' => (string) Str::uuid(),
            'messages' => $messages,
            'tools' => $tools,
            'tool_choice' => $toolChoice,
        ], (int) config('plugged-host-ai.gateway_timeout', 90));

        $data = (array) $response->json();

        if ($response->successful()) {
            Cache::forget($this->discordKey($discordId));

            return $data;
        }

        $this->throwFor($response, $data);
    }

    /**
     * Who a Discord account is on noprofit.space and where its windows stand: linked, account_id
     * and name (when linked), limits, blocked (the window that refuses new turns, or null) and
     * unlinked_percent. Cached briefly, so a burst of messages asks once.
     *
     * @return array<string, mixed>
     *
     * @throws GatewayUnavailableException
     */
    public function discordUsage(string $discordId): array
    {
        $cached = Cache::get($this->discordKey($discordId));
        if (is_array($cached)) {
            return $cached;
        }

        $response = $this->send('POST', '/usage', [
            'discord_id' => $discordId,
            'service' => self::DISCORD_SERVICE,
            'usage_percent' => self::usagePercent(self::panelUserForDiscord($discordId)),
        ], 8);

        if (!$response->successful()) {
            throw new GatewayUnavailableException('usage_http_' . $response->status());
        }

        $data = (array) $response->json();
        Cache::put($this->discordKey($discordId), $data, 20);

        return $data;
    }

    /**
     * The account's current windows and the premium model on offer (label and multiplier, null
     * while there is none), cached briefly. Null when unknown (gateway down); ['linked' => false]
     * when noprofit.space does not know the account.
     *
     * @return array{linked: bool, limits?: array<string, mixed>|null, premium_model?: array{label: string, multiplier: int}|null}|null
     */
    public function usage(User $user): ?array
    {
        $cached = Cache::get($this->usageKey($user));
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = $this->send('POST', '/usage', NoprofitLink::identity($user) + ['service' => 'plugged-host', 'usage_percent' => self::usagePercent($user)], 8);
        } catch (Throwable) {
            return null;
        }

        $result = match (true) {
            $response->successful() => ['linked' => true, 'limits' => $response->json('limits'), 'premium_model' => $response->json('premium_model')],
            $response->status() === 404 => ['linked' => false],
            default => null,
        };

        if ($result !== null) {
            $this->rememberAccount($user, (array) $response->json());
            Cache::put($this->usageKey($user), $result, 30);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws GatewayUnavailableException
     */
    public function config(): array
    {
        $response = $this->send('GET', '/config', null, 10);

        if (!$response->successful()) {
            throw new GatewayUnavailableException('config_http_' . $response->status());
        }

        return (array) $response->json();
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed> The gateway's JSON (fresh config, or an error body with 'error').
     *
     * @throws GatewayUnavailableException
     */
    public function saveConfig(array $settings, User $actor): array
    {
        $response = $this->send('PUT', '/config', ['actor' => $this->actor($actor)] + $settings, 15);

        if ($response->serverError() || $response->status() === 401) {
            throw new GatewayUnavailableException('config_http_' . $response->status());
        }

        return (array) $response->json();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     *
     * @throws GatewayUnavailableException
     */
    public function testProvider(int $slot, array $overrides, User $actor): array
    {
        $response = $this->send('POST', "/providers/{$slot}/test", ['actor' => $this->actor($actor), 'overrides' => (object) $overrides], 40);

        if ($response->serverError() || $response->status() === 401) {
            throw new GatewayUnavailableException('test_http_' . $response->status());
        }

        return (array) $response->json();
    }

    /**
     * @param  array<string, mixed>|null  $body
     *
     * @throws GatewayUnavailableException
     */
    private function send(string $method, string $path, ?array $body, int $timeout): Response
    {
        if (!$this->configured()) {
            throw new GatewayUnavailableException('not_configured');
        }

        try {
            return $this->client->send($method, self::BASE . $path, $body, $this->secret(), $timeout);
        } catch (Throwable $e) {
            report($e);

            throw new GatewayUnavailableException('unreachable', 10);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws LimitReachedException|NotLinkedException|GatewayBusyException|GatewayUnavailableException
     */
    private function throwFor(Response $response, array $data): never
    {
        $retryAfter = (int) ($data['retry_after'] ?? $response->header('Retry-After') ?: 0);

        throw match (true) {
            $response->status() === 429 => new LimitReachedException(
                (string) ($data['window'] ?? 'short'),
                is_string($data['retry_at'] ?? null) ? $data['retry_at'] : null,
                (array) ($data['limits'] ?? []),
            ),
            $response->status() === 404 => new NotLinkedException('account_not_linked'),
            $response->status() === 409, ($data['error'] ?? null) === 'busy' => new GatewayBusyException('busy', max(1, $retryAfter)),
            default => new GatewayUnavailableException((string) ($data['error'] ?? 'http_' . $response->status()), $retryAfter),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function rememberAccount(User $user, array $data): void
    {
        $accountId = (int) ($data['account_id'] ?? 0);

        if ($accountId > 0 && NoprofitLink::accountId($user) === null) {
            NoprofitLink::remember($user, $accountId);
        }
    }

    /**
     * @return array{id: string, name: string}
     */
    private function actor(User $actor): array
    {
        return ['id' => (string) $actor->id, 'name' => (string) $actor->username];
    }

    /**
     * How much neo this account gets, as a percentage of the free limits: more with
     * plugged.host Plus and PlusPlus (plugged-host-economy's MembershipService).
     */
    public static function usagePercent(?User $user): int
    {
        $membership = 'PluggedHost\\Economy\\Services\\MembershipService';

        try {
            return $user !== null && class_exists($membership) ? max(100, (int) app($membership)->aiUsagePercent($user)) : 100;
        } catch (Throwable) {
            return 100;
        }
    }

    /**
     * Whether the account may use the premium model: plugged.host PlusPlus (plugged-host-economy).
     */
    public static function premiumAllowed(?User $user): bool
    {
        $membership = 'PluggedHost\\Economy\\Services\\MembershipService';

        try {
            return $user !== null && class_exists($membership) && app($membership)->hasPlusPlus($user);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The model this account's chat turns ask for: the one picked in the chat, while it may still
     * use it (PlusPlus can run out after it was picked).
     */
    public static function modelFor(User $user): string
    {
        return NeoPreferences::model($user) === 'premium' && self::premiumAllowed($user) ? 'premium' : 'standard';
    }

    /**
     * The panel account a Discord account is linked to (plugged-host-discord's mirror), if any.
     */
    private static function panelUserForDiscord(string $discordId): ?User
    {
        $links = 'PluggedHost\\Discord\\Models\\DiscordLink';

        try {
            return class_exists($links) ? $links::query()->where('discord_id', $discordId)->first()?->user : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function discordKey(string $discordId): string
    {
        return "plugged-host-ai:neo-bot:usage:{$discordId}";
    }

    private function usageKey(User $user): string
    {
        return "plugged-host-ai:usage:{$user->id}";
    }

    private function secret(): string
    {
        return (string) config('plugged-host-ai.gateway_secret', '');
    }
}
