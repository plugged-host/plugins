<?php

namespace PluggedHost\Servers\Support;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The per-user "paid location tier unlocked" flags (WP13 premium, plus the cheaper Plus tier),
 * same shape as Support\OAuthPasswordGate / Support\PanelTour: each tier reads and writes its own
 * nullable users.<tier>_unlocked_at behind a memoised Schema::hasColumn guard, using
 * forceFill(...)->saveQuietly() since the columns cannot live in upstream User::$fillable (and
 * users.customization is off-limits — EditProfile rebuilds it).
 *
 * Tiers are ranked: unlocking a higher tier also opens every tier below it, so a Premium user
 * never pays again for Plus locations (and nobody who bought Premium before Plus existed loses
 * the locations that moved down a tier).
 *
 * When a tier's column does not exist yet (migration unrun) that tier reports "locked" and
 * unlock() is a no-op, so its locations are simply omitted — the plan's standard
 * dark-until-migration degrade.
 */
class TierAccess
{
    public const FREE = 'free';

    public const PLUS = 'plus';

    public const PREMIUM = 'premium';

    /** Paid tiers, lowest first, and the users column recording each unlock. */
    private const COLUMNS = [
        self::PLUS => 'plus_unlocked_at',
        self::PREMIUM => 'premium_unlocked_at',
    ];

    /** @var array<string, bool> */
    private static array $columnExists = [];

    /** @return string[] Paid tiers, lowest first. */
    public static function paidTiers(): array
    {
        return array_keys(self::COLUMNS);
    }

    public static function isPaidTier(string $tier): bool
    {
        return array_key_exists($tier, self::COLUMNS);
    }

    public static function supported(string $tier): bool
    {
        if (!self::isPaidTier($tier)) {
            return false;
        }

        if (!array_key_exists($tier, self::$columnExists)) {
            try {
                self::$columnExists[$tier] = Schema::hasColumn('users', self::COLUMNS[$tier]);
            } catch (Throwable) {
                self::$columnExists[$tier] = false;
            }
        }

        return self::$columnExists[$tier];
    }

    /** True for the free tier, and for a paid tier the user (or a higher tier) has unlocked. */
    public static function isUnlocked(?User $user, string $tier): bool
    {
        if ($tier === self::FREE) {
            return true;
        }

        if ($user === null || !self::supported($tier)) {
            return false;
        }

        $tiers = self::paidTiers();

        // This tier or any tier ranked above it.
        foreach (array_slice($tiers, (int) array_search($tier, $tiers, true)) as $candidate) {
            if (self::ownUnlock($user, $candidate)) {
                return true;
            }
        }

        return false;
    }

    public static function unlock(User $user, string $tier): bool
    {
        if (!self::supported($tier)) {
            return false;
        }

        try {
            if (self::ownUnlock($user, $tier)) {
                return true; // already unlocked
            }

            $user->forceFill([self::COLUMNS[$tier] => now()])->saveQuietly();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Whether this exact tier was bought, ignoring the higher tiers that also cover it. */
    private static function ownUnlock(User $user, string $tier): bool
    {
        if (!self::supported($tier)) {
            return false;
        }

        try {
            return $user->getAttribute(self::COLUMNS[$tier]) !== null;
        } catch (Throwable) {
            return false;
        }
    }
}
