<?php

namespace PluggedHost\Neo\Services;

use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PluggedHost\Core\Services\NoprofitClient;
use PluggedHost\Core\Support\NoprofitLink;
use PluggedHost\Neo\Models\NeoConversation;
use PluggedHost\Neo\Services\Gateway\GatewayBusyException;
use PluggedHost\Neo\Services\Gateway\GatewayUnavailableException;
use PluggedHost\Neo\Services\Gateway\LimitReachedException;
use PluggedHost\Neo\Services\Gateway\NotLinkedException;
use Throwable;

/**
 * neo's side of noprofit.space's /api/v1/internal/neo/* API. noprofit.space holds the provider
 * keys and the usage limits; this class only builds signed requests (through core's
 * NoprofitClient) and turns the gateway's status codes into typed exceptions.
 */
final class NeoGateway
{
    private const BASE = '/api/v1/internal/neo';

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
            'conversation' => $conversation->uuid,
            'request_id' => (string) Str::uuid(),
            'messages' => $messages,
            'tools' => $tools,
            'tool_choice' => $toolChoice,
        ], (int) config('plugged-host-neo.gateway_timeout', 90));

        $data = (array) $response->json();

        if ($response->successful()) {
            $this->rememberAccount($user, $data);
            Cache::put($this->usageKey($user), ['linked' => true, 'limits' => $data['limits'] ?? null], 30);

            return $data;
        }

        $this->throwFor($response, $data);
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
            $response = $this->send('POST', '/usage', NoprofitLink::identity($user) + ['service' => 'plugged-host'], 8);
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

    private function usageKey(User $user): string
    {
        return "plugged-host-neo:usage:{$user->id}";
    }

    private function secret(): string
    {
        return (string) config('plugged-host-neo.gateway_secret', '');
    }
}
