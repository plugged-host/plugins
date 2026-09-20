<?php

namespace PluggedHost\Queue\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PluggedHost\Queue\Models\StartQueueEntry;
use PluggedHost\Queue\Models\StartQueuePriority;
use Throwable;

/**
 * Selling, extending and answering questions about the priority window.
 *
 * Separate from `StartQueueService` because the two have nothing in common but the
 * `priority` column: one runs on every power action for every user, the other runs
 * when somebody spends tokens. Keeping the money in its own class is also what makes
 * the refund path below easy to see.
 *
 * Two tiers are sold and this class is the only thing that knows the difference:
 *
 *   standard — the cheap one. The longer window; the panel keeps its advertising.
 *   ad_free  — dearer and shorter. Priority *and* a window with no advertising in it,
 *              which is a second date on the same row rather than a second product.
 *
 * plugged-host-economy holds the tokens and is resolved by string, so an uninstalled
 * economy means the offer is simply not made — `available()` is false, every page hides
 * the buttons, and the queue still works, just without a way to skip it.
 *
 * plugged-host-ads asks this class whether an account is inside its quiet window, from
 * the other side and also by string. Neither plugin imports the other.
 *
 * Registered `scoped` by `QueuePluginProvider`, which is what makes the memo below
 * safe: one instance per request and per queue job, thrown away between them.
 */
class PriorityPassService
{
    public const RESULT_OK = 'ok';

    /** No token ledger, or selling is switched off. The button should not have shown. */
    public const RESULT_UNAVAILABLE = 'unavailable';

    /** Not enough tokens — the ordinary, expected refusal. */
    public const RESULT_SHORT = 'short';

    /** Paid but not granted, or could not pay for a reason that is not the balance. */
    public const RESULT_FAILED = 'failed';

    /** The cheap tier: longer window, advertising stays. */
    public const TIER_STANDARD = 'standard';

    /** The dear tier: shorter window, no advertising anywhere in the panel. */
    public const TIER_AD_FREE = 'ad_free';

    protected const TOKENS = 'PluggedHost\\Economy\\Services\\TokenService';

    /**
     * Windows already read, keyed by user id.
     *
     * `AdDisplayService::isExemptUser()` sits on the hottest path in the panel — every
     * render, twice, for every user — and now asks this class a question that costs a
     * query. The model is memoised rather than the answer, so `isActive()` and
     * `isAdFree()` stay honest: both re-compare against `now()`.
     *
     * @var array<int, StartQueuePriority|null>
     */
    protected array $windows = [];

    /** Can a pass be bought at all right now? */
    public function available(): bool
    {
        $tokens = $this->tokens();

        if ($tokens === null) {
            return false;
        }

        try {
            return (bool) $tokens->isEnabled();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * What is on offer, cheapest first.
     *
     * The one shape every surface renders from — the two cards in the store, the two
     * buttons on the waiting page — so the prices cannot drift apart.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tiers(): array
    {
        $tiers = [$this->describe(self::TIER_STANDARD)];

        if ($this->adFreeOffered()) {
            $tiers[] = $this->describe(self::TIER_AD_FREE);
        }

        return $tiers;
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(?string $tier): array
    {
        $tier = $this->normaliseTier($tier);

        return [
            'key' => $tier,
            'days' => $this->days($tier),
            'cost' => $this->cost($tier),
            'ad_free' => $tier === self::TIER_AD_FREE,
        ];
    }

    /**
     * Is the second tier being sold?
     *
     * Off, only the cheap one is offered — but windows already bought run to their end.
     * Withdrawing an offer must not repossess something somebody paid for.
     */
    public function adFreeOffered(): bool
    {
        return (bool) config('plugged-host-queue.priority.ad_free.enabled', true);
    }

    /**
     * Unknown, misspelled, null, and "the tier we stopped selling" all mean the cheap
     * one. This runs on the way in from a Livewire action, so it is also the validation:
     * a hand-crafted `buyPriority('free')` buys the standard tier at the standard price.
     */
    public function normaliseTier(?string $tier): string
    {
        return $tier === self::TIER_AD_FREE && $this->adFreeOffered()
            ? self::TIER_AD_FREE
            : self::TIER_STANDARD;
    }

    public function isAdFreeTier(?string $tier): bool
    {
        return $this->normaliseTier($tier) === self::TIER_AD_FREE;
    }

    /**
     * Null means the cheap tier, which is what keeps every existing caller — and
     * plugged-host-ads' earn page, which reads the same two config keys by name —
     * quoting the price it always quoted.
     */
    public function cost(?string $tier = null): int
    {
        return $this->isAdFreeTier($tier)
            ? max(1, (int) config('plugged-host-queue.priority.ad_free.token_cost', 35))
            : max(1, (int) config('plugged-host-queue.priority.token_cost', 15));
    }

    public function days(?string $tier = null): int
    {
        return $this->isAdFreeTier($tier)
            ? max(1, (int) config('plugged-host-queue.priority.ad_free.days', 2))
            : max(1, (int) config('plugged-host-queue.priority.days', 3));
    }

    public function balance(?User $user): int
    {
        $tokens = $this->tokens();

        if ($tokens === null || !$user instanceof User) {
            return 0;
        }

        try {
            return (int) $tokens->balance($user);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * This account's window row, whether or not it has run out.
     *
     * Returns null rather than throwing when the table is not there yet: the queue's
     * migration and the code that reads it land in the same deploy, but a page that
     * 500s between the two is a worse answer than "no pass".
     *
     * Memoised per instance, including the null, because the ad-exemption check calls
     * this on every render. `forget()` is what a purchase uses to make the new window
     * visible in the same request.
     */
    public function window(?User $user): ?StartQueuePriority
    {
        if (!$user instanceof User) {
            return null;
        }

        $id = (int) $user->getKey();

        if (array_key_exists($id, $this->windows)) {
            return $this->windows[$id];
        }

        try {
            $cached = Cache::remember(
                "queue:user:{$id}:priority_window",
                60,
                function () use ($id) {
                    $record = StartQueuePriority::query()
                        ->where('user_id', $id)
                        ->first();

                    return $record ? $record->toArray() : ['_none' => true];
                }
            );

            if (!is_array($cached) || isset($cached['_none'])) {
                return $this->windows[$id] = null;
            }

            $model = new StartQueuePriority();
            $model->setRawAttributes($cached, true);
            $model->exists = true;

            return $this->windows[$id] = $model;
        } catch (Throwable) {
            // Deliberately not memoised: a missing table during a deploy is a state
            // that resolves, and caching "no pass" through it would outlast the cause.
            return null;
        }
    }

    public function forget(?User $user): void
    {
        if ($user instanceof User) {
            $id = (int) $user->getKey();
            unset($this->windows[$id]);
            try {
                Cache::forget("queue:user:{$id}:priority_window");
            } catch (Throwable) {
            }
        }
    }

    public function isActive(?User $user): bool
    {
        return (bool) $this->window($user)?->isActive();
    }

    public function remainingSeconds(?User $user): int
    {
        return (int) ($this->window($user)?->remainingSeconds() ?? 0);
    }

    /**
     * Is this account inside a window it paid to have no advertising in?
     *
     * What plugged-host-ads calls, through the exemption hook `QueuePluginProvider`
     * registers with it. Answering true here removes every ad surface in the panel for
     * this user — banners, social bar, popunder, the start gate and the ad-block
     * detector — because all of them hang off one method over there.
     */
    public function isAdFree(?User $user): bool
    {
        return (bool) $this->window($user)?->isAdFree();
    }

    public function adFreeRemainingSeconds(?User $user): int
    {
        return (int) ($this->window($user)?->adFreeRemainingSeconds() ?? 0);
    }

    /**
     * Buy or extend a window.
     *
     * Tokens come out first and the window goes in second, which is the wrong order
     * for a bank and the right one here: `spend()` re-reads the balance under a lock,
     * so it is the only step that can fail for a reason the user caused. If the grant
     * then fails the tokens go straight back — a pass that was paid for and not given
     * is the one outcome worth writing extra code to avoid.
     */
    public function purchase(?User $user, ?string $tier = null): string
    {
        if (!$user instanceof User || !$this->available()) {
            return self::RESULT_UNAVAILABLE;
        }

        $tier = $this->normaliseTier($tier);
        $cost = $this->cost($tier);

        if ($this->balance($user) < $cost) {
            return self::RESULT_SHORT;
        }

        $tokens = $this->tokens();
        $days = $this->days($tier);
        $adFree = $this->isAdFreeTier($tier);

        try {
            $paid = (bool) $tokens->spend(
                $user,
                $cost,
                'queue:priority',
                trans(
                    $adFree
                        ? 'plugged-host-queue::strings.tokens_tx_priority_ad_free'
                        : 'plugged-host-queue::strings.tokens_tx_priority',
                    ['days' => $days],
                ),
                ['days' => $days, 'tier' => $tier],
            );
        } catch (Throwable $e) {
            $this->log('priority purchase could not charge', $e);

            return self::RESULT_FAILED;
        }

        if (!$paid) {
            // Either the locked re-read found the balance short after all, or the
            // ledger is unreachable. Nothing was taken either way.
            return self::RESULT_SHORT;
        }

        try {
            $this->grant($user, $tier);
        } catch (Throwable $e) {
            $this->log('priority granted failed after charge, refunding', $e);
            $this->refund($user, $cost);

            return self::RESULT_FAILED;
        }

        // Anyone already standing in a line moves up now rather than on their next
        // start. Deliberately after the grant and outside it: a pass that is live but
        // has not re-stamped an entry yet costs one poll of position, and a re-stamp
        // without a live pass would be a free skip.
        $this->stampWaiting($user);

        return self::RESULT_OK;
    }

    /**
     * Open the window, or push an open one further out.
     *
     * The unique index on `user_id` is the arbiter, not this read: two purchases
     * landing together both find no row, both insert, and the loser gets a constraint
     * violation which is retried as the extension it always was.
     */
    protected function grant(User $user, string $tier): StartQueuePriority
    {
        try {
            $window = $this->write($user, $tier);
        } catch (QueryException) {
            $window = $this->write($user, $tier);
        }

        // The memo was filled before the purchase and would otherwise keep answering
        // "no pass" for the rest of the request that just sold one.
        $this->forget($user);

        return $window;
    }

    protected function write(User $user, string $tier): StartQueuePriority
    {
        $days = $this->days($tier);
        $cost = $this->cost($tier);
        $adFree = $this->isAdFreeTier($tier);

        return DB::transaction(function () use ($user, $days, $cost, $adFree): StartQueuePriority {
            $window = StartQueuePriority::query()
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if (!$window instanceof StartQueuePriority) {
                $window = new StartQueuePriority();
                $window->user_id = $user->getKey();
                $window->expires_at = now()->addDays($days);
                $window->ad_free_until = $adFree ? now()->addDays($days) : null;
                $window->tokens_spent = $cost;
                $window->purchases = 1;
                $window->ad_free_purchases = $adFree ? 1 : 0;
                $window->save();

                return $window;
            }

            // Added to the end of what is left, never to now — buying a second window
            // on day one of the first must not throw the remaining two days away.
            $window->expires_at = $this->extend($window->expires_at, $days);

            // The quiet window is extended only by the tier that sells it, and from its
            // own end date. Buying standard during an ad-free window therefore buys more
            // priority and not more quiet, which is exactly what was paid for.
            if ($adFree) {
                $window->ad_free_until = $this->extend($window->ad_free_until, $days);
                $window->ad_free_purchases = (int) $window->ad_free_purchases + 1;
            }

            $window->tokens_spent = (int) $window->tokens_spent + $cost;
            $window->purchases = (int) $window->purchases + 1;
            $window->save();

            return $window;
        });
    }

    /**
     * Push a date `$days` further out, from itself when it is still in the future and
     * from now when it has already passed or was never set.
     */
    protected function extend(?Carbon $from, int $days): Carbon
    {
        $base = $from !== null && $from->isFuture() ? $from->copy() : now();

        return $base->addDays($days);
    }

    /**
     * Mark this user's waiting entries as priority.
     *
     * `queued_at` is deliberately left alone. The pass buys a place above everyone
     * without one, not a place above people who bought theirs earlier — that is the
     * whole of `scopeInLineOrder`, and re-stamping the arrival time would break it.
     */
    protected function stampWaiting(User $user): void
    {
        try {
            StartQueueEntry::query()
                ->waiting()
                ->where('user_id', $user->getKey())
                ->where('priority', false)
                ->update(['priority' => true]);
        } catch (Throwable $e) {
            $this->log('priority re-stamp of waiting entries failed', $e);
        }
    }

    protected function refund(User $user, int $amount): void
    {
        $tokens = $this->tokens();

        if ($tokens === null) {
            return;
        }

        try {
            $tokens->add(
                $user,
                $amount,
                'queue:priority-refund',
                trans('plugged-host-queue::strings.tokens_tx_priority_refund'),
                ['refund' => true],
            );
        } catch (Throwable $e) {
            $this->log('priority refund failed', $e);
        }
    }

    /** plugged-host-economy's ledger, or null when it is not installed. */
    protected function tokens(): mixed
    {
        if (!class_exists(self::TOKENS)) {
            return null;
        }

        try {
            return app(self::TOKENS);
        } catch (Throwable) {
            return null;
        }
    }

    protected function log(string $message, Throwable $e): void
    {
        Log::warning("[plugged-host-queue] {$message}", ['error' => $e->getMessage()]);
    }
}
