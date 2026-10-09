<?php

namespace PluggedHost\Servers\Support;

use App\Models\User;
use PluggedHost\Servers\Services\LocationCatalog;
use Throwable;

/**
 * Who may use the paid locations. The Plus locations come with plugged.host Plus, the Premium
 * ones with PlusPlus, both sold and kept by plugged-host-economy (MembershipService, reached by
 * class name). Without economy, or before its table exists, every paid location is locked.
 *
 * The one-time "unlock every paid location" (users.premium_unlocked_at) this class used to
 * sell is gone; whoever bought it got Plus (economy migration 015).
 */
class PremiumAccess
{
    private const MEMBERSHIP = 'PluggedHost\\Economy\\Services\\MembershipService';

    public static function supported(): bool
    {
        try {
            return class_exists(self::MEMBERSHIP) && app(self::MEMBERSHIP)->supported();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * May this user deploy to (or move to) a location of $tier? Free is open to everyone.
     */
    public static function isUnlocked(?User $user, string $tier = LocationCatalog::PREMIUM): bool
    {
        if ($tier === LocationCatalog::FREE) {
            return true;
        }

        if ($user === null || !self::supported()) {
            return false;
        }

        try {
            $membership = app(self::MEMBERSHIP);

            return $tier === LocationCatalog::PLUS ? $membership->hasPlus($user) : $membership->hasPlusPlus($user);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * What a tier needs: 'plus' or 'plusplus' (null for free).
     */
    public static function requires(string $tier): ?string
    {
        return match ($tier) {
            LocationCatalog::PLUS => 'plus',
            LocationCatalog::PREMIUM => 'plusplus',
            default => null,
        };
    }
}
