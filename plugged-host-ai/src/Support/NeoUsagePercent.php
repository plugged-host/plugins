<?php

namespace PluggedHost\Ai\Support;

use App\Models\User;
use PluggedHost\Core\Support\Plugins;
use Throwable;

/**
 * How much neo an account gets, as a percentage of the free limits: more with plugged.host Plus
 * and PlusPlus (plugged-host-economy's MembershipService), 100 without economy. Whichever backend
 * meters neo applies it.
 */
final class NeoUsagePercent
{
    public static function for(?User $user): int
    {
        $membership = 'PluggedHost\\Economy\\Services\\MembershipService';

        try {
            return $user !== null && Plugins::available($membership) ? max(100, (int) app($membership)->aiUsagePercent($user)) : 100;
        } catch (Throwable) {
            return 100;
        }
    }
}
