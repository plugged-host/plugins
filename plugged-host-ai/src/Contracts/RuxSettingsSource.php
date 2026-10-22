<?php

namespace PluggedHost\Ai\Contracts;

use PluggedHost\Ai\Rux\Support\RuxGuild;

/**
 * Every setting rux runs on: on or off, the bot token, the Discord servers it works on (and what
 * each of them has of its own: the mod log, the default giveaway channel, its own sites for the
 * message checks, the name and description of the host behind it), the time zone and language,
 * the giveaway and credit limits, the main admins, the warning steps, the checks, the spam guard
 * and the agent loop. Standalone (Rux\Support\EnvRuxSettingsSource) they are this panel's settings
 * (config plugged-host-ai.rux, from the .env, edited in the rux tab); an account system that keeps
 * rux's settings itself (plugged-host-noprofit: Admin > rux on noprofit.space) binds its own.
 *
 * Only the settings come from here. rux's rules, cases, moderator roles, watched channels and
 * giveaways stay in this panel's database, each row with its guild_id; admins (DiscordAccounts)
 * and the credit granters are the same on every server.
 *
 * guilds() and settings() are called for every message the gateway daemon sees, so an
 * implementation that fetches must keep its own copy (and keep answering from it while the source
 * is down).
 */
interface RuxSettingsSource
{
    /**
     * Every server rux works on, the primary one first (guild_id: a DM acts on it until the admin
     * picks another one), each id once. Empty when no server is set up.
     *
     * @return list<RuxGuild>
     */
    public function guilds(): array;

    /**
     * The settings, in the shape of config('plugged-host-ai.rux') (read one with
     * RuxConfig::get('spam.enabled')): the panel's config with whatever the source keeps laid
     * over it.
     *
     * @return array<string, mixed>
     */
    public function settings(): array;

    /**
     * Where the settings are edited when it is not this panel's rux tab, which then only shows
     * them: ['by' => 'accounts.example.org', 'url' => its page for them, 'fetched_at' => unix time
     * of the copy in use or null, and when it says so 'saved_at' and 'saved_by']. Null: this
     * panel's own settings apply and the rux tab edits them.
     *
     * @return array{by: string, url: string, fetched_at: ?int, saved_at?: ?string, saved_by?: ?string}|null
     */
    public function managedBy(): ?array;
}
