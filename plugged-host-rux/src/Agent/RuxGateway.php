<?php

namespace PluggedHost\Rux\Agent;

use Illuminate\Support\Str;
use PluggedHost\Core\Services\NoprofitClient;
use PluggedHost\Rux\Agent\Gateway\GatewayBusyException;
use PluggedHost\Rux\Agent\Gateway\GatewayUnavailableException;
use PluggedHost\Rux\Agent\Gateway\LimitReachedException;
use PluggedHost\Rux\Agent\Gateway\NotLinkedException;
use Throwable;

/**
 * One model turn through noprofit.space's neo gateway (/api/v1/internal/neo/turn), which holds
 * the provider chain and meters every turn against the commanding admin's own neo limits.
 * Same wire format and status codes as neo's NeoGateway, so the same exceptions.
 */
class RuxGateway
{
    public function __construct(private readonly NoprofitClient $client) {}

    public function configured(): bool
    {
        return $this->client->configured($this->secret());
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @return array<string, mixed>
     *
     * @throws LimitReachedException|NotLinkedException|GatewayBusyException|GatewayUnavailableException
     */
    public function turn(int $accountId, string $conversation, array $messages, array $tools, string $toolChoice): array
    {
        if (!$this->configured()) {
            throw new GatewayUnavailableException('not_configured');
        }

        try {
            $response = $this->client->send('POST', '/api/v1/internal/neo/turn', [
                'account_id' => $accountId,
                'service' => 'rux',
                'conversation' => Str::limit($conversation, 64, ''),
                'request_id' => (string) Str::uuid(),
                'messages' => $messages,
                'tools' => $tools,
                'tool_choice' => $toolChoice,
            ], $this->secret(), (int) config('plugged-host-rux.gateway_timeout', 90));
        } catch (Throwable $e) {
            report($e);

            throw new GatewayUnavailableException('unreachable', 10);
        }

        $data = (array) $response->json();

        if ($response->successful()) {
            return $data;
        }

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

    private function secret(): string
    {
        return (string) config('plugged-host-rux.gateway_secret', '');
    }
}
