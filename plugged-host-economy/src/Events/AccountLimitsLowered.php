<?php

namespace PluggedHost\Economy\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Part of an account's limits was taken away, and its servers have to give it back: the base
 * went down on noprofit.space (an admin, a lower tier, a Discord server left; plugged-host-noprofit
 * sees it in the plan). Up to these amounts come off the account's servers, evenly, as far as
 * they no longer fit (plugged-host-servers' AllowanceTrim). CPU in %, memory and disk in MB.
 *
 * Dispatched inside the transaction that takes on the lower limits, so a listener's record of
 * it commits or rolls back with them.
 */
final class AccountLimitsLowered
{
    use Dispatchable;

    public function __construct(
        public readonly User $user,
        public readonly int $cpu,
        public readonly int $memory,
        public readonly int $disk,
        public readonly string $reason,
    ) {}
}
