<?php

namespace PluggedHost\Core\Services;

use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PluggedHost\Core\Support\NoprofitLink;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Discord\Models\DiscordLink;
use Throwable;

/**
 * What the panel tells noprofit.space about a linked account, the moment it changes:
 *
 *  - the snapshot (sync secret): what its servers use, and its Discord server membership and
 *    reward counters. Membership decides the tier of the compute pool on noprofit.space,
 *    whose plan comes back with the credit sync (NoprofitCreditSync).
 *  - the link (migration secret): which panel user an account belongs to. The panel's own
 *    link is the one that counts; when noprofit.space has another one (a user re-created
 *    here, an account born there), it is corrected from here.
 *
 * Sent right after the request (or the transaction, in a job or command), never through the
 * queue. noprofit:accounts-sync sends whatever changed once a minute and everything every
 * quarter hour, so nothing depends on a single call arriving.
 */
final class NoprofitAccountSync
{
    private const HASH_KEY = 'plugged-host-core:noprofit-snapshot:';

    /** @var array<int, true> */
    private static array $scheduled = [];

    public static function enabled(): bool
    {
        return app(NoprofitClient::class)->configured(self::syncSecret());
    }

    /**
     * Send this user's snapshot once the current transaction is committed.
     */
    public static function schedule(int $userId): void
    {
        if ($userId < 1 || isset(self::$scheduled[$userId])) {
            return;
        }

        self::$scheduled[$userId] = true;

        DB::afterCommit(function () use ($userId): void {
            $send = function () use ($userId): void {
                unset(self::$scheduled[$userId]);
                self::push([$userId]);
            };

            app()->runningInConsole() ? $send() : app()->terminating($send);
        });
    }

    /**
     * Send the snapshots of these users that changed since they were last sent (all of them
     * with $force). Returns how many were sent.
     *
     * @param  array<int, int>  $userIds
     */
    public static function push(array $userIds, bool $force = false, bool $relink = true): int
    {
        if (!self::enabled() || $userIds === []) {
            return 0;
        }

        $sent = 0;
        $relinked = [];

        foreach (array_chunk(array_values(array_unique($userIds)), 200) as $chunk) {
            $snapshots = self::snapshots(User::query()->whereIn('id', $chunk)->get());

            if (!$force) {
                $snapshots = array_values(array_filter($snapshots, fn (array $item): bool => Cache::get(self::HASH_KEY . $item['pelican_user_id']) !== self::hash($item)));
            }

            if ($snapshots === []) {
                continue;
            }

            try {
                $response = app(NoprofitClient::class)->send('POST', '/api/v1/internal/accounts/sync', ['items' => $snapshots], self::syncSecret(), 10);
            } catch (Throwable $e) {
                Log::warning('Sending account snapshots to noprofit.space failed', ['error' => $e->getMessage()]);

                continue;
            }

            if (!$response->successful()) {
                Log::warning('noprofit.space refused account snapshots', ['status' => $response->status()]);

                continue;
            }

            $unlinked = array_map('intval', (array) $response->json('unlinked', []));

            foreach ($snapshots as $item) {
                if (in_array((int) $item['account_id'], $unlinked, true)) {
                    // noprofit.space has this account linked to another panel user (or none):
                    // put the panel's link right, then send this snapshot once more.
                    if ($relink && self::reportLink((int) $item['account_id'], (int) $item['pelican_user_id'])) {
                        $relinked[] = (int) $item['pelican_user_id'];
                    }

                    continue;
                }

                Cache::put(self::HASH_KEY . $item['pelican_user_id'], self::hash($item), now()->addDay());
                $sent++;
            }
        }

        return $sent + ($relinked === [] ? 0 : self::push($relinked, true, relink: false));
    }

    /**
     * Every linked account: the changed ones, or all of them.
     */
    public static function sweep(bool $full = false): int
    {
        if (!self::enabled()) {
            return 0;
        }

        return self::push(User::query()->whereNotNull(NoprofitLink::COLUMN)->pluck('id')->map(fn ($id) => (int) $id)->all(), $full);
    }

    /**
     * Tell noprofit.space which panel user an account belongs to (null: none any more).
     */
    public static function reportLink(int $accountId, ?int $pelicanUserId): bool
    {
        $client = app(NoprofitClient::class);
        $secret = (string) config('plugged-host-core.noprofit.migration_secret', '');

        if ($accountId < 1 || !$client->configured($secret)) {
            return false;
        }

        try {
            $response = $client->send('POST', '/api/v1/internal/plugged-host/link', [
                'account_id' => $accountId,
                'pelican_user_id' => $pelicanUserId,
            ], $secret, 8);
        } catch (Throwable $e) {
            Log::warning('Telling noprofit.space about an account link failed', ['account_id' => $accountId, 'error' => $e->getMessage()]);

            return false;
        }

        if (!$response->successful()) {
            Log::warning('noprofit.space refused an account link', ['account_id' => $accountId, 'status' => $response->status()]);
        }

        return $response->successful();
    }

    /**
     * The snapshot of one user, for answers that carry it (a Discord link update).
     *
     * @return array<string, mixed>|null
     */
    public static function snapshotFor(User $user): ?array
    {
        return self::snapshots(collect([$user]))[0] ?? null;
    }

    /**
     * Mark a snapshot as known on noprofit.space (it went back in an answer).
     *
     * @param  array<string, mixed>  $item
     */
    public static function remember(array $item): void
    {
        Cache::put(self::HASH_KEY . $item['pelican_user_id'], self::hash($item), now()->addDay());
    }

    /**
     * @param  Collection<int, User>  $users
     * @return list<array<string, mixed>>
     */
    private static function snapshots(Collection $users): array
    {
        $users = $users->filter(fn (User $user): bool => NoprofitLink::accountId($user) !== null)->keyBy('id');
        if ($users->isEmpty()) {
            return [];
        }

        $usage = Server::query()
            ->whereIn('owner_id', $users->keys())
            ->toBase()
            ->groupBy('owner_id')
            ->selectRaw('owner_id, COUNT(*) AS servers, COALESCE(SUM(cpu), 0) AS cpu, COALESCE(SUM(memory), 0) AS memory, COALESCE(SUM(disk), 0) AS disk')
            ->get()
            ->keyBy('owner_id');

        $discord = Plugins::available(DiscordLink::class)
            ? DiscordLink::query()->whereIn('user_id', $users->keys())->whereNotNull('discord_id')->get()->keyBy('user_id')
            : collect();

        return $users->map(function (User $user) use ($usage, $discord): array {
            $used = $usage->get($user->id);
            $link = $discord->get($user->id);

            return [
                'account_id' => NoprofitLink::accountId($user),
                'pelican_user_id' => (int) $user->id,
                'usage' => [
                    'cpu' => (int) ($used->cpu ?? 0),
                    'memory' => (int) ($used->memory ?? 0),
                    'disk' => (int) ($used->disk ?? 0),
                    'servers' => (int) ($used->servers ?? 0),
                ],
                'discord' => $link ? [
                    'discord_id' => (string) $link->discord_id,
                    'username' => $link->discord_username,
                    'in_guild' => (bool) $link->is_in_guild,
                    'invites' => (int) $link->invite_count,
                    'invite_debt' => (int) $link->invite_debt,
                    'boost_count' => (int) $link->boost_count,
                    'boosts_rewarded' => (int) $link->boosts_rewarded,
                    'is_boosting' => (bool) $link->is_boosting,
                ] : null,
            ];
        })->values()->all();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function hash(array $item): string
    {
        return sha1((string) json_encode($item));
    }

    private static function syncSecret(): string
    {
        return (string) config('plugged-host-core.noprofit.sync_secret', '');
    }
}
