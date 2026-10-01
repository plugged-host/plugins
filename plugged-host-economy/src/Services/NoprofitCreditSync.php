<?php

namespace PluggedHost\Economy\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PluggedHost\Core\Services\AccountSummary;
use PluggedHost\Core\Services\NoprofitClient;
use PluggedHost\Core\Support\NoprofitLink;
use PluggedHost\Economy\Models\CreditTransaction;
use PluggedHost\Economy\Models\NoprofitCreditOutbox;
use PluggedHost\Economy\Models\UserCredits;
use PluggedHost\Servers\Support\AccountAllowance;
use RuntimeException;
use Throwable;

/**
 * Keeps a linked account's credits, bought resources and compute plan the same on the panel
 * and on noprofit.space.
 *
 * noprofit.space is the authority: it holds the balance and decides the plan (base tier, caps,
 * the limits the panel enforces, see AccountAllowance::forUser) and the store prices. The
 * panel keeps a copy it can change too:
 *
 *  - Panel -> noprofit.space: every change to user_credits.credits or extra_* (store, rewards,
 *    admin, recovery, the reset command) is caught by the model events and written to
 *    noprofit_credit_outbox in the same transaction, then sent as a delta with its own
 *    idempotency key right after the request (or the transaction, in a job or command), not
 *    through the queue, so a long job there can never hold it up. A retry never applies a
 *    change twice, and changes made at once on both sides both survive. A change
 *    noprofit.space refuses is undone here: spent credits come back, bought resources go.
 *  - noprofit.space -> panel: noprofit.space pushes each account's state after every change of
 *    its own, and noprofit:credits-sync pulls whatever changed once a minute in case a push got
 *    lost. Balance and resources are taken on only when the version is newer and the panel has
 *    no unconfirmed changes (their confirmation carries the newest state); the plan and prices
 *    are only ever set there, so they are always taken on.
 *
 * Writes that apply noprofit.space's state go through the normal ledger methods with
 * self::$applying set, so they are recorded but not sent back.
 */
final class NoprofitCreditSync
{
    public const TYPE = 'noprofit_sync';

    public const UNDO_TYPE = 'noprofit_undo';

    private const CURSOR_KEY = 'plugged-host-economy:noprofit-credits-cursor';

    private const PRICING_KEY = 'plugged-host-economy:noprofit-pricing';

    private const TIERS_KEY = 'plugged-host-economy:noprofit-tiers';

    private const TERMS_FETCH_KEY = 'plugged-host-economy:noprofit-terms-fetch';

    private const RESOURCES = ['cpu', 'memory', 'disk'];

    private static bool $applying = false;

    /**
     * Outbox rows written during this request, per user, so the resources of a purchase join
     * the credit change that paid for them.
     *
     * @var array<int, int>
     */
    private static array $openRows = [];

    /** @var array<int, true> */
    private static array $scheduled = [];

    public static function enabled(): bool
    {
        return class_exists(NoprofitClient::class)
            && class_exists(NoprofitLink::class)
            && app(NoprofitClient::class)->configured(self::secret());
    }

    /**
     * UserCredits created or updated: queue the change for noprofit.space. On `updated` the
     * original still holds the values before this write; the model syncs it only after the
     * event.
     */
    public static function recordLocalChange(UserCredits $credits, bool $created = false): void
    {
        if (self::$applying) {
            return;
        }

        $delta = self::change($credits, 'credits', $created);
        $extras = [];
        foreach (self::RESOURCES as $resource) {
            $extras[$resource] = self::change($credits, "extra_{$resource}", $created);
        }

        if (($delta === 0 && array_filter($extras) === []) || !self::enabled()) {
            return;
        }

        $userId = (int) $credits->user_id;
        if (NoprofitLink::accountId(User::query()->find($userId)) === null) {
            return;
        }

        $open = isset(self::$openRows[$userId]) ? NoprofitCreditOutbox::query()->find(self::$openRows[$userId]) : null;

        // Resources bought (or taken back) with a credit change made in this same request
        // travel in that change's row.
        if ($open !== null && $delta === 0) {
            $open->forceFill([
                'extra_cpu' => $open->extra_cpu + $extras['cpu'],
                'extra_memory' => $open->extra_memory + $extras['memory'],
                'extra_disk' => $open->extra_disk + $extras['disk'],
            ])->save();
        } else {
            self::$openRows[$userId] = (int) NoprofitCreditOutbox::query()->create([
                'user_id' => $userId,
                'delta' => $delta,
                'extra_cpu' => $extras['cpu'],
                'extra_memory' => $extras['memory'],
                'extra_disk' => $extras['disk'],
            ])->getKey();
        }

        self::schedulePush($userId);
    }

    /**
     * CreditTransaction created: name the matching outbox row after the ledger entry, so the
     * noprofit.space history says "Discord invite reward" instead of a bare number.
     */
    public static function describeLocalChange(CreditTransaction $transaction): void
    {
        if (self::$applying) {
            return;
        }

        NoprofitCreditOutbox::query()
            ->where('user_id', $transaction->user_id)
            ->where('delta', (int) $transaction->amount)
            ->whereNull('type')
            ->orderByDesc('id')
            ->limit(1)
            ->update([
                'type' => mb_substr((string) $transaction->type, 0, 64),
                'description' => mb_substr((string) $transaction->description, 0, 255),
            ]);
    }

    /**
     * Send this user's changes once the transaction that made them is committed: after the
     * response in a web request, right away in a job or a command. Never through the queue.
     */
    public static function schedulePush(int $userId): void
    {
        if (isset(self::$scheduled[$userId])) {
            return;
        }

        self::$scheduled[$userId] = true;

        DB::afterCommit(function () use ($userId): void {
            $send = function () use ($userId): void {
                unset(self::$scheduled[$userId], self::$openRows[$userId]);

                try {
                    self::push($userId);
                } catch (Throwable $e) {
                    Log::warning('Sending credit changes to noprofit.space failed; noprofit:credits-sync retries', ['user_id' => $userId, 'error' => $e->getMessage()]);
                }
            };

            app()->runningInConsole() ? $send() : app()->terminating($send);
        });
    }

    /**
     * Send this user's unconfirmed changes, oldest first, then take on the state noprofit.space
     * answers with.
     *
     * @throws RuntimeException when noprofit.space could not be reached (retried every minute)
     */
    public static function push(int $userId): void
    {
        $lock = Cache::lock('plugged-host-economy:noprofit-credits-push:' . $userId, 60);
        if (!$lock->block(10)) {
            throw new RuntimeException('Another push for this account is still running.');
        }

        try {
            if (!self::enabled()) {
                return;
            }

            $user = User::query()->find($userId);
            $accountId = NoprofitLink::accountId($user);

            // Unlinked since the change was made: there is nothing left to keep in step.
            if ($user === null || $accountId === null) {
                NoprofitCreditOutbox::query()->where('user_id', $userId)->delete();

                return;
            }

            $last = null;

            foreach (NoprofitCreditOutbox::query()->where('user_id', $userId)->orderBy('id')->get() as $row) {
                $response = self::client()->send('POST', '/api/v1/internal/credits/apply', [
                    'account_id' => $accountId,
                    'pelican_user_id' => $userId,
                    'delta' => $row->delta,
                    'extra_cpu' => $row->extra_cpu,
                    'extra_memory' => $row->extra_memory,
                    'extra_disk' => $row->extra_disk,
                    'type' => $row->type ?? 'plugged_host',
                    'description' => $row->description ?? 'Changed on plugged.host',
                    'idempotency_key' => self::idempotencyKey($row),
                ], self::secret(), 8);

                // Refused for good (not linked there, invalid, or the key is taken by another
                // change): undo it here rather than let the two copies drift apart.
                if (in_array($response->status(), [404, 409, 422], true)) {
                    Log::warning('noprofit.space refused a credit change; undoing it on the panel', ['user_id' => $userId, 'outbox_id' => $row->id, 'status' => $response->status()]);
                    self::undo($user, $row);
                    $row->delete();

                    continue;
                }

                if (!$response->successful()) {
                    $row->forceFill(['attempts' => $row->attempts + 1, 'last_error' => 'HTTP ' . $response->status()])->save();

                    throw new RuntimeException("noprofit.space answered HTTP {$response->status()} for a credit change.");
                }

                $row->delete();
                $last = $response->json();
            }

            if (is_array($last)) {
                self::adopt($user, $last);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * The key noprofit.space applies a change once under. Outbox ids alone repeat: the dev
     * panel used the same ones before 2.1 went live, and noprofit.space took prod's changes
     * #1-#22 for repeats and dropped them. The panel's URL and the row's creation time make
     * the key this panel's and this row's alone.
     */
    private static function idempotencyKey(NoprofitCreditOutbox $row): string
    {
        $panel = substr(hash('sha256', (string) config('app.url')), 0, 12);

        return "plugged-host:{$panel}:{$row->id}:{$row->created_at->getTimestamp()}";
    }

    /**
     * Take back a change noprofit.space refused: the credits it spent come back, the credits it
     * gave and the resources it bought go again.
     */
    private static function undo(User $user, NoprofitCreditOutbox $row): void
    {
        DB::transaction(function () use ($user, $row): void {
            $credits = UserCredits::query()->where('user_id', $user->getKey())->lockForUpdate()->first();
            if ($credits === null) {
                return;
            }

            $description = mb_substr('Undone, noprofit.space did not accept it: ' . ($row->description ?? 'a change'), 0, 255);
            $metadata = ['outbox_id' => $row->id, 'type' => $row->type];

            self::$applying = true;

            try {
                if ($row->delta < 0) {
                    $credits->addCredits(-$row->delta, self::UNDO_TYPE, $description, $metadata);
                } elseif ($row->delta > 0) {
                    $credits->deductCreditsUpTo($row->delta, self::UNDO_TYPE, $description, $metadata);
                }

                $credits->forceFill([
                    'extra_cpu' => max(0, (int) $credits->extra_cpu - (int) $row->extra_cpu),
                    'extra_memory' => max(0, (int) $credits->extra_memory - (int) $row->extra_memory),
                    'extra_disk' => max(0, (int) $credits->extra_disk - (int) $row->extra_disk),
                ])->saveQuietly();
            } finally {
                self::$applying = false;
            }
        });

        self::forget($user);
    }

    /**
     * Take on noprofit.space's state for one account: the plan always, the balance and bought
     * resources when they are newer than this copy and the panel has no unconfirmed changes.
     *
     * @param  array<string, mixed>  $state  {balance, version, extra_cpu, extra_memory, extra_disk, plan}
     */
    public static function adopt(User $user, array $state): bool
    {
        if (!isset($state['balance'], $state['version'])) {
            return false;
        }

        $version = (int) $state['version'];
        $balance = max(0, (int) $state['balance']);

        $adopted = DB::transaction(function () use ($user, $state, $version, $balance): bool {
            $credits = UserCredits::forUser($user);
            if ($credits === null) {
                return false;
            }

            $credits = UserCredits::query()->whereKey($credits->getKey())->lockForUpdate()->first();
            if ($credits === null) {
                return false;
            }

            self::$applying = true;

            try {
                if (is_array($state['plan'] ?? null) && $credits->noprofit_plan !== $state['plan']) {
                    $credits->forceFill(['noprofit_plan' => $state['plan']])->saveQuietly();
                }

                if (($credits->noprofit_version !== null && $version <= (int) $credits->noprofit_version)
                    || NoprofitCreditOutbox::query()->where('user_id', $user->getKey())->exists()) {
                    return true;
                }

                // First sync of an account whose noprofit.space state never changed (version 0):
                // until now the panel's balance and resources were the live ones, so they go up
                // instead of being overwritten. The confirmation then sets the version.
                if ($version === 0 && $credits->noprofit_version === null) {
                    $row = [
                        'delta' => (int) $credits->credits - $balance,
                        'extra_cpu' => (int) $credits->extra_cpu - (int) ($state['extra_cpu'] ?? 0),
                        'extra_memory' => (int) $credits->extra_memory - (int) ($state['extra_memory'] ?? 0),
                        'extra_disk' => (int) $credits->extra_disk - (int) ($state['extra_disk'] ?? 0),
                    ];

                    if (array_filter($row) !== []) {
                        NoprofitCreditOutbox::query()->create(['user_id' => $user->getKey(), 'type' => 'initial_sync', 'description' => 'Carried over from plugged.host'] + $row);
                        self::schedulePush((int) $user->getKey());

                        return true;
                    }
                }

                $difference = $balance - (int) $credits->credits;
                $metadata = ['noprofit_version' => $version];

                if ($difference > 0) {
                    $credits->addCredits($difference, self::TYPE, 'Balance synced from noprofit.space', $metadata);
                } elseif ($difference < 0) {
                    $credits->deductCreditsUpTo(-$difference, self::TYPE, 'Balance synced from noprofit.space', $metadata);
                }

                $credits->forceFill([
                    'noprofit_version' => $version,
                    'extra_cpu' => max(0, (int) ($state['extra_cpu'] ?? $credits->extra_cpu)),
                    'extra_memory' => max(0, (int) ($state['extra_memory'] ?? $credits->extra_memory)),
                    'extra_disk' => max(0, (int) ($state['extra_disk'] ?? $credits->extra_disk)),
                ])->saveQuietly();
            } finally {
                self::$applying = false;
            }

            return true;
        });

        if ($adopted) {
            self::forget($user);
        }

        return $adopted;
    }

    /**
     * Balances noprofit.space pushed or returned from a pull, with the store prices and the
     * compute tiers that came along.
     *
     * @param  array<int, mixed>  $items  [{account_id, balance, version, extra_*, plan}, ...]
     * @param  array<string, int>|null  $pricing
     * @param  array<string, array<string, int>>|null  $tiers
     */
    public static function applyBalances(array $items, ?array $pricing = null, ?array $tiers = null): int
    {
        self::rememberTerms($pricing, $tiers);

        $applied = 0;

        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['account_id'])) {
                continue;
            }

            $user = NoprofitLink::userFor((int) $item['account_id']);

            if ($user !== null && self::adopt($user, $item)) {
                $applied++;
            }
        }

        return $applied;
    }

    /**
     * The plan noprofit.space set for this user (limits, base, caps), or null when there is
     * none (not linked, or not synced yet): then the account has no allowance.
     *
     * @return array<string, int>|null
     */
    public static function plan(?User $user): ?array
    {
        if ($user === null || !class_exists(NoprofitLink::class) || NoprofitLink::accountId($user) === null) {
            return null;
        }

        $plan = UserCredits::forUser($user)?->noprofit_plan;

        return is_array($plan) && isset($plan['cpu'], $plan['memory'], $plan['disk']) ? array_map('intval', $plan) : null;
    }

    /**
     * Store prices set on noprofit.space ({cpu,memory,storage}_unit / _price_credits), or null
     * while none are known: then the store does not sell CPU, memory or disk.
     *
     * @return array<string, int>|null
     */
    public static function pricing(): ?array
    {
        return self::terms(self::PRICING_KEY);
    }

    /**
     * The compute tiers set on noprofit.space: {default: {cpu, memory, disk}, discord: {...}},
     * the base an account gets without and with the Discord server. Null while none are known.
     *
     * @return array{default: array{cpu: int, memory: int, disk: int}, discord: array{cpu: int, memory: int, disk: int}}|null
     */
    public static function tiers(): ?array
    {
        return self::terms(self::TIERS_KEY);
    }

    /**
     * What noprofit.space last sent. After a cache flush (optimize:clear) it is asked for right
     * away, at most every half minute, rather than waiting for the next sync to bring it.
     *
     * @return array<string, mixed>|null
     */
    private static function terms(string $key): ?array
    {
        $value = Cache::get($key);

        if ((!is_array($value) || $value === []) && self::enabled() && Cache::add(self::TERMS_FETCH_KEY, 1, 30)) {
            try {
                $response = self::client()->send('POST', '/api/v1/internal/credits/balances', ['account_ids' => []], self::secret(), 5);

                if ($response->successful()) {
                    self::rememberTerms($response->json('pricing'), $response->json('tiers'));
                    $value = Cache::get($key);
                }
            } catch (Throwable $e) {
                Log::warning('Fetching store prices and tiers from noprofit.space failed', ['error' => $e->getMessage()]);
            }
        }

        return is_array($value) && $value !== [] ? $value : null;
    }

    private static function rememberTerms(mixed $pricing, mixed $tiers): void
    {
        if (is_array($pricing) && $pricing !== []) {
            Cache::forever(self::PRICING_KEY, array_map('intval', $pricing));
        }

        if (!is_array($tiers)) {
            return;
        }

        $clean = [];
        foreach (['default', 'discord'] as $tier) {
            if (!isset($tiers[$tier]['cpu'], $tiers[$tier]['memory'], $tiers[$tier]['disk'])) {
                return;
            }

            $clean[$tier] = [
                'cpu' => (int) $tiers[$tier]['cpu'],
                'memory' => (int) $tiers[$tier]['memory'],
                'disk' => (int) $tiers[$tier]['disk'],
            ];
        }

        Cache::forever(self::TIERS_KEY, $clean);
    }

    /**
     * Everything that changed on noprofit.space since the last pull.
     */
    public static function pullChanges(bool $full = false): int
    {
        $cursor = $full ? 0 : (int) Cache::get(self::CURSOR_KEY, 0);
        $applied = 0;

        for ($page = 0; $page < 50; $page++) {
            $response = self::client()->send('POST', '/api/v1/internal/credits/balances', ['since' => $cursor], self::secret(), 15);
            if (!$response->successful()) {
                throw new RuntimeException("noprofit.space answered HTTP {$response->status()} for the balance list.");
            }

            $applied += self::applyBalances((array) $response->json('items', []), $response->json('pricing'), $response->json('tiers'));
            $cursor = max($cursor, (int) $response->json('cursor', $cursor));
            Cache::forever(self::CURSOR_KEY, $cursor);

            if (!$response->json('more')) {
                break;
            }
        }

        return $applied;
    }

    /**
     * Linked accounts this copy has never synced (noprofit_version still null: linked since
     * the last pull, or before the sync existed): ask for their state directly.
     */
    public static function pullNeverSynced(): int
    {
        return self::pullAccounts(
            UserCredits::query()
                ->join('users', 'users.id', '=', 'user_credits.user_id')
                ->whereNull('user_credits.noprofit_version')
                ->whereNotNull('users.' . NoprofitLink::COLUMN)
                ->pluck('users.' . NoprofitLink::COLUMN)
                ->all(),
        );
    }

    /**
     * @param  array<int, int|string>  $accountIds
     */
    public static function pullAccounts(array $accountIds): int
    {
        $applied = 0;

        foreach (array_chunk(array_map('intval', $accountIds), 200) as $chunk) {
            $response = self::client()->send('POST', '/api/v1/internal/credits/balances', ['account_ids' => $chunk], self::secret(), 15);

            if ($response->successful()) {
                $applied += self::applyBalances((array) $response->json('items', []), $response->json('pricing'), $response->json('tiers'));
            }
        }

        return $applied;
    }

    /**
     * Re-send changes that did not go through (noprofit.space was unreachable).
     */
    public static function retryStuck(): int
    {
        $users = NoprofitCreditOutbox::query()
            ->where('created_at', '<', now()->subSeconds(30))
            ->distinct()
            ->pluck('user_id');

        foreach ($users as $userId) {
            try {
                self::push((int) $userId);
            } catch (Throwable $e) {
                Log::warning('noprofit.space credit sync retry failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            }
        }

        return $users->count();
    }

    private static function change(UserCredits $credits, string $column, bool $created): int
    {
        if ($created) {
            return (int) $credits->{$column};
        }

        return $credits->wasChanged($column) ? (int) $credits->{$column} - (int) $credits->getOriginal($column) : 0;
    }

    private static function forget(User $user): void
    {
        UserCredits::forgetMemo($user);

        if (class_exists(AccountSummary::class)) {
            app(AccountSummary::class)->forget($user);
        }

        if (class_exists(AccountAllowance::class)) {
            AccountAllowance::forgetMemo($user);
        }
    }

    private static function client(): NoprofitClient
    {
        return app(NoprofitClient::class);
    }

    private static function secret(): string
    {
        return (string) config('plugged-host-core.noprofit.sync_secret', '');
    }
}
