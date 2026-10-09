<?php

namespace PluggedHost\Economy\Services;

use App\Models\Server;
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

    /**
     * What the account's servers use together, in one aggregate query.
     *
     * @return array{cpu: int, memory: int, disk: int, count: int}
     */
    public static function usage(User $user): array
    {
        $row = Server::query()
            ->where('owner_id', $user->getKey())
            ->toBase()
            ->selectRaw('COUNT(*) AS count')
            ->selectRaw('COALESCE(SUM(cpu), 0) AS cpu')
            ->selectRaw('COALESCE(SUM(memory), 0) AS memory')
            ->selectRaw('COALESCE(SUM(disk), 0) AS disk')
            ->first();

        return [
            'cpu' => (int) ($row?->cpu ?? 0),
            'memory' => (int) ($row?->memory ?? 0),
            'disk' => (int) ($row?->disk ?? 0),
            'count' => (int) ($row?->count ?? 0),
        ];
    }

    /**
     * How far the account's servers are above these limits (0 = unlimited, never exceeded).
     *
     * @param  array{cpu: int, memory: int, disk: int}  $limits
     * @return array{cpu: int, memory: int, disk: int}
     */
    public static function overage(User $user, array $limits): array
    {
        $usage = self::usage($user);
        $over = fn (int $limit, int $used): int => $limit > 0 ? max(0, $used - $limit) : 0;

        return [
            'cpu' => $over((int) $limits['cpu'], $usage['cpu']),
            'memory' => $over((int) $limits['memory'], $usage['memory']),
            'disk' => $over((int) $limits['disk'], $usage['disk']),
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
