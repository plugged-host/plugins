<?php

namespace PluggedHost\Ai\Rux\Noprofit;

use Illuminate\Http\Client\Response;
use PluggedHost\Core\Services\NoprofitClient;
use Throwable;

/**
 * rux's side of noprofit.space's /api/v1/internal/rux/* API, signed with the credit sync
 * secret. noprofit.space is the truth for three things rux needs: whose Discord is linked
 * (and whether that account is an admin), the winners of recent giveaways, and paying winners.
 */
class RuxNoprofit
{
    private const BASE = '/api/v1/internal/rux';

    public function __construct(private readonly NoprofitClient $client) {}

    public function configured(): bool
    {
        return $this->client->configured($this->secret());
    }

    /**
     * The linked ones among these Discord ids, keyed by Discord id.
     *
     * @param  list<string>  $discordIds
     * @return array<string, array{account_id: int, name: string, is_admin: bool}>
     *
     * @throws NoprofitUnavailable
     */
    public function accounts(array $discordIds, int $timeout = 8): array
    {
        $discordIds = array_values(array_unique(array_map('strval', $discordIds)));
        $found = [];

        foreach (array_chunk($discordIds, 1000) as $chunk) {
            $response = $this->send('/accounts', ['discord_ids' => $chunk], $timeout);

            foreach ((array) $response->json('accounts', []) as $row) {
                if (!is_array($row) || !isset($row['discord_id'], $row['account_id'])) {
                    continue;
                }

                $found[(string) $row['discord_id']] = [
                    'account_id' => (int) $row['account_id'],
                    'name' => (string) ($row['name'] ?? ''),
                    'is_admin' => ($row['is_admin'] ?? false) === true,
                ];
            }
        }

        return $found;
    }

    /**
     * @return array{account_id: int, name: string, is_admin: bool}|null Null when not linked.
     *
     * @throws NoprofitUnavailable
     */
    public function account(string $discordId, int $timeout = 8): ?array
    {
        return $this->accounts([$discordId], $timeout)[$discordId] ?? null;
    }

    /**
     * The newest giveaways on noprofit.space (rux's and everyone else's), newest first.
     *
     * @return list<array{id: int, prize: string, amount: int, created_at: ?string, winners: list<string>}>
     *
     * @throws NoprofitUnavailable
     */
    public function recentGiveaways(int $limit): array
    {
        $response = $this->send('/giveaways/recent', ['limit' => $limit], 10);

        return array_values(array_map(static fn (array $row): array => [
            'id' => (int) ($row['id'] ?? 0),
            'prize' => (string) ($row['prize'] ?? ''),
            'amount' => (int) ($row['amount'] ?? 0),
            'created_at' => is_string($row['created_at'] ?? null) ? $row['created_at'] : null,
            'winners' => array_values(array_map('strval', (array) ($row['winners'] ?? []))),
        ], array_filter((array) $response->json('giveaways', []), 'is_array')));
    }

    /**
     * Have noprofit.space pay these winners. Idempotent on $key: calling again with the same key
     * pays nobody twice and answers with the giveaway as it is.
     *
     * @param  list<string>  $discordIds
     * @return array{giveaway_id: int, created: bool, recipients: array<string, array{status: string, account_id: ?int}>}
     *
     * @throws NoprofitUnavailable
     */
    public function payGiveaway(string $key, string $prize, int $amount, array $discordIds, ?int $createdBy): array
    {
        $response = $this->send('/giveaways', [
            'key' => $key,
            'prize' => $prize,
            'amount' => $amount,
            'discord_ids' => array_values($discordIds),
            'created_by' => $createdBy,
        ], 20);

        $recipients = [];
        foreach ((array) $response->json('recipients', []) as $row) {
            if (is_array($row) && isset($row['discord_id'])) {
                $recipients[(string) $row['discord_id']] = [
                    'status' => (string) ($row['status'] ?? 'pending'),
                    'account_id' => isset($row['account_id']) ? (int) $row['account_id'] : null,
                ];
            }
        }

        return [
            'giveaway_id' => (int) $response->json('giveaway_id', 0),
            'created' => (bool) $response->json('created', false),
            'recipients' => $recipients,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws NoprofitUnavailable
     */
    private function send(string $path, array $body, int $timeout): Response
    {
        if (!$this->configured()) {
            throw new NoprofitUnavailable('noprofit.space is not configured on this panel (NOPROFIT_SYNC_SECRET).');
        }

        try {
            $response = $this->client->send('POST', self::BASE . $path, $body, $this->secret(), $timeout);
        } catch (Throwable $e) {
            throw new NoprofitUnavailable('noprofit.space could not be reached: ' . class_basename($e), 0, $e);
        }

        if (!$response->successful()) {
            $error = (string) ($response->json('error') ?? $response->json('message') ?? '');

            throw new NoprofitUnavailable('noprofit.space answered HTTP ' . $response->status() . ($error !== '' ? " ({$error})" : '') . '.');
        }

        return $response;
    }

    private function secret(): string
    {
        return (string) config('plugged-host-core.noprofit.sync_secret', '');
    }
}
