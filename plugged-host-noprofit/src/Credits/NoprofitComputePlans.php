<?php

namespace PluggedHost\Noprofit\Credits;

use App\Models\User;
use PluggedHost\Economy\Contracts\ComputePlans;
use PluggedHost\Noprofit\Services\NoprofitCreditSync;

/**
 * Plans, tiers and store prices as noprofit.space sets them (they arrive with the credit sync).
 * Starting credits are granted there, so the panel grants none.
 */
final class NoprofitComputePlans implements ComputePlans
{
    public function plan(?User $user): ?array
    {
        return NoprofitCreditSync::plan($user);
    }

    /** The noprofit.space pool, which plugged.cloud shares. */
    public function pool(?User $user): ?array
    {
        return NoprofitCreditSync::pool($user);
    }

    public function tiers(): ?array
    {
        return NoprofitCreditSync::tiers();
    }

    public function pricing(int $maxAge = self::PRICING_MAX_AGE): ?array
    {
        return NoprofitCreditSync::pricing($maxAge);
    }

    public function startingCredits(): int
    {
        return 0;
    }

    public function editableHere(): bool
    {
        return false;
    }

    public function forget(User $user): void {}
}
