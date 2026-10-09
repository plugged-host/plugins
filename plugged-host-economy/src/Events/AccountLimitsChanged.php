<?php

namespace PluggedHost\Economy\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An account's limits changed (its plan, bought resources or PlusPlus). Anything that remembers
 * them listens: servers' AccountAllowance drops its memo.
 */
final class AccountLimitsChanged
{
    use Dispatchable;

    public function __construct(public readonly User $user) {}
}
