<?php

namespace PluggedHost\Ai\Support;

use App\Models\User;
use PluggedHost\Core\Support\Plugins;
use Throwable;

/**
 * The model a neo chat turn asks for: 'premium' (the premium provider slots, counted at a multiple
 * of their tokens) for plugged.host PlusPlus accounts that picked it in the chat, 'standard' for
 * everyone else. Whichever backend runs neo routes and meters by it.
 */
final class NeoModel
{
    public const STANDARD = 'standard';

    public const PREMIUM = 'premium';

    /**
     * Whether the account may use the premium model: PlusPlus (plugged-host-economy's
     * MembershipService); nobody without economy.
     */
    public static function premiumAllowed(?User $user): bool
    {
        $membership = 'PluggedHost\\Economy\\Services\\MembershipService';

        try {
            return $user !== null && Plugins::available($membership) && app($membership)->hasPlusPlus($user);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The model picked in the chat, while the account may still use it (PlusPlus can run out after
     * it was picked).
     */
    public static function forTurn(User $user): string
    {
        return NeoPreferences::model($user) === self::PREMIUM && self::premiumAllowed($user) ? self::PREMIUM : self::STANDARD;
    }
}
