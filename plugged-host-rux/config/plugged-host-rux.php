<?php

/*
 * rux, the plugged.host Discord bot (moderation and credit giveaways).
 *
 * rux is its own Discord application with its own token, so it runs next to the plugged.host
 * bot (plugged-host-discord) rather than inside it. It only ever runs on the production panel:
 * RuxConfig::runnable() also requires APP_ENV=production, so a copy of this plugin on the dev
 * panel stays dark even with a token in its .env (two gateways on one token would answer every
 * message twice).
 *
 * Who may command rux, who is linked, and paying giveaway winners all belong to noprofit.space
 * (signed with NOPROFIT_SYNC_SECRET); rux's AI turns go through the neo gateway there
 * (RUX_GATEWAY_SECRET, by default the same as NEO_GATEWAY_SECRET) and are metered against the
 * neo limits of the admin who gave the order (or made the rule, or set up the moderator role).
 */
return [
    // Master switch. Ships dark.
    'enabled' => (bool) env('RUX_ENABLED', false),

    // The rux application's bot token (Discord Developer Portal → rux → Bot).
    'bot_token' => (string) env('RUX_BOT_TOKEN', ''),

    // The Discord server rux moderates. Defaults to the plugged.host bot's server.
    'guild_id' => (string) env('RUX_GUILD_ID', env('DISCORD_GUILD_ID', '')),

    // Where a giveaway goes when the admin names no channel, and where moderation is logged.
    // Both optional.
    'giveaway_channel_id' => (string) env('RUX_GIVEAWAY_CHANNEL_ID', ''),
    'mod_log_channel_id' => (string) env('RUX_MOD_LOG_CHANNEL_ID', ''),

    // rux reads and writes every time in this zone.
    'timezone' => (string) env('RUX_TIMEZONE', 'Europe/Paris'),

    // Language of what rux posts on Discord (lang/<locale>/strings.php).
    'locale' => (string) env('RUX_LOCALE', 'en'),

    // The AI: the same providers and failover chain as neo, through noprofit.space's neo gateway.
    // Signed with the neo gateway secret unless rux is given its own.
    'gateway_secret' => (string) env('RUX_GATEWAY_SECRET', env('NEO_GATEWAY_SECRET', '')),
    'gateway_timeout' => (int) env('RUX_GATEWAY_TIMEOUT', 90),

    // Whose neo limits pay for rux's automatic checks of watched channels (a noprofit.space
    // account id). Empty: the admin who gave rux its latest order.
    'ai_account_id' => (int) env('RUX_AI_ACCOUNT_ID', 0),

    // Sanity limits on what one sentence can give away.
    'giveaway' => [
        'max_credits' => (int) env('RUX_GIVEAWAY_MAX_CREDITS', 10000),
        'max_winners' => (int) env('RUX_GIVEAWAY_MAX_WINNERS', 25),
        'max_days' => (int) env('RUX_GIVEAWAY_MAX_DAYS', 60),
        'max_exclude_recent' => 10,
    ],

    // The agent loop. Every step re-sends the context, so these decide what one order costs
    // against the admin's neo limits.
    'agent' => [
        'max_steps' => (int) env('RUX_MAX_STEPS', 8),
        'history_minutes' => (int) env('RUX_HISTORY_MINUTES', 30),
        'history_messages' => 24,
        'max_message_chars' => 4000,
        'tool_output_chars' => 6000,
        // How long one order may wait in total for a busy/rate-limited provider.
        'busy_wait_seconds' => 150,
    ],

    // Warnings count for good (until an admin clears them). Reaching timeout_at warnings times
    // the member out for `timeout`, reaching ban_at bans them for `ban`, and every warning past
    // a step repeats it. A step at 0 is off.
    'warnings' => [
        'timeout_at' => (int) env('RUX_WARN_TIMEOUT_AT', 3),
        'timeout' => (string) env('RUX_WARN_TIMEOUT', '24h'),
        'ban_at' => (int) env('RUX_WARN_BAN_AT', 5),
        'ban' => (string) env('RUX_WARN_BAN', '24h'),
    ],

    // Standing rules ("if anyone asks for new locations, warn them"): a keyword hit is checked
    // by the model before rux acts, and one rule acts on one member at most once per cooldown.
    'rules' => [
        'cooldown_seconds' => (int) env('RUX_RULE_COOLDOWN', 300),
        'checks_per_member_per_minute' => 3,
    ],

    // Watched channels (#chat and #bot-commands to begin with; admins add and remove them by
    // telling rux): every member message is checked against the standing rules, and against the
    // always-on guidelines (scams, slurs, harassment, sexual content, spam, advertising) unless
    // this is off.
    'watch' => [
        'guidelines' => (bool) env('RUX_WATCH_GUIDELINES', true),
    ],

    // Orders per Discord user per minute that reach a worker at all.
    'messages_per_minute' => (int) env('RUX_MESSAGES_PER_MINUTE', 6),

    // GUILDS | GUILD_MESSAGES | DIRECT_MESSAGES | MESSAGE_CONTENT. MESSAGE_CONTENT is privileged
    // (Developer Portal → Bot → Message Content Intent): it lets rux read the messages its rules
    // apply to and hear "hey rux, …" without an @mention. When Discord refuses it (close 4014)
    // rux reconnects without it: DMs and @mentions keep working, rules and name calls do not.
    'gateway_intents' => (int) env('RUX_GATEWAY_INTENTS', 1 | 512 | 4096 | 32768),
    'gateway_idle_timeout' => (int) env('RUX_GATEWAY_IDLE_TIMEOUT', 90),
];
