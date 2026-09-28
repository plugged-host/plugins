<?php

namespace PluggedHost\Servers\Support;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The per-user "paid locations unlocked" flag (WP13) — one purchase covers both the Plus and
 * Premium tiers, which are two labels over this single entitlement. Same shape as
 * Support\PanelTour: reads and writes users.premium_unlocked_at behind
 * a memoised Schema::hasColumn guard, using forceFill(...)->saveQuietly() since the column cannot
 * live in upstream User::$fillable (and users.customization is off-limits — EditProfile rebuilds
 * it).
 *
 * When the column does not exist yet (migration unrun) everything reports "locked" and unlock()
 * is a no-op, so paid locations are simply omitted — the plan's standard dark-until-migration
 * degrade.
 */
class PremiumAccess
{
    private static ?bool $columnExists = null;

    public static function supported(): bool
    {
        if (self::$columnExists === null) {
            try {
                self::$columnExists = Schema::hasColumn('users', 'premium_unlocked_at');
            } catch (Throwable) {
                self::$columnExists = false;
            }
        }

        return self::$columnExists;
    }

    public static function isUnlocked(?User $user): bool
    {
        if ($user === null || !self::supported()) {
            return false;
        }

        try {
            return $user->premium_unlocked_at !== null;
        } catch (Throwable) {
            return false;
        }
    }

    public static function unlock(User $user): bool
    {
        if (!self::supported()) {
            return false;
        }

        try {
            if ($user->premium_unlocked_at !== null) {
                return true; // already unlocked
            }

            $user->forceFill(['premium_unlocked_at' => now()])->saveQuietly();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
