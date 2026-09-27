<?php

namespace PluggedHost\Economy\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PluggedHost\Core\Services\AccountSummary;
use PluggedHost\Core\Services\NoprofitClient;
use PluggedHost\Core\Support\NoprofitLink;
use PluggedHost\Economy\Jobs\PushNoprofitCreditsJob;
use PluggedHost\Economy\Models\CreditTransaction;
use PluggedHost\Economy\Models\NoprofitCreditOutbox;
use PluggedHost\Economy\Models\UserCredits;
use RuntimeException;
use Throwable;

/**
 * Keeps a linked account's credits the same on the panel and on noprofit.space.
 *
 * noprofit.space holds the authoritative balance; the panel keeps a copy it can change too.
 *
 *  - Panel -> noprofit.space: every change to user_credits.credits, whoever makes it (store,
 *    rewards, admin "set credits", the reset command), is caught by the model events and
 *    written to noprofit_credit_outbox in the same transaction. A queued job sends each row
 *    as a delta with its own idempotency key, so a retry can never apply it twice and two
 *    changes made at once on both sides both survive.
 *  - noprofit.space -> panel: noprofit.space pushes its balance and version after every change
 *    of its own, and noprofit:credits-sync pulls whatever changed once a minute in case a push
 *    got lost. The copy takes on a balance only when its version is newer, and never while
 *    the panel still has unconfirmed changes (their confirmation carries the newest balance).
 *
 * Writes that apply noprofit.space's balance go through the normal ledger methods with
 * self::$applying set, so they are recorded but not sent back.
 */
final class NoprofitCreditSync
{
    public const TYPE = 'noprofit_sync';

    private const CURSOR_KEY = 'plugged-host-economy:noprofit-credits-cursor';

    private static bool $applying = false;

    public static function enabled(): bool
    {
        return class_exists(NoprofitClient::class)
            && class_exists(NoprofitLink::class)
            && app(NoprofitClient::class)->configured(self::secret());
    }

    /**
     * UserCredits created (a starting balance) or updated: queue the change for noprofit.space.
     * On `updated` the original still holds the balance before this write; the model syncs
     * it only after the event.
     */
    public static function recordLocalChange(UserCredits $credits, bool $created = false): void
    {
        if (self::$applying || (!$created && !$credits->wasChanged('credits'))) {
            return;
        }

        $delta = $created ? (int) $credits->credits : (int) $credits->credits - (int) $credits->getOriginal('credits');
        if ($delta === 0 || !self::enabled()) {
            return;
        }

        $user = User::query()->find($credits->user_id);
        if (NoprofitLink::accountId($user) === null) {
            return;
        }

        NoprofitCreditOutbox::query()->create([
            'user_id' => $credits->user_id,
            'delta' => $delta,
        ]);

        PushNoprofitCreditsJob::dispatch((int) $credits->user_id)->afterCommit();
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
     * Send this user's unconfirmed changes, oldest first, then take on the balance
     * noprofit.space answers with.
     *
     * @throws RuntimeException when noprofit.space could not be reached (the job retries)
     */
    public static function push(int $userId): void
    {
        $lock = Cache::lock('plugged-host-economy:noprofit-credits-push:' . $userId, 60);
        if (!$lock->get()) {
            throw new RuntimeException('Another push for this account is running.');
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
                    'type' => $row->type ?? 'plugged_host',
                    'description' => $row->description ?? 'Changed on plugged.host',
                    'idempotency_key' => 'plugged-host:' . $row->id,
                ], self::secret(), 8);

                if ($response->status() === 404 || $response->status() === 422) {
                    Log::warning('noprofit.space refused a credit change', ['user_id' => $userId, 'outbox_id' => $row->id, 'status' => $response->status()]);
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

            if (is_array($last) && isset($last['balance'], $last['version'])) {
                self::adopt($user, (int) $last['balance'], (int) $last['version']);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Take on noprofit.space's balance for one account, if it is newer than what this copy
     * has and the panel has no unconfirmed changes of its own.
     */
    public static function adopt(User $user, int $balance, int $version): bool
    {
        $balance = max(0, $balance);

        $adopted = DB::transaction(function () use ($user, $balance, $version): bool {
            $credits = UserCredits::forUser($user);
            if ($credits === null) {
                return false;
            }

            $credits = UserCredits::query()->whereKey($credits->getKey())->lockForUpdate()->first();

            if ($credits === null
                || ($credits->noprofit_version !== null && $version <= (int) $credits->noprofit_version)
                || NoprofitCreditOutbox::query()->where('user_id', $user->getKey())->exists()) {
                return false;
            }

            // First sync of an account whose noprofit.space balance never changed (version 0):
            // until now the panel's balance was the live one, so it goes up instead of being
            // overwritten. The confirmation then sets the version like any other change.
            if ($version === 0 && $credits->noprofit_version === null && (int) $credits->credits !== $balance) {
                NoprofitCreditOutbox::query()->create([
                    'user_id' => $user->getKey(),
                    'delta' => (int) $credits->credits - $balance,
                    'type' => 'initial_sync',
                    'description' => 'Balance carried over from plugged.host',
                ]);

                PushNoprofitCreditsJob::dispatch((int) $user->getKey())->afterCommit();

                return false;
            }

            self::$applying = true;

            try {
                $difference = $balance - (int) $credits->credits;
                $metadata = ['noprofit_version' => $version];

                if ($difference > 0) {
                    $credits->addCredits($difference, self::TYPE, 'Balance synced from noprofit.space', $metadata);
                } elseif ($difference < 0) {
                    $credits->deductCreditsUpTo(-$difference, self::TYPE, 'Balance synced from noprofit.space', $metadata);
                }

                $credits->forceFill(['noprofit_version' => $version])->saveQuietly();
            } finally {
                self::$applying = false;
            }

            return true;
        });

        if ($adopted) {
            UserCredits::forgetMemo($user);

            if (class_exists(AccountSummary::class)) {
                app(AccountSummary::class)->forget($user);
            }
        }

        return $adopted;
    }

    /**
     * Balances noprofit.space pushed or returned from a pull.
     *
     * @param  array<int, mixed>  $items  [{account_id, balance, version}, ...]
     */
    public static function applyBalances(array $items): int
    {
        $applied = 0;

        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['account_id'], $item['balance'], $item['version'])) {
                continue;
            }

            $user = NoprofitLink::userFor((int) $item['account_id']);

            if ($user !== null && self::adopt($user, (int) $item['balance'], (int) $item['version'])) {
                $applied++;
            }
        }

        return $applied;
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

            $applied += self::applyBalances((array) $response->json('items', []));
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
     * the last pull, or before the sync existed): ask for their balances directly. The first
     * sync takes on noprofit.space's balance, like every later one.
     */
    public static function pullNeverSynced(): int
    {
        $applied = 0;

        UserCredits::query()
            ->whereNull('noprofit_version')
            ->join('users', 'users.id', '=', 'user_credits.user_id')
            ->whereNotNull('users.' . NoprofitLink::COLUMN)
            ->select('users.' . NoprofitLink::COLUMN . ' as account_id')
            ->orderBy('user_credits.id')
            ->chunk(200, function ($rows) use (&$applied): void {
                $response = self::client()->send('POST', '/api/v1/internal/credits/balances', [
                    'account_ids' => $rows->pluck('account_id')->map(fn ($id) => (int) $id)->values()->all(),
                ], self::secret(), 15);

                if ($response->successful()) {
                    $applied += self::applyBalances((array) $response->json('items', []));
                }
            });

        return $applied;
    }

    /**
     * Re-send changes whose job never finished (worker down, retries used up).
     */
    public static function retryStuck(): int
    {
        $users = NoprofitCreditOutbox::query()
            ->where('created_at', '<', now()->subMinute())
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

    private static function client(): NoprofitClient
    {
        return app(NoprofitClient::class);
    }

    private static function secret(): string
    {
        return (string) config('plugged-host-core.noprofit.sync_secret', '');
    }
}
