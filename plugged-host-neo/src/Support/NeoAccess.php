<?php

namespace PluggedHost\Neo\Support;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Whether neo is usable at all, and for whom. Checked server-side on every entry point, so
 * hiding the launcher is never the only thing standing between a disabled user and neo.
 */
final class NeoAccess
{
    private static ?bool $tablesReady = null;

    /**
     * Globally switched on and migrated.
     */
    public static function ready(): bool
    {
        if (!config('plugged-host-neo.enabled', false)) {
            return false;
        }

        if (self::$tablesReady !== null) {
            return self::$tablesReady;
        }

        try {
            return self::$tablesReady = Schema::hasTable('neo_conversations')
                && Schema::hasTable('neo_messages')
                && Schema::hasTable('neo_user_settings');
        } catch (Throwable) {
            return false;
        }
    }

    public static function enabledFor(?User $user): bool
    {
        return $user instanceof User && self::ready() && NeoPreferences::enabled($user);
    }
}
