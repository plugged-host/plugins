<?php

namespace PluggedHost\Minecraft\Support;

use App\Models\Egg;

/**
 * The Minecraft: Java Edition egg this plugin ships (egg/egg-minecraft-java.json, exported from
 * plugged.host's panel). servers' catalog (plugged-host-servers.user-creatable-servers.catalog)
 * is keyed to its UUID, and the Minecraft pages only appear on servers of a catalog egg.
 * `php artisan plugged-host:minecraft-setup` imports it.
 */
final class MinecraftEgg
{
    public const UUID = '2f6a1c58-9e4b-4d7a-8c31-5b0e7f9a4d62';

    public static function path(): string
    {
        return plugin_path('plugged-host-minecraft', 'egg', 'egg-minecraft-java.json');
    }

    public static function find(): ?Egg
    {
        return Egg::query()->where('uuid', self::UUID)->first();
    }
}
