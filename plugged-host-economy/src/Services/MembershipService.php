<?php

namespace PluggedHost\Economy\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Economy\Events\AccountLimitsChanged;
use PluggedHost\Economy\Models\UserCredits;
use PluggedHost\Economy\Models\UserMembership;
use Throwable;

/**
 * plugged.host Plus and PlusPlus: who has them, and buying them.
 *
 * Plus is bought once with tokens and kept for good: the Plus locations, priority in the start
 * queue and no in-game ads. PlusPlus needs Plus and runs for a while, paid with tokens or
 * credits: everything in Plus, the Premium locations, no ads anywhere, the highest priority in
 * the start queue and more CPU. The other plugins ask this service (by class name, behind
 * class_exists) rather than keep a copy:
 *
 *  - plugged-host-servers: Plus/Premium locations (PremiumAccess), the CPU bonus
 *    (AccountAllowance) and what happens when PlusPlus runs out (plusplus:expire);
 *  - plugged-host-queue: the priority level (PriorityPassService);
 *  - plugged-host-ads: no ads for PlusPlus (an exemption registered by
 *    MembershipServiceProvider), no in-game ads for Plus.
 *
 * Scoped, so the per-user memo lasts one request or job.
 */
class MembershipService
{
    public const RESULT_OK = 'ok';

    public const RESULT_SHORT = 'short';

    public const RESULT_NEEDS_PLUS = 'needs_plus';

    public const RESULT_OWNED = 'owned';

    public const RESULT_UNAVAILABLE = 'unavailable';

    public const RESULT_FAILED = 'failed';

    public const PAY_TOKENS = 'tokens';

    public const PAY_CREDITS = 'credits';

    /** Ledger types (tokens and credits). */
    public const TYPE_PLUS = 'plus';

    public const TYPE_PLUSPLUS = 'plusplus';

    /** @var array<int, UserMembership|null> */
    protected array $memo = [];

    protected static ?bool $tableExists = null;

    public function enabled(): bool
    {
        return (bool) config('plugged-host-economy.membership.enabled', true) && $this->supported();
    }

    public function supported(): bool
    {
        return self::$tableExists ??= Schema::hasTable('user_memberships');
    }

    public function membership(?User $user): ?UserMembership
    {
        if (!$user instanceof User || !$this->supported()) {
            return null;
        }

        $id = (int) $user->getKey();

        if (!array_key_exists($id, $this->memo)) {
            try {
                $this->memo[$id] = UserMembership::query()->where('user_id', $id)->first();
            } catch (Throwable) {
                return null;
            }
        }

        return $this->memo[$id];
    }

    public function forget(?User $user = null): void
    {
        if ($user instanceof User) {
            unset($this->memo[(int) $user->getKey()]);

            return;
        }

        $this->memo = [];
    }

    public function hasPlus(?User $user): bool
    {
        return (bool) $this->membership($user)?->hasPlus();
    }

    public function hasPlusPlus(?User $user): bool
    {
        return (bool) $this->membership($user)?->hasPlusPlus();
    }

    /**
     * When PlusPlus runs out, or null when it is not running.
     */
    public function plusPlusUntil(?User $user): ?Carbon
    {
        $membership = $this->membership($user);

        return $membership?->hasPlusPlus() ? $membership->plusplus_until : null;
    }

    /**
     * The account's place in the start queue: 2 with PlusPlus, 1 with Plus, 0 without.
     */
    public function priorityLevel(?User $user): int
    {
        return match (true) {
            $this->hasPlusPlus($user) => 2,
            $this->hasPlus($user) => 1,
            default => 0,
        };
    }

    /**
     * CPU (%) PlusPlus adds to the account's allowance while it runs.
     */
    public function cpuBonus(?User $user): int
    {
        return $this->hasPlusPlus($user) ? max(0, (int) config('plugged-host-economy.membership.plusplus_cpu_bonus', 50)) : 0;
    }

    /**
     * neo (AI) usage as a percentage of the free limits: Plus plus_ai_percent, PlusPlus
     * plusplus_ai_percent on top of that. noprofit.space applies it (InternalNeoController).
     */
    public function aiUsagePercent(?User $user): int
    {
        $plus = max(100, (int) config('plugged-host-economy.membership.plus_ai_percent', 125));
        $plusPlus = max(100, (int) config('plugged-host-economy.membership.plusplus_ai_percent', 125));

        return match (true) {
            $this->hasPlusPlus($user) => min(1000, intdiv($plus * $plusPlus, 100)),
            $this->hasPlus($user) => min(1000, $plus),
            default => 100,
        };
    }

    public function plusCost(): int
    {
        return max(0, (int) config('plugged-host-economy.membership.plus_token_cost', 25));
    }

    /**
     * What PlusPlus costs and for how long, per way of paying.
     *
     * @return array{cost: int, days: int}
     */
    public function plusPlusOffer(string $pay): array
    {
        $key = $pay === self::PAY_CREDITS ? 'credit' : 'token';

        return [
            'cost' => max(0, (int) config("plugged-host-economy.membership.plusplus_{$key}_cost", $pay === self::PAY_CREDITS ? 150 : 10)),
            'days' => max(1, (int) config("plugged-host-economy.membership.plusplus_{$key}_days", $pay === self::PAY_CREDITS ? 14 : 7)),
        ];
    }

    /**
     * Buy Plus with tokens. The tokens and Plus change in one transaction: both or neither.
     */
    public function buyPlus(User $user): string
    {
        if (!$this->enabled()) {
            return self::RESULT_UNAVAILABLE;
        }

        if ($this->hasPlus($user)) {
            return self::RESULT_OWNED;
        }

        $cost = $this->plusCost();

        try {
            $result = DB::transaction(function () use ($user, $cost): string {
                $membership = $this->lockedRow($user);

                if ($membership->hasPlus()) {
                    return self::RESULT_OWNED;
                }

                if (!$this->spendTokens($user, $cost, self::TYPE_PLUS, trans('plugged-host-economy::strings.membership.tx_plus'), [])) {
                    return self::RESULT_SHORT;
                }

                $membership->forceFill(['plus_at' => now()])->save();

                return self::RESULT_OK;
            });
        } catch (Throwable $e) {
            Log::warning('Buying plugged.host Plus failed', ['user_id' => $user->getKey(), 'error' => $e->getMessage()]);

            $result = self::RESULT_FAILED;
        }

        $this->forget($user);

        return $result;
    }

    /**
     * Buy (or extend) PlusPlus with tokens or credits. Needs Plus. Time bought adds to whatever
     * is left, so buying early loses nothing.
     */
    public function buyPlusPlus(User $user, string $pay): string
    {
        if (!$this->enabled() || !in_array($pay, [self::PAY_TOKENS, self::PAY_CREDITS], true)) {
            return self::RESULT_UNAVAILABLE;
        }

        if ($pay === self::PAY_CREDITS && !config('plugged-host-economy.credits.enabled', true)) {
            return self::RESULT_UNAVAILABLE;
        }

        if (!$this->hasPlus($user)) {
            return self::RESULT_NEEDS_PLUS;
        }

        ['cost' => $cost, 'days' => $days] = $this->plusPlusOffer($pay);

        try {
            $result = DB::transaction(function () use ($user, $pay, $cost, $days): string {
                $membership = $this->lockedRow($user);

                if (!$membership->hasPlus()) {
                    return self::RESULT_NEEDS_PLUS;
                }

                $description = trans('plugged-host-economy::strings.membership.tx_plusplus', ['days' => $days]);
                $metadata = ['days' => $days, 'pay' => $pay];

                $paid = $pay === self::PAY_CREDITS
                    ? $this->spendCredits($user, $cost, $description, $metadata)
                    : $this->spendTokens($user, $cost, self::TYPE_PLUSPLUS, $description, $metadata);

                if (!$paid) {
                    return self::RESULT_SHORT;
                }

                $from = $membership->hasPlusPlus() ? $membership->plusplus_until : now();

                $membership->forceFill([
                    'plusplus_until' => $from->copy()->addDays($days),
                    'plusplus_purchases' => $membership->plusplus_purchases + 1,
                ])->save();

                return self::RESULT_OK;
            });
        } catch (Throwable $e) {
            Log::warning('Buying plugged.host PlusPlus failed', ['user_id' => $user->getKey(), 'pay' => $pay, 'error' => $e->getMessage()]);

            $result = self::RESULT_FAILED;
        }

        $this->forget($user);

        if ($result === self::RESULT_OK) {
            $this->forgetAllowance($user);
        }

        return $result;
    }

    /**
     * The membership row, created when missing, locked for the rest of the transaction.
     */
    protected function lockedRow(User $user): UserMembership
    {
        UserMembership::query()->firstOrCreate(['user_id' => $user->getKey()]);

        return UserMembership::query()->where('user_id', $user->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function spendTokens(User $user, int $cost, string $type, string $description, array $metadata): bool
    {
        if ($cost <= 0) {
            return true;
        }

        $tokens = app(TokenService::class);

        if (!$tokens->isEnabled()) {
            return false;
        }

        return $tokens->spend($user, $cost, $type, $description, $metadata);
    }

    /**
     * Credits move through UserCredits like every other spend, so noprofit.space sees it.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function spendCredits(User $user, int $cost, string $description, array $metadata): bool
    {
        if ($cost <= 0) {
            return true;
        }

        $credits = UserCredits::query()->where('user_id', $user->getKey())->lockForUpdate()->first();

        if ($credits === null || !$credits->canAfford($cost)) {
            return false;
        }

        try {
            $credits->deductCredits($cost, self::TYPE_PLUSPLUS, $description, $metadata);
        } catch (InvalidArgumentException) {
            return false;
        }

        UserCredits::forgetMemo($user);

        return true;
    }

    protected function forgetAllowance(User $user): void
    {
        AccountLimitsChanged::dispatch($user);

        $summary = 'PluggedHost\\Economy\\Services\\AccountSummary';
        if (Plugins::available($summary)) {
            app($summary)->forget($user);
        }
    }
}
