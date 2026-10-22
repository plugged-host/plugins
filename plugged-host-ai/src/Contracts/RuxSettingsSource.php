<?php

namespace PluggedHost\Ai\Contracts;

use PluggedHost\Ai\Rux\Support\RuxGuild;

/**
 * The Discord servers rux works on, and what each of them has of its own: the mod log, the
 * default giveaway channel, its own sites for the message checks, and the name and description
 * of the host behind it. Standalone (Rux\Support\EnvRuxSettingsSource) they are this panel's
 * settings (RUX_GUILD_ID and RUX_GUILD_{2..10}_* in the .env, edited in the rux tab); an account
 * system that keeps rux's settings itself (plugged-host-noprofit) binds its own.
 *
 * Only the servers come from here. Their rules, cases, moderator roles, watched channels and
 * giveaways stay in this panel's database, each row with its guild_id; admins (DiscordAccounts)
 * and the credit granters are the same on every server.
 *
 * guilds() is called for every message the gateway daemon sees, so an implementation that
 * fetches must keep its own copy (and keep answering from it while the source is down).
 */
interface RuxSettingsSource
{
    /**
     * Every server rux works on, the primary one first (RUX_GUILD_ID: a DM acts on it until the
     * admin picks another one), each id once. Empty when no server is set up.
     *
     * @return list<RuxGuild>
     */
    public function guilds(): array;
}
