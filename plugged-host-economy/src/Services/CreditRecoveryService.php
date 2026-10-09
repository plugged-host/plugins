<?php

namespace PluggedHost\Economy\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use PluggedHost\Servers\Support\AccountAllowance;
use PluggedHost\Economy\Models\CreditTransaction;
use PluggedHost\Economy\Models\UserCredits;

/**
 * Reclaims credits that were awarded and should not have been kept — currently
 * only Discord invite rewards whose invited member left the server again.
 *
 * `user_credits.credits` and every `extra_*` column are unsigned, so nothing here
 * may ever drive a value below zero. Recovery therefore runs as a cascade and
 * reports whatever it could not take rather than failing:
 *
 *   1. the credit balance,
 *   2. purchased resources sold back at what the store would charge for them now
 *      (the top of the price curve, see ComputePricing), but only out of the
 *      headroom the user's servers are not already using,
 *   3. the remainder, returned as `shortfall` for the caller to carry as debt.
 *
 * Step 2 is the part that needs care: shrinking a limit below what running
 * servers already consume would leave the owner permanently over quota and unable
 * to create or edit anything. `resourceHeadroom()` is what prevents that.
 */
class CreditRecoveryService
{
    public function __construct(protected ResourceStoreService $store) {}

    /**
     * @return array{
     *     requested: int,
     *     from_credits: int,
     *     from_resources: int,
     *     recovered: int,
     *     shortfall: int,
     *     reclaimed: array{cpu: int, memory: int, disk: int},
     *     balance_after: int
     * }
     */
    public function recover(User $user, int $amount, string $type, string $description, array $metadata = []): array
    {
        $reclaimed = ['cpu' => 0, 'memory' => 0, 'disk' => 0];
        $result = [
            'requested' => max(0, $amount),
            'from_credits' => 0,
            'from_resources' => 0,
            'recovered' => 0,
            'shortfall' => max(0, $amount),
            'reclaimed' => $reclaimed,
            'balance_after' => 0,
        ];

        $userCredits = UserCredits::forUser($user);
        if (!$userCredits) {
            return $result;
        }

        $result['balance_after'] = (int) $userCredits->credits;

        if ($amount <= 0) {
            $result['shortfall'] = 0;

            return $result;
        }

        $owed = $amount;

        // 1. Whatever the balance can cover.
        $fromCredits = $userCredits->deductCreditsUpTo($amount, $type, $description, $metadata + ['recovery_stage' => 'credits']);
        $owed -= $fromCredits;
        $result['from_credits'] = $fromCredits;

        // 2. Sell purchased resources back out of unused headroom.
        if ($owed > 0 && $this->resourceReclaimEnabled()) {
            [$owed, $reclaimed, $fromResources] = $this->reclaimResources($user, $userCredits, $owed, $type, $description, $metadata);
            $result['reclaimed'] = $reclaimed;
            $result['from_resources'] = $fromResources;
        }

        $userCredits->refresh();

        $result['recovered'] = $result['from_credits'] + $result['from_resources'];
        $result['shortfall'] = max(0, $owed);
        $result['balance_after'] = (int) $userCredits->credits;

        return $result;
    }

    public function resourceReclaimEnabled(): bool
    {
        return (bool) config('plugged-host-economy.credits.reclaim_resources', true);
    }

    /**
     * Walk the purchasable resources cheapest-disruption first, taking each back
     * off the top at what the store would charge for it now.
     *
     * Disk goes first because it is the least performance-sensitive and usually
     * has the most slack.
     *
     * @return array{0: int, 1: array{cpu: int, memory: int, disk: int}, 2: int}
     */
    protected function reclaimResources(
        User $user,
        UserCredits $userCredits,
        int $owed,
        string $type,
        string $description,
        array $metadata
    ): array {
        $reclaimed = ['cpu' => 0, 'memory' => 0, 'disk' => 0];
        $recovered = 0;

        $headroom = $this->resourceHeadroom($user, $userCredits);

        foreach (['disk', 'cpu', 'memory'] as $resource) {
            if ($owed <= 0) {
                break;
            }

            $available = max(0, min((int) $userCredits->{"extra_{$resource}"}, $headroom[$resource]));
            if ($available <= 0) {
                continue;
            }

            $units = $this->unitsWorth($user, $userCredits, $resource, $owed, $available);

            // Never credit back more than is owed, even when the store's ceil()
            // pricing values the reclaimed slice slightly above the debt.
            $value = min($owed, $this->store->value($user, $resource, $units, $userCredits));
            if ($value <= 0) {
                continue;
            }

            DB::transaction(function () use ($userCredits, $resource, $units) {
                $userCredits->reduceExtraResources(...[$resource => $units]);
            });

            $reclaimed[$resource] += $units;
            $recovered += $value;
            $owed -= $value;
        }

        if ($recovered > 0) {
            // Recorded at amount 0: the balance did not move, resources did. Logging
            // it as a negative amount would double-count the clawback in the ledger.
            CreditTransaction::create([
                'user_id' => $userCredits->user_id,
                'amount' => 0,
                'balance_after' => (int) $userCredits->refresh()->credits,
                'type' => 'resource_reclaim',
                'description' => $description,
                'metadata' => $metadata + [
                    'recovery_stage' => 'resources',
                    'credits_recovered' => $recovered,
                    'reclaimed' => array_filter($reclaimed),
                    'source_type' => $type,
                ],
                'created_at' => now(),
            ]);
        }

        return [$owed, $reclaimed, $recovered];
    }

    /**
     * The least of $resource (at most $available) whose store value covers $owed:
     * the top of the curve is the dearest part, so this takes as little as it can.
     */
    protected function unitsWorth(User $user, UserCredits $userCredits, string $resource, int $owed, int $available): int
    {
        if ($this->store->value($user, $resource, $available, $userCredits) <= $owed) {
            return $available;
        }

        [$low, $high] = [1, $available];

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if ($this->store->value($user, $resource, $middle, $userCredits) >= $owed) {
                $high = $middle;
            } else {
                $low = $middle + 1;
            }
        }

        return $low;
    }

    /**
     * How much of each purchased resource can be taken back without pushing the
     * user's effective limit below what their existing servers already allocate.
     *
     * A limit of 0 means "unlimited" in this codebase, and AccountAllowance
     * does not apply extras on top of an unlimited limit — so in that case the
     * whole purchased amount is inert and free to reclaim.
     *
     * @return array{cpu: int, memory: int, disk: int}
     */
    public function resourceHeadroom(User $user, ?UserCredits $userCredits = null): array
    {
        $userCredits ??= UserCredits::forUser($user);
        $limits = AccountAllowance::forUser($user);

        if (!$userCredits) {
            return ['cpu' => 0, 'memory' => 0, 'disk' => 0];
        }

        $usedCpu = (int) $user->servers()->sum('cpu');
        $usedMemory = (int) $user->servers()->sum('memory');
        $usedDisk = (int) $user->servers()->sum('disk');

        return [
            'cpu' => $this->headroomFor($limits?->cpu, $usedCpu, (int) $userCredits->extra_cpu),
            'memory' => $this->headroomFor($limits?->memory, $usedMemory, (int) $userCredits->extra_memory),
            'disk' => $this->headroomFor($limits?->disk, $usedDisk, (int) $userCredits->extra_disk),
        ];
    }

    protected function headroomFor(?int $limit, int $used, int $purchased): int
    {
        // null (no plan) or 0 (unlimited resource): the extra is not being
        // applied to anything, so reclaiming it cannot put a server over quota.
        if ($limit === null || $limit <= 0) {
            return $purchased;
        }

        return max(0, min($purchased, $limit - $used));
    }
}
