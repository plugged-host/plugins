<?php

namespace PluggedHost\Economy\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use PluggedHost\Economy\Models\UserCredits;
use PluggedHost\Economy\Support\ComputePricing;

/**
 * The Resource Store's rules. CPU, memory and disk belong to the account's plan on
 * noprofit.space: the limits come with the plan and the prices with the sync
 * (NoprofitCreditSync). Without both the store does not sell them, rather than guess at numbers
 * noprofit.space may have changed.
 *
 * Nothing caps an account. Every step is priced on noprofit.space's curve (ComputePricing):
 * cheap on the way to the limit, climbing past it, steep past the soft limit.
 */
class ResourceStoreService
{
    public const RESOURCES = ['cpu', 'memory', 'disk'];

    /**
     * The most bought at once, in steps (the same as noprofit.space's store).
     */
    public const MAX_STEPS = 50;

    /**
     * How old (seconds) the prices may be when a purchase is priced: they are asked for again
     * from noprofit.space first.
     */
    public const PURCHASE_PRICING_MAX_AGE = 30;

    public function sellsComputeTo(User $user): bool
    {
        return NoprofitCreditSync::plan($user) !== null && $this->pricing() !== null;
    }

    public function pricing(int $maxAge = NoprofitCreditSync::PRICING_MAX_AGE): ?ComputePricing
    {
        return ComputePricing::current($maxAge);
    }

    /**
     * The step CPU (%), memory or disk (MB) is sold in -- the unit noprofit.space prices -- or 0
     * while no prices are known.
     */
    public function step(string $resource): int
    {
        return $this->pricing()?->step($resource) ?? 0;
    }

    /**
     * Where a step of $resource reaches its full price for this account (the plan's max_*).
     */
    public function limit(User $user, string $resource): int
    {
        return max(0, (int) (NoprofitCreditSync::plan($user)["max_{$resource}"] ?? 0));
    }

    public function softLimit(User $user, string $resource): int
    {
        return $this->pricing()?->softLimit($this->limit($user, $resource)) ?? 0;
    }

    /**
     * What the account has of $resource for pricing: the plan's base plus the resources bought
     * here, including ones noprofit.space has not confirmed yet.
     */
    public function current(User $user, string $resource, ?UserCredits $credits = null): int
    {
        $plan = NoprofitCreditSync::plan($user);

        if ($plan === null) {
            return 0;
        }

        if (!isset($plan["base_{$resource}"])) {
            return (int) ($plan[$resource] ?? 0);
        }

        $credits ??= UserCredits::forUser($user);

        return (int) $plan["base_{$resource}"] + (int) ($credits?->{"extra_{$resource}"} ?? 0);
    }

    /**
     * Credits for $amount more of $resource for this account right now, or 0 while nothing is
     * for sale.
     */
    public function price(User $user, string $resource, int $amount): int
    {
        if (NoprofitCreditSync::plan($user) === null) {
            return 0;
        }

        return $this->pricing()?->cost($resource, $this->current($user, $resource), $amount, $this->limit($user, $resource)) ?? 0;
    }

    /**
     * What taking $amount of $resource back off the top of this account is worth: the price the
     * same amount would cost to buy back.
     */
    public function value(User $user, string $resource, int $amount, ?UserCredits $credits = null): int
    {
        if (NoprofitCreditSync::plan($user) === null) {
            return 0;
        }

        $current = $this->current($user, $resource, $credits);
        $amount = min($amount, $current);

        return $this->pricing()?->cost($resource, $current - $amount, $amount, $this->limit($user, $resource)) ?? 0;
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function purchaseCpu(User $user, int $amountPercent): array
    {
        return $this->purchase($user, 'cpu', $amountPercent);
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function purchaseMemory(User $user, int $amountMb): array
    {
        return $this->purchase($user, 'memory', $amountMb);
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function purchaseDisk(User $user, int $amountMb): array
    {
        return $this->purchase($user, 'disk', $amountMb);
    }

    /**
     * Buy $amount of $resource at noprofit.space's current price. The credits row is locked
     * for the whole price-and-spend, so two purchases at once are priced one after the other.
     * noprofit.space checks the price again when the change reaches it and refuses an
     * underpaid one, which is then undone here (NoprofitCreditSync::push).
     *
     * @return array{success: bool, message: string}
     */
    public function purchase(User $user, string $resource, int $amount): array
    {
        if (!in_array($resource, self::RESOURCES, true)) {
            return ['success' => false, 'message' => 'Unknown resource.'];
        }

        // Prices younger than half a minute: asked for again from noprofit.space first.
        $pricing = $this->pricing(self::PURCHASE_PRICING_MAX_AGE);
        $step = $pricing?->step($resource) ?? 0;

        if ($pricing === null || $step <= 0 || NoprofitCreditSync::plan($user) === null) {
            return ['success' => false, 'message' => trans('plugged-host-economy::strings.credits.store_unavailable')];
        }

        if ($amount <= 0 || $amount > self::MAX_STEPS * $step) {
            return ['success' => false, 'message' => trans('plugged-host-economy::strings.credits.invalid_amount')];
        }

        $result = DB::transaction(function () use ($user, $resource, $amount, $pricing): array {
            $credits = UserCredits::query()->where('user_id', $user->getKey())->lockForUpdate()->first();

            if ($credits === null) {
                return ['success' => false, 'message' => trans('plugged-host-economy::strings.credits.insufficient_credits')];
            }

            $cost = $pricing->cost($resource, $this->current($user, $resource, $credits), $amount, $this->limit($user, $resource));

            if ($cost >= ComputePricing::UNAFFORDABLE || !$credits->canAfford($cost)) {
                return ['success' => false, 'message' => trans('plugged-host-economy::strings.credits.insufficient_credits')];
            }

            [$label, $unit] = $this->describe($resource, $amount);

            if ($cost > 0) {
                $credits->deductCredits(
                    $cost,
                    'resource_purchase',
                    trans("plugged-host-economy::strings.credits.tx_{$resource}_purchase", ['amount' => $label, 'unit' => $unit]),
                    ['resource' => $resource, 'amount' => $amount, 'cost' => $cost]
                );
            }

            $credits->addExtraResources(...[$resource => $amount]);

            return [
                'success' => true,
                'message' => trans("plugged-host-economy::strings.credits.{$resource}_purchased", ['amount' => $label, 'unit' => $unit, 'cost' => number_format($cost)]),
            ];
        });

        UserCredits::forgetMemo($user);

        return $result;
    }

    /**
     * The amount as the purchase messages show it, and its unit.
     *
     * @return array{0: string, 1: string}
     */
    private function describe(string $resource, int $amount): array
    {
        if ($resource === 'cpu') {
            return [(string) $amount, '%'];
        }

        return [(string) $amount, config('panel.use_binary_prefix') ? 'MiB' : 'MB'];
    }
}
