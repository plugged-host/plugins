<?php

namespace PluggedHost\Ai\Support;

use App\Models\User;
use PluggedHost\Ai\Models\NeoUserSetting;

/**
 * The per-user switches in the chat's settings ("Enable neo", "Let neo make changes without
 * asking"), the model picked in the chat, and consent, in neo_user_settings. A missing row reads as
 * the defaults: on, asks first, the standard model, not yet acknowledged.
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
                'model' => 'standard',
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

    /**
     * The model picked in the chat: 'premium' or 'standard'. Whether the account may still use the
     * premium one is NeoGateway::modelFor()'s question.
     */
    public static function model(User $user): string
    {
        return self::for($user)->model === 'premium' ? 'premium' : 'standard';
    }

    public static function acknowledged(User $user): bool
    {
        return self::for($user)->acknowledged_at !== null;
    }

    /**
     * @param  array{enabled?: bool, auto_approve?: bool, model?: string, acknowledged_at?: mixed}  $values
     */
    public static function set(User $user, array $values): NeoUserSetting
    {
        $setting = NeoUserSetting::query()->updateOrCreate(['user_id' => $user->id], $values);

        return self::$memo[$user->id] = $setting;
    }
}
