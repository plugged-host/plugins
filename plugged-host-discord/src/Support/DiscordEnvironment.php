<?php

namespace PluggedHost\Discord\Support;

/**
 * Whether this panel may act on the real Discord server: connect the gateway, answer slash
 * commands, register them, and give or take roles.
 *
 * Only the production panel may, unless DISCORD_ALLOW_NON_PRODUCTION says otherwise. A dev
 * panel that copies production's .env carries production's bot token, and without this its
 * hourly discord-sync would add and remove real members' roles from dev's database, and a
 * second gateway daemon would answer every slash command twice. Give a dev panel its own
 * Discord application before turning the switch on. neo and rux have the same rule
 * (NeoBotConfig::blockers(), RuxConfig::blockers()).
 */
final class DiscordEnvironment
{
    public static function allowsGuildAccess(): bool
    {
        return app()->environment('production')
            || (bool) config('plugged-host-discord.allow_non_production', false);
    }

    /**
     * Why this panel leaves the Discord server alone, or null when it does not.
     */
    public static function blocker(): ?string
    {
        if (self::allowsGuildAccess()) {
            return null;
        }

        return 'The Discord bot only acts on the Discord server from the production panel (APP_ENV is '
            . app()->environment() . '). Give this panel its own Discord application and set '
            . 'DISCORD_ALLOW_NON_PRODUCTION=true to use it here.';
    }
}
