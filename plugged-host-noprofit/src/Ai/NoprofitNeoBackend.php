<?php

namespace PluggedHost\Noprofit\Ai;

use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PluggedHost\Ai\Contracts\NeoBackend;
use PluggedHost\Ai\Contracts\NeoSettingsStore;
use PluggedHost\Ai\Models\NeoConversation;
use PluggedHost\Ai\Services\Gateway\GatewayBusyException;
use PluggedHost\Ai\Services\Gateway\GatewayUnavailableException;
use PluggedHost\Ai\Services\Gateway\LimitReachedException;
use PluggedHost\Ai\Services\Gateway\NotLinkedException;
use PluggedHost\Ai\Support\NeoUsagePercent;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Noprofit\Services\NoprofitClient;
use PluggedHost\Noprofit\Support\NoprofitLink;
use Throwable;

/**
 * neo's side of noprofit.space's /api/v1/internal/neo/* API (plugged-host-ai's NeoGateway until
 * 2.2). noprofit.space holds the provider keys and the usage limits; this class only builds signed
 * requests (through NoprofitClient) and turns the gateway's status codes into typed exceptions. It
 * is plugged-host-ai's NeoBackend and NeoSettingsStore while this plugin is enabled.
 *
 * The panel chat names its user's account; neo on Discord names a Discord account, which
 * noprofit.space meters as the account it is linked to, or on its own at a share of the limits.
 */
final class NoprofitNeoBackend implements NeoBackend, NeoSettingsStore
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
            'usage_percent' => NeoUsagePercent::for($user),
            'conversation' => $conversation->uuid,
            'request_id' => (string) Str::uuid(),
            'messages' => $messages,
            'tools' => $tools,
            'tool_choice' => $toolChoice,
        ], $this->timeout());

        $data = (array) $response->json();

        if ($response->successful()) {
            $this->rememberAccount($user, $data);
            Cache::put($this->usageKey($user), ['linked' => true, 'limits' => $data['limits'] ?? null], 30);

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
            'usage_percent' => NeoUsagePercent::for(self::panelUserForDiscord($discordId)),
            'conversation' => 'discord:' . $conversation,
            'request_id' => (string) Str::uuid(),
            'messages' => $messages,
            'tools' => $tools,
            'tool_choice' => $toolChoice,
        ], $this->timeout());

        $data = (array) $response->json();

        if ($response->successful()) {
            Cache::forget($this->discordKey($discordId));

            return $data;
        }

        $this->throwFor($response, $data);
    }

    /**
     * Who a Discord account is on noprofit.space and where its windows stand: linked, account_id,
     * user_id (the panel user of that account, if it has one) and name (when linked), limits,
     * blocked (the window that refuses new turns, or null) and unlinked_percent. Cached briefly,
     * so a burst of messages asks once.
     *
     * @return array<string, mixed>
     *
     * @throws GatewayUnavailableException
     */
    public function discordUsage(string $discordId): array
    {
        $cached = Cache::get($this->discordKey($discordId));
        if (is_array($cached)) {
            return $this->withPanelUser($cached);
        }

        $response = $this->send('POST', '/usage', [
            'discord_id' => $discordId,
            'service' => self::DISCORD_SERVICE,
            'usage_percent' => NeoUsagePercent::for(self::panelUserForDiscord($discordId)),
        ], 8);

        if (!$response->successful()) {
            throw new GatewayUnavailableException('usage_http_' . $response->status());
        }

        $data = $this->withPanelUser((array) $response->json());
        Cache::put($this->discordKey($discordId), $data, 20);

        return $data;
    }

    /**
     * The account's current windows, cached briefly. Null when unknown (gateway down);
     * ['linked' => false] when noprofit.space does not know the account.
     *
     * @return array{linked: bool, limits?: array<string, mixed>|null}|null
     */
    public function usage(User $user): ?array
    {
        $cached = Cache::get($this->usageKey($user));
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = $this->send('POST', '/usage', NoprofitLink::identity($user) + ['service' => 'plugged-host', 'usage_percent' => NeoUsagePercent::for($user)], 8);
        } catch (Throwable) {
            return null;
        }

        $result = match (true) {
            $response->successful() => ['linked' => true, 'limits' => $response->json('limits')],
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
     * A panel user noprofit.space does not know yet signs in there once (/sso/link).
     */
    public function activationUrl(): ?string
    {
        return Route::has('plugged-host.sso.link') ? route('plugged-host.sso.link') : null;
    }

    public function label(): string
    {
        return 'noprofit.space';
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
     * The panel user of the noprofit.space account a Discord account is linked to (user_id), if
     * it has one.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withPanelUser(array $data): array
    {
        if (!array_key_exists('user_id', $data)) {
            $data['user_id'] = !empty($data['linked']) && (int) ($data['account_id'] ?? 0) > 0 ? NoprofitLink::userFor((int) $data['account_id'])?->id : null;
        }

        return $data;
    }

    /**
     * @return array{id: string, name: string}
     */
    private function actor(User $actor): array
    {
        return ['id' => (string) $actor->id, 'name' => (string) $actor->username];
    }

    /**
     * The panel account a Discord account is linked to (plugged-host-discord's mirror), if any.
     */
    private static function panelUserForDiscord(string $discordId): ?User
    {
        $links = 'PluggedHost\\Discord\\Models\\DiscordLink';

        try {
            return Plugins::available($links) ? $links::query()->where('discord_id', $discordId)->first()?->user : null;
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
        return (string) config('plugged-host-noprofit.neo_gateway_secret', '');
    }

    private function timeout(): int
    {
        return (int) config('plugged-host-noprofit.neo_gateway_timeout', 90);
    }
}
