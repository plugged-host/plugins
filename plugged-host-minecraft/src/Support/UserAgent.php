<?php

namespace PluggedHost\Minecraft\Support;

use PluggedHost\Core\Support\Release;

/**
 * The User-Agent of every call this plugin makes to Modrinth, CurseForge, Spiget, Mojang and the
 * loaders' APIs (and, through plugged-host:minecraft-setup, the egg's installer). Modrinth asks
 * for one that names the application and says how to reach whoever runs it.
 *
 * MINECRAFT_USER_AGENT replaces it whole. Without it: "<app name>-panel/<release> (<contact>)",
 * the contact being mail.from.address, or the panel's URL while that is unset or still an
 * example.com placeholder (Laravel's default).
 */
final class UserAgent
{
    public static function get(): string
    {
        $configured = trim((string) config('plugged-host-minecraft.user_agent', ''));
        if ($configured !== '') {
            return $configured;
        }

        // A product token has no spaces or separators: "My Host" becomes "My-Host".
        $name = trim((string) preg_replace('/[^A-Za-z0-9.!#$%&\'*+^_`|~-]+/', '-', (string) config('app.name', '')), '-');
        $product = ($name !== '' ? $name : 'pelican') . '-panel/' . Release::version();

        $contact = trim((string) config('mail.from.address', ''));
        if (filter_var($contact, FILTER_VALIDATE_EMAIL) === false || preg_match('/@example\.(com|net|org)$/i', $contact) === 1) {
            $contact = rtrim((string) config('app.url', ''), '/');
        }

        return $contact !== '' ? "{$product} ({$contact})" : $product;
    }
}
