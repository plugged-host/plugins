<?php

namespace PluggedHost\Economy\Services;

use App\Models\User;
use PluggedHost\Economy\Contracts\ComputePlans;
use Throwable;

/**
 * What an account may use across all its servers: its plan (ComputePlans) plus the CPU
 * PlusPlus adds while it runs. The one definition servers' AccountAllowance (which enforces it)
 * and the topbar's AccountSummary (which shows it) both read.
 */
final class AccountLimits
{
    /**
     * Null without a plan: then the account has no allowance.
     *
     * @return null|array{cpu: int, memory: int, disk: int}
     */
    public static function forUser(?User $user): ?array
    {
        $plan = app(ComputePlans::class)->plan($user);

        if ($user === null || $plan === null) {
            return null;
        }

        // 0 reads as "unlimited" everywhere on the panel, so a zero plan is kept at the
        // smallest real limit instead.
        return [
            'cpu' => max(1, (int) $plan['cpu'] + self::cpuBonus($user)),
            'memory' => max(1, (int) $plan['memory']),
            'disk' => max(1, (int) $plan['disk']),
        ];
    }

    private static function cpuBonus(User $user): int
    {
        try {
            return (int) app(MembershipService::class)->cpuBonus($user);
        } catch (Throwable) {
            return 0;
        }
    }
}
