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
use PluggedHost\Ai\Services\Gateway\ModelUnavailableException;
use PluggedHost\Ai\Services\Gateway\NotLinkedException;
use PluggedHost\Ai\Support\NeoModel;
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
 * Every call says the account's plan here (NeoModel::plan()); a chat turn names the model its
 * user picked (NeoModel), and runs on that one only. A chat turn is streamed: its text and thinking
 * are handed on while the model writes them.
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
     * One model turn in the canonical chat shape. With $onDelta it is streamed: $onDelta('text'
     * or 'thinking', …) while the model writes, $onDelta('reset', '') when the gateway sends the
     * call again and what came so far is void.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @param  (callable(string, string): void)|null  $onDelta
     * @return array<string, mixed>
     *
     * @throws LimitReachedException|NotLinkedException|GatewayBusyException|GatewayUnavailableException
     */
    public function turn(User $user, NeoConversation $conversation, array $messages, array $tools, string $toolChoice, ?callable $onDelta = null): array
    {
        $body = NoprofitLink::identity($user) + [
            'service' => 'plugged-host',
            'usage_percent' => NeoUsagePercent::for($user),
            'plan' => NeoModel::plan($user),
            'model' => NeoModel::forTurn($user),
            'conversation' => $conversation->uuid,
            'request_id' => (string) Str::uuid(),
            'messages' => $messages,
            'tools' => $tools,
            'tool_choice' => $toolChoice,
        ];

        $data = $onDelta === null ? $this->plainTurn($body) : $this->streamedTurn($body, $onDelta);

        $this->rememberAccount($user, $data);
        Cache::put($this->usageKey($user), $this->usageFrom($data), 30);

        return $data;
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
            'plan' => NeoModel::plan(self::panelUserForDiscord($discordId)),
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

        $this->throwFor($response->status(), $data, (int) $response->header('Retry-After'));
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

        $response = $this->sendTwice('POST', '/usage', [
            'discord_id' => $discordId,
            'service' => self::DISCORD_SERVICE,
            'usage_percent' => NeoUsagePercent::for(self::panelUserForDiscord($discordId)),
            'plan' => NeoModel::plan(self::panelUserForDiscord($discordId)),
        ], 8);

        if (!$response->successful()) {
            throw new GatewayUnavailableException('usage_http_' . $response->status());
        }

        $data = $this->withPanelUser((array) $response->json());
        Cache::put($this->discordKey($discordId), $data, 20);

        return $data;
    }

    /**
     * The account's windows, the limits mode and the models it can pick, cached briefly. Null
     * when unknown (gateway down); ['linked' => false] when noprofit.space does not know the account.
     *
     * @return array<string, mixed>|null
     */
    public function usage(User $user): ?array
    {
        $cached = Cache::get($this->usageKey($user));
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = $this->sendTwice('POST', '/usage', NoprofitLink::identity($user) + [
                'service' => 'plugged-host',
                'usage_percent' => NeoUsagePercent::for($user),
                'plan' => NeoModel::plan($user),
            ], 8);
        } catch (Throwable) {
            return null;
        }

        $result = match (true) {
            $response->successful() => $this->usageFrom((array) $response->json()),
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
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws LimitReachedException|NotLinkedException|GatewayBusyException|GatewayUnavailableException
     */
    private function plainTurn(array $body): array
    {
        $response = $this->send('POST', '/turn', $body, $this->timeout());
        $data = (array) $response->json();

        if ($response->successful()) {
            return $data;
        }

        $this->throwFor($response->status(), $data, (int) $response->header('Retry-After'));
    }

    /**
     * A turn whose answer is streamed (InternalNeoController on noprofit.space): deltas go to
     * $onDelta as they come, the last event is the turn's result or its error.
     *
     * @param  array<string, mixed>  $body
     * @param  callable(string, string): void  $onDelta
     * @return array<string, mixed>
     *
     * @throws LimitReachedException|NotLinkedException|GatewayBusyException|GatewayUnavailableException
     */
    private function streamedTurn(array $body, callable $onDelta): array
    {
        if (!$this->configured()) {
            throw new GatewayUnavailableException('not_configured');
        }

        $final = null;

        try {
            $answer = $this->client->stream(self::BASE . '/turn', $body + ['stream' => true], $this->secret(), $this->streamTimeout(), 120, static function (string $data) use (&$final, $onDelta): void {
                $event = json_decode($data, true);

                if (!is_array($event)) {
                    return;
                }

                match ($event['type'] ?? null) {
                    'delta' => $onDelta((string) ($event['kind'] ?? 'text'), (string) ($event['text'] ?? '')),
                    'result', 'error' => $final = $event,
                    default => null,
                };
            });
        } catch (Throwable $e) {
            report($e);

            throw new GatewayUnavailableException('unreachable', 10);
        }

        if (is_array($final) && ($final['type'] ?? null) === 'result') {
            unset($final['type']);

            return $final;
        }

        if (is_array($final)) {
            $this->throwFor((int) ($final['status'] ?? 503), $final, 0);
        }

        if ($answer['status'] >= 400) {
            $this->throwFor($answer['status'], (array) json_decode($answer['body'], true), 0);
        }

        throw new GatewayUnavailableException($answer['error'] !== null ? 'unreachable' : 'stream_ended', 10);
    }

    /**
     * What usage() keeps of an answer from /usage or /turn.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function usageFrom(array $data): array
    {
        return [
            'linked' => true,
            'limits' => $data['limits'] ?? null,
            'mode' => $data['mode'] ?? 'all',
            'models' => array_values(array_filter((array) ($data['models'] ?? []), 'is_array')),
            'default_model' => isset($data['default_model']) ? (string) $data['default_model'] : null,
        ];
    }

    /**
     * send(), once more when the connection itself failed (the way to noprofit.space runs through
     * Cloudflare and now and then a request is lost on it). For calls that change nothing.
     *
     * @param  array<string, mixed>|null  $body
     *
     * @throws GatewayUnavailableException
     */
    private function sendTwice(string $method, string $path, ?array $body, int $timeout): Response
    {
        try {
            return $this->send($method, $path, $body, $timeout, false);
        } catch (GatewayUnavailableException) {
            return $this->send($method, $path, $body, $timeout);
        }
    }

    /**
     * @param  array<string, mixed>|null  $body
     *
     * @throws GatewayUnavailableException
     */
    private function send(string $method, string $path, ?array $body, int $timeout, bool $report = true): Response
    {
        if (!$this->configured()) {
            throw new GatewayUnavailableException('not_configured');
        }

        try {
            return $this->client->send($method, self::BASE . $path, $body, $this->secret(), $timeout);
        } catch (Throwable $e) {
            if ($report) {
                report($e);
            }

            throw new GatewayUnavailableException('unreachable', 10);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws LimitReachedException|NotLinkedException|GatewayBusyException|GatewayUnavailableException
     */
    private function throwFor(int $status, array $data, int $retryAfterHeader): never
    {
        $retryAfter = (int) ($data['retry_after'] ?? $retryAfterHeader);

        throw match (true) {
            $status === 429 => new LimitReachedException(
                (string) ($data['window'] ?? 'short'),
                is_string($data['retry_at'] ?? null) ? $data['retry_at'] : null,
                (array) ($data['limits'] ?? []),
            ),
            $status === 404 => new NotLinkedException('account_not_linked'),
            $status === 422 && str_starts_with((string) ($data['error'] ?? ''), 'model_') || ($data['error'] ?? null) === 'no_model' => new ModelUnavailableException(
                (string) $data['error'],
                is_string($data['label'] ?? null) ? $data['label'] : null,
                is_string($data['access'] ?? null) ? $data['access'] : null,
            ),
            $status === 409, ($data['error'] ?? null) === 'busy' => new GatewayBusyException('busy', max(1, $retryAfter)),
            default => new GatewayUnavailableException((string) ($data['error'] ?? 'http_' . $status), $retryAfter),
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

    /**
     * How long a streamed turn may take in all (the gateway's own deadline is shorter); silence
     * ends it sooner, since the gateway pings while the model is quiet.
     */
    private function streamTimeout(): int
    {
        return (int) config('plugged-host-noprofit.neo_gateway_stream_timeout', 290);
    }
}
