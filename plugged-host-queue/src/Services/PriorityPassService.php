<?php

namespace PluggedHost\Queue\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Queue\Models\StartQueueEntry;
use PluggedHost\Queue\Models\StartQueuePriority;
use Throwable;

/**
 * An account's place in the start queue.
 *
 * Priority is no longer sold here. It comes with plugged.host Plus (level 1) and PlusPlus
 * (level 2, ahead of Plus), which plugged-host-economy sells and keeps (MembershipService,
 * reached by class name so the queue still works without economy: nobody has priority).
 *
 * The priority windows this class used to sell (start_queue_priorities) were closed and
 * refunded when Plus replaced them (refundOpenWindows, `queue:refund-priority-passes`); the
 * rows stay as the record of what was bought.
 *
 * Registered `scoped` by `QueuePluginProvider`.
 */
class PriorityPassService
{
    /** No priority. */
    public const LEVEL_NONE = 0;

    /** plugged.host Plus. */
    public const LEVEL_PLUS = 1;

    /** plugged.host PlusPlus: ahead of Plus. */
    public const LEVEL_PLUSPLUS = 2;

    protected const MEMBERSHIP = 'PluggedHost\\Economy\\Services\\MembershipService';

    protected const TOKENS = 'PluggedHost\\Economy\\Services\\TokenService';

    /** Is there a way to get priority at all (Plus is on sale)? */
    public function available(): bool
    {
        return (bool) $this->membership()?->enabled();
    }

    public function level(?User $user): int
    {
        if (!$user instanceof User) {
            return self::LEVEL_NONE;
        }

        try {
            return (int) ($this->membership()?->priorityLevel($user) ?? self::LEVEL_NONE);
        } catch (Throwable $e) {
            $this->log('priority level could not be read', $e);

            return self::LEVEL_NONE;
        }
    }

    /** Plus or PlusPlus. */
    public function isActive(?User $user): bool
    {
        return $this->level($user) >= self::LEVEL_PLUS;
    }

    /**
     * Raise this user's waiting entries to their level now rather than on their next start.
     * `queued_at` is left alone: priority buys a place above lower levels, not above people
     * at the same level who arrived first.
     */
    public function stampWaiting(User $user): void
    {
        $level = $this->level($user);

        if ($level <= self::LEVEL_NONE) {
            return;
        }

        try {
            StartQueueEntry::query()
                ->waiting()
                ->where('user_id', $user->getKey())
                ->where('priority', '<', $level)
                ->update(['priority' => $level]);
        } catch (Throwable $e) {
            $this->log('priority re-stamp of waiting entries failed', $e);
        }
    }

    /**
     * Close every priority window that was still running when Plus replaced them, and give back
     * the tokens of each purchase whose time had not run out. Purchases stacked end to end, so
     * walking back from the window's end by each purchase's days finds the ones still running.
     * Run once by `queue:refund-priority-passes`; a second run finds nothing open.
     *
     * @return list<array{user_id: int, tokens: int, purchases: int}>
     */
    public function refundOpenWindows(bool $dryRun = false): array
    {
        $now = now();
        $refunds = [];

        $windows = StartQueuePriority::query()
            ->where(fn ($query) => $query->where('expires_at', '>', $now)->orWhere('ad_free_until', '>', $now))
            ->get();

        foreach ($windows as $window) {
            $end = collect([$window->expires_at, $window->ad_free_until])->filter()->max();
            $purchases = DB::table('token_transactions')
                ->where('user_id', $window->user_id)
                ->where('type', 'queue:priority')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get(['amount', 'metadata']);

            $tokens = 0;
            $count = 0;
            $cursor = Carbon::parse($end);

            foreach ($purchases as $purchase) {
                if ($cursor->lte($now)) {
                    break;
                }

                $days = (int) (json_decode((string) $purchase->metadata, true)['days'] ?? 0);
                $tokens += abs((int) $purchase->amount);
                $count++;
                $cursor = $cursor->copy()->subDays(max(1, $days));
            }

            $refunds[] = ['user_id' => (int) $window->user_id, 'tokens' => $tokens, 'purchases' => $count];

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($window, $now, $tokens): void {
                $window->expires_at = $now;
                if ($window->ad_free_until !== null && $window->ad_free_until->gt($now)) {
                    $window->ad_free_until = $now;
                }
                $window->save();

                $user = User::query()->find($window->user_id);
                $ledger = Plugins::available(self::TOKENS) ? app(self::TOKENS) : null;

                if ($user !== null && $ledger !== null && $tokens > 0) {
                    $ledger->add(
                        $user,
                        $tokens,
                        'queue:priority-refund',
                        trans('plugged-host-queue::strings.tokens_tx_priority_replaced'),
                        ['refund' => true, 'reason' => 'replaced_by_plus'],
                    );
                }
            });
        }

        return $refunds;
    }

    /** plugged-host-economy's MembershipService, or null when it is not installed. */
    protected function membership(): mixed
    {
        if (!Plugins::available(self::MEMBERSHIP)) {
            return null;
        }

        try {
            return app(self::MEMBERSHIP);
        } catch (Throwable) {
            return null;
        }
    }

    protected function log(string $message, Throwable $e): void
    {
        Log::warning("[plugged-host-queue] {$message}", ['error' => $e->getMessage()]);
    }
}
