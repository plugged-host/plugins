<?php

namespace PluggedHost\Neo\Support;

use App\Models\User;
use PluggedHost\Neo\Models\NeoUserSetting;

/**
 * The per-user profile toggles ("Enable neo", "Let neo make changes without asking") and
 * consent, in neo_user_settings. A missing row reads as the defaults: on, asks first,
 * not yet acknowledged.
 */
final class NeoPreferences
{
    /**
     * @var array<int, NeoUserSetting>
     */
    private static array $memo = [];

    public static function for(User $user): NeoUserSetting
    {
        return self::$memo[$user->id] ??= NeoUserSetting::query()->find($user->id)
            ?? new NeoUserSetting([
                'user_id' => $user->id,
                'enabled' => true,
                'auto_approve' => false,
                'acknowledged_at' => null,
            ]);
    }

    public static function enabled(User $user): bool
    {
        return (bool) self::for($user)->enabled;
    }

    public static function autoApprove(User $user): bool
    {
        return (bool) self::for($user)->auto_approve;
    }

    public static function acknowledged(User $user): bool
    {
        return self::for($user)->acknowledged_at !== null;
    }

    /**
     * @param  array{enabled?: bool, auto_approve?: bool, acknowledged_at?: mixed}  $values
     */
    public static function set(User $user, array $values): NeoUserSetting
    {
        $setting = NeoUserSetting::query()->updateOrCreate(['user_id' => $user->id], $values);

        return self::$memo[$user->id] = $setting;
    }
}
