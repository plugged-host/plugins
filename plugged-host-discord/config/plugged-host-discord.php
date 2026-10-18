<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Discord Bot Token
    |--------------------------------------------------------------------------
    |
    | The bot token used to query the Discord REST API for guild membership.
    | The bot must be present in the server and have "Server Members Intent"
    | enabled in the Discord Developer Portal.
    |
    */
    'bot_token' => env('DISCORD_BOT_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Discord Guild (Server) ID
    |--------------------------------------------------------------------------
    |
    | The snowflake ID of the Discord guild/server to check membership against.
    |
    */
    'guild_id' => env('DISCORD_GUILD_ID', ''),

    /*
    |--------------------------------------------------------------------------
    | Panel Role Name
    |--------------------------------------------------------------------------
    |
    | The name of the role inside the Pelican panel that is assigned to users
    | when their Discord is linked and they are verified members of the Discord
    | server.
    |
    | What members get in CPU, memory and disk is the Discord tier on
    | noprofit.space; a role only decides the server limit (Admin -> Role
    | Server Limits).
    |
    */
    'role_name' => env('DISCORD_LINKED_ROLE_NAME', 'Discord Linked'),

    /*
    |--------------------------------------------------------------------------
    | Discord Server Invite URL
    |--------------------------------------------------------------------------
    |
    | The invite URL presented to users who have linked their Discord account
    | but are not yet members of the server. Empty: no invite link or button is
    | shown anywhere.
    |
    */
    'invite_url' => (string) env('DISCORD_INVITE_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Auto Sync on Login
    |--------------------------------------------------------------------------
    |
    | When enabled, users with a linked Discord account will have their Discord
    | server membership checked upon each panel login.
    |
    */
    'sync_on_login' => env('DISCORD_SYNC_ON_LOGIN', true),

    /*
    |--------------------------------------------------------------------------
    | Remove Role on Server Leave / Unlink
    |--------------------------------------------------------------------------
    |
    | When enabled, if a user unlinks their Discord account or leaves the
    | Discord server, the panel role is automatically removed.
    |
    */
    'unlinked_remove_role' => env('DISCORD_UNLINK_REMOVE_ROLE', true),

    /*
    |--------------------------------------------------------------------------
    | Discord Invite & Boost Rewards
    |--------------------------------------------------------------------------
    |
    | Credits awarded to users for inviting members and boosting the Discord server.
    | - Invite reward: balanced credit amount per active invite tracked.
    | - Boost 1 reward: equivalent to around 10 invites.
    | - Boost 2 reward: equivalent to around 25 invites.
    |
    */
    'credits_per_invite' => (int) env('DISCORD_CREDITS_PER_INVITE', 50),
    'credits_per_boost_1' => (int) env('DISCORD_CREDITS_PER_BOOST_1', 500),
    'credits_per_boost_2' => (int) env('DISCORD_CREDITS_PER_BOOST_2', 1250),
    'boost_2_role_id' => env('DISCORD_BOOST_2_ROLE_ID', ''),

    /*
    |--------------------------------------------------------------------------
    | Minimum Account Age for Invites
    |--------------------------------------------------------------------------
    |
    | Minimum age (in days) that a Discord user account must have to count
    | towards invite rewards. Accounts younger than this are disqualified as alts.
    |
    */
    'min_account_age_days' => (int) env('DISCORD_INVITE_MIN_ACCOUNT_AGE_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Invite Reward Escrow
    |--------------------------------------------------------------------------
    |
    | How long an invited member must stay in the Discord server before the
    | inviter is actually paid. Until then the join is tracked as pending and
    | counts toward the invite total, but no credits move — which stops the
    | join/leave farming loop from paying out at all in the common case.
    |
    | Set to 0 to award immediately (the old behaviour).
    |
    */
    'invite_maturity_hours' => (int) env('DISCORD_INVITE_MATURITY_HOURS', 6),

    /*
    |--------------------------------------------------------------------------
    | Invite Reward Clawback
    |--------------------------------------------------------------------------
    |
    | When an already-rewarded member leaves the server, reclaim the credits.
    | Recovery cascades: balance first, then purchased resources sold back at
    | the store rate out of unused headroom, then the remainder is carried as
    | `discord_links.invite_debt` and taken out of future rewards.
    |
    | See PluggedHost\Economy\Services\CreditRecoveryService.
    |
    */
    'invite_clawback' => (bool) env('DISCORD_INVITE_CLAWBACK', true),

    /*
    |--------------------------------------------------------------------------
    | Gateway Intents
    |--------------------------------------------------------------------------
    |
    | 1  GUILDS         — required for the gateway connection and slash commands
    | 2  GUILD_MEMBERS  — PRIVILEGED. Required for GUILD_MEMBER_ADD/REMOVE, which
    |                     is how a join is attributed to an invite code and how a
    |                     leave is noticed in real time.
    | 64 GUILD_INVITES  — INVITE_CREATE/INVITE_DELETE, to keep the code cache warm.
    |
    | 67 = all three. GUILD_MEMBERS must also be switched on under
    | "Privileged Gateway Intents" in the Discord Developer Portal, or the
    | gateway closes the connection with code 4014 — the bot then falls back to
    | intents 1 and leave detection relies solely on the hourly REST reconcile.
    |
    */
    'gateway_intents' => (int) env('DISCORD_GATEWAY_INTENTS', 67),

    /*
    |--------------------------------------------------------------------------
    | Gateway Idle Timeout
    |--------------------------------------------------------------------------
    |
    | Seconds of complete gateway silence after which the daemon drops the socket
    | and reconnects. Discord ACKs our heartbeat about every 41 seconds, so a
    | longer gap means the connection is dead in a way that never surfaces as EOF.
    |
    */
    'gateway_idle_timeout' => (int) env('DISCORD_GATEWAY_IDLE_TIMEOUT', 90),

    /*
    |--------------------------------------------------------------------------
    | Gateway Daemon Supervision
    |--------------------------------------------------------------------------
    |
    | The daemon is kept alive by the plugin itself: the scheduler runs
    | `plugged-host:discord-bot-supervise` every minute, which starts the daemon
    | when nothing holds its lock. Nothing outside /var/www is involved beyond
    | the `schedule:run` cron entry Laravel already needs, so a host rebuild
    | cannot take the bot with it.
    |
    | - enabled            master switch for the automatic restarts
    | - auto_install_trigger  reinstall the `schedule:run` crontab line by itself
    |                     when nothing has ticked for three minutes — the crontab
    |                     is the only piece of this that a host rebuild can lose
    | - heartbeat_interval how often the daemon records that it is alive
    | - heartbeat_timeout  silence after which it is considered wedged and replaced
    | - max_backoff        ceiling on the delay between repeated failed starts
    | - log_max_bytes      rotate storage/logs/discord-bot.log past this size
    | - php_binary         override the interpreter used to spawn the daemon
    | - run_as             the user the daemon must run as; never root
    |
    | See PluggedHost\Discord\Support\DiscordBotSupervisor and
    | PluggedHost\Discord\Providers\DiscordSupervisionRepairProvider.
    |
    */
    'supervisor' => [
        'enabled' => (bool) env('DISCORD_BOT_SUPERVISOR_ENABLED', true),
        'auto_install_trigger' => (bool) env('DISCORD_BOT_AUTO_INSTALL_TRIGGER', true),
        'heartbeat_interval' => (int) env('DISCORD_BOT_HEARTBEAT_INTERVAL', 15),
        'heartbeat_timeout' => (int) env('DISCORD_BOT_HEARTBEAT_TIMEOUT', 120),
        'max_backoff' => (int) env('DISCORD_BOT_RESTART_MAX_BACKOFF', 900),
        'log_max_bytes' => (int) env('DISCORD_BOT_LOG_MAX_BYTES', 8388608),
        'php_binary' => env('DISCORD_BOT_PHP_BINARY', ''),
        'run_as' => env('DISCORD_BOT_RUN_AS', 'www-data'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Discord Bot Public Key (Optional for HTTP Interactions)
    |--------------------------------------------------------------------------
    |
    | The public key from Discord Developer Portal used to verify Ed25519
    | signatures if using HTTP Interactions instead of Gateway WebSocket.
    |
    */
    'public_key' => env('DISCORD_PUBLIC_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Act on the Discord server from a non-production panel
    |--------------------------------------------------------------------------
    |
    | Off by default: only the production panel connects the gateway, registers
    | slash commands and gives or takes roles. A dev panel sharing production's
    | bot token would otherwise change real members' roles from its own data.
    | Turn on only with this panel's own Discord application.
    | See PluggedHost\Discord\Support\DiscordEnvironment.
    |
    */
    'allow_non_production' => (bool) env('DISCORD_ALLOW_NON_PRODUCTION', false),
];


