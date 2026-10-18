<?php

/*
 * plugged.host AI: neo, the agentic assistant (the panel chat, and neo on Discord), and rux,
 * the Discord moderation bot.
 *
 * neo's AI providers and usage limits are set on this panel (the `local` block below) unless
 * plugged-host-noprofit is enabled: then noprofit.space holds them and this plugin's settings
 * form edits them there. Either way they are edited in the form.
 */
return [
    // neo's master switch. Ships dark: nothing renders and every entry point refuses until on.
    'enabled' => (bool) env('NEO_ENABLED', false),

    // Agent loop and context budget. Every model call re-sends the context, so these two
    // decide what one question costs against the user's limits.
    'max_steps' => (int) env('NEO_MAX_STEPS', 25),
    'context_tokens' => (int) env('NEO_CONTEXT_TOKENS', 24000),
    'max_message_chars' => 4000,

    // Tool limits.
    'tool_output_chars' => (int) env('NEO_TOOL_OUTPUT_CHARS', 12000),
    'max_read_bytes' => (int) env('NEO_MAX_READ_BYTES', 1048576),
    'max_write_bytes' => (int) env('NEO_MAX_WRITE_BYTES', 262144),
    'console_lines' => (int) env('NEO_CONSOLE_LINES', 80),

    // Per-user request rate limits on top of the token windows (`local.limits`, or noprofit.space's).
    'messages_per_minute' => (int) env('NEO_MESSAGES_PER_MINUTE', 10),
    'tools_per_minute' => (int) env('NEO_TOOLS_PER_MINUTE', 60),

    // Privacy and safety.
    'redact_secrets' => (bool) env('NEO_REDACT_SECRETS', true),
    'always_confirm_destructive' => (bool) env('NEO_ALWAYS_CONFIRM_DESTRUCTIVE', true),
    'retention_days' => (int) env('NEO_RETENTION_DAYS', 30),

    // Where neo sends people for human help: this URL, else the Discord plugin's invite
    // (DISCORD_INVITE_URL); with neither, neo names no link.
    'support_url' => (string) env('NEO_SUPPORT_URL', ''),

    // Contact addresses neo gives for legal complaints (abuse, copyright, illegal content) and
    // for privacy complaints and data requests. Empty: neo names none.
    'legal_email' => (string) env('NEO_LEGAL_EMAIL', ''),
    'privacy_email' => (string) env('NEO_PRIVACY_EMAIL', ''),

    // The AI providers named in neo's consent notice and on its settings page, as free text
    // (e.g. "OpenAI and Anthropic"). Empty: a generic sentence, or with plugged-host-noprofit
    // the providers noprofit.space's chain uses (Support\ProviderDisclosure).
    'provider_disclosure' => (string) env('NEO_PROVIDER_DISCLOSURE', ''),

    // The automatic log check above Minecraft consoles (Services\LogCheck): uploads the server
    // log to mclo.gs and shows what it finds. Never runs while neo is off (NEO_ENABLED).
    'log_check' => [
        'enabled' => (bool) env('NEO_LOG_CHECK_ENABLED', true),
    ],

    /*
     * neo's AI on this panel, used without plugged-host-noprofit (with it, noprofit.space holds
     * all of this and these are ignored). The env names and defaults are noprofit.space's own
     * (accounts config/noprofit.php, `ai`), so a chain moves between the two by copying lines;
     * here they go in the panel's .env, and the settings form writes them.
     *
     * Providers are tried in slot order (Chat\ProviderChain), rux uses the same ones. JSON values
     * (extra headers and request fields) are base64-encoded JSON, because the .env writer's
     * quoting mangles raw JSON; an unset one falls back to the slot default.
     */
    'local' => [
        // Off: every neo turn is refused (rux keeps its AI).
        'enabled' => (bool) env('NEO_GATEWAY_ENABLED', true),

        // Ask each slot once (kept 6 hours) whether the tools of a request reach the model; a slot
        // that drops them gets the tools written into the prompt (Chat\PromptedTools).
        'tools_probe' => (bool) env('NEO_TOOLS_PROBE', true),

        // Provider endpoints must be public https URLs (Chat\EndpointGuard, checked on save).
        'endpoint_guard' => true,

        // Tokens (input + output, every model the same) per subject and anchored window. A user's
        // limits are scaled by Plus and PlusPlus (economy); neo on Discord answers people who have
        // not linked Discord too, with windows of their own at discord_unlinked_percent of these.
        'limits' => [
            'short' => [
                'hours' => (int) env('NEO_SHORT_WINDOW_HOURS', 5),
                'tokens' => (int) env('NEO_SHORT_TOKENS', 3000000),
            ],
            'long' => [
                'days' => (int) env('NEO_LONG_WINDOW_DAYS', 7),
                'tokens' => (int) env('NEO_LONG_TOKENS', 15000000),
            ],
            'discord_unlinked_percent' => (int) env('NEO_DISCORD_UNLINKED_PERCENT', 50),
            // A turn a premium slot answered counts this many times its tokens.
            'premium_multiplier' => (int) env('NEO_PREMIUM_MULTIPLIER', 2),
        ],

        'failover' => [
            'default_cooldown' => (int) env('NEO_DEFAULT_COOLDOWN', 60),
            'auth_cooldown' => 600,
            'max_cooldown' => 900,
            // Every provider down for longer than this is an outage, not a wait (seconds).
            'max_busy_wait' => (int) env('NEO_MAX_BUSY_WAIT', 60),
            // The time budget of one turn across all providers (seconds).
            'deadline' => (int) env('NEO_CHAIN_DEADLINE', 80),
            // Model calls in flight at once, panel-wide (each holds a PHP worker).
            'max_concurrent' => (int) env('NEO_MAX_CONCURRENT', 4),
        ],

        'providers' => (static function (): array {
            $slot = static function (int $slot, array $defaults): array {
                $json = static function (string $key, array $fallback): array {
                    $raw = env($key);

                    if ($raw === null) {
                        return $fallback;
                    }

                    $decoded = json_decode((string) base64_decode((string) $raw, true), true);

                    return is_array($decoded) ? $decoded : [];
                };

                $prefix = "NEO_P{$slot}_";

                return [
                    'enabled' => (bool) env($prefix . 'ENABLED', $defaults['enabled']),
                    'label' => (string) env($prefix . 'LABEL', $defaults['label']),
                    'driver' => (string) env($prefix . 'DRIVER', $defaults['driver']),
                    'endpoint' => (string) env($prefix . 'ENDPOINT', $defaults['endpoint']),
                    'api_key' => (string) env($prefix . 'API_KEY', ''),
                    'model' => (string) env($prefix . 'MODEL', $defaults['model']),
                    'max_tokens' => (int) env($prefix . 'MAX_TOKENS', $defaults['max_tokens']),
                    'timeout' => (int) env($prefix . 'TIMEOUT', $defaults['timeout']),
                    'daily_token_cap' => (int) env($prefix . 'DAILY_TOKEN_CAP', $defaults['daily_token_cap']),
                    'extra_headers' => $json($prefix . 'EXTRA_HEADERS_B64', $defaults['extra_headers']),
                    'extra_body' => $json($prefix . 'EXTRA_BODY_B64', $defaults['extra_body']),
                    // Answers only turns that ask for the premium model (PlusPlus picked it in the chat).
                    'premium' => (bool) env($prefix . 'PREMIUM', $defaults['premium'] ?? false),
                    // Sees images (screenshots go to these slots first). Unset: by driver, Gemini
                    // and Anthropic yes, OpenAI-style no (Chat\ProviderChain::seesImages()).
                    'vision' => env($prefix . 'VISION'),
                ];
            };

            // No provider is set up out of the box: fill the slots in the AI plugin's settings
            // (any OpenAI-compatible, Anthropic or Gemini endpoint and its key). Slot 1 is tried
            // first, the others on failover.
            $blank = fn (string $driver): array => [
                'enabled' => false,
                'label' => '',
                'driver' => $driver,
                'endpoint' => '',
                'model' => '',
                'max_tokens' => 4096,
                'timeout' => 30,
                'daily_token_cap' => 0,
                'extra_headers' => [],
                'extra_body' => [],
            ];

            return [
                1 => $slot(1, $blank('openai')),
                2 => $slot(2, $blank('openai')),
                3 => $slot(3, $blank('anthropic')),
            ];
        })(),
    ],

    /*
     * neo on Discord: account support for anyone on the plugged.host Discord server, by DM,
     * @mention, a reply to neo or "hey neo". Not server support: for a server, neo sends people
     * to the panel's neo. Its own Discord application and token, production panel only (two
     * gateways on one token would answer every message twice).
     *
     * Every answer is metered like neo in the panel (here, or on noprofit.space): a Discord account
     * linked to an account uses that account's limits, an unlinked one gets windows of its own at
     * NEO_DISCORD_UNLINKED_PERCENT of them.
     */
    'neo_bot' => [
        // Ships dark.
        'enabled' => (bool) env('NEO_BOT_ENABLED', false),

        // The neo application's bot token (Discord Developer Portal → neo → Bot).
        'bot_token' => (string) env('NEO_BOT_TOKEN', ''),

        // The Discord server neo answers on (and DMs from its members). Defaults to the
        // Discord plugin's server (DISCORD_GUILD_ID).
        'guild_id' => (string) env('NEO_BOT_GUILD_ID', env('DISCORD_GUILD_ID', '')),

        // The agent loop: only account lookups, so a few steps are plenty.
        'max_steps' => (int) env('NEO_BOT_MAX_STEPS', 6),
        // Earlier messages of the same person in the same channel that neo still remembers.
        'history_minutes' => (int) env('NEO_BOT_HISTORY_MINUTES', 60),
        'history_turns' => 6,
        'max_message_chars' => 2000,
        'tool_output_chars' => 6000,
        // How long one answer may wait in total while every provider is busy.
        'busy_wait_seconds' => 120,

        // Messages per Discord user per minute that reach a worker at all.
        'messages_per_minute' => (int) env('NEO_BOT_MESSAGES_PER_MINUTE', 6),

        // GUILDS | GUILD_MESSAGES | DIRECT_MESSAGES | MESSAGE_CONTENT. MESSAGE_CONTENT is
        // privileged (Developer Portal → Bot → Message Content Intent) and only needed for
        // "hey neo"; DMs, @mentions and replies to neo work without it.
        'gateway_intents' => (int) env('NEO_BOT_GATEWAY_INTENTS', 1 | 512 | 4096 | 32768),
        'gateway_idle_timeout' => 90,
    ],

    /*
     * rux, the Discord moderation and credit giveaway bot.
     *
     * rux is its own Discord application with its own token, so it runs next to the plugged.host
     * bot (plugged-host-discord) rather than inside it. It only ever runs on the production panel:
     * RuxConfig::runnable() also requires APP_ENV=production, so a copy of this plugin on the dev
     * panel stays dark even with a token in its .env (two gateways on one token would answer every
     * message twice).
     *
     * Who may command rux, who is linked, and paying giveaway winners go through
     * plugged-host-discord (DiscordAccounts, DiscordCreditPayouts: the panel's own, or
     * noprofit.space's with plugged-host-noprofit). rux's AI is neo's provider chain, 1:1 (this
     * panel's, or fetched from noprofit.space), called directly: no neo limits apply.
     */
    'rux' => [
        // Master switch. Ships dark.
        'enabled' => (bool) env('RUX_ENABLED', false),

        // The rux application's bot token (Discord Developer Portal → rux → Bot).
        'bot_token' => (string) env('RUX_BOT_TOKEN', ''),

        // The Discord server rux moderates. Defaults to the Discord plugin's server (DISCORD_GUILD_ID).
        'guild_id' => (string) env('RUX_GUILD_ID', env('DISCORD_GUILD_ID', '')),

        // Sites the message checks treat as this host's own (never a scam or an advert), besides
        // the panel's and the status page's host names, the Discord invite and (with
        // plugged-host-noprofit) noprofit.space: comma separated domains, each with its subdomains,
        // e.g. "example.com". Rules\ServerFacts.
        'trusted_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env('RUX_TRUSTED_DOMAINS', ''))))),

        // Where a giveaway goes when the admin names no channel, and where moderation is logged.
        // Both optional.
        'giveaway_channel_id' => (string) env('RUX_GIVEAWAY_CHANNEL_ID', ''),
        'mod_log_channel_id' => (string) env('RUX_MOD_LOG_CHANNEL_ID', ''),

        // rux reads and writes every time in this zone.
        'timezone' => (string) env('RUX_TIMEZONE', 'Europe/Paris'),

        // Language of what rux posts on Discord (lang/<locale>/strings.php, the rux group).
        'locale' => (string) env('RUX_LOCALE', 'en'),

        // Sanity limits on what one sentence can give away.
        'giveaway' => [
            'max_credits' => (int) env('RUX_GIVEAWAY_MAX_CREDITS', 10000),
            'max_winners' => (int) env('RUX_GIVEAWAY_MAX_WINNERS', 25),
            'max_days' => (int) env('RUX_GIVEAWAY_MAX_DAYS', 60),
            'max_exclude_recent' => 10,
        ],

        // Credits rux gives someone (give_credits): only on the order of these Discord users,
        // plugged.host's main admin (who must be an admin of the account system too), at most
        // this many at once.
        'credits' => [
            'granters' => array_values(array_filter(array_map('trim', explode(',', (string) env('RUX_CREDIT_GRANTERS', ''))))),
            'max_per_grant' => (int) env('RUX_CREDIT_MAX', 10000),
        ],

        // The agent loop. Every step re-sends the context, so these decide what one order costs
        // on the AI's API key.
        'agent' => [
            'max_steps' => (int) env('RUX_MAX_STEPS', 8),
            'history_minutes' => (int) env('RUX_HISTORY_MINUTES', 30),
            'history_messages' => 24,
            'max_message_chars' => 4000,
            'tool_output_chars' => 6000,
            // How long one order may wait in total while every provider is busy or rate limited.
            'busy_wait_seconds' => 150,
        ],

        // Warnings count for good (until an admin clears them). Reaching timeout_at warnings
        // times the member out for `timeout`, reaching ban_at bans them for `ban`, and every
        // warning past a step repeats it. A step at 0 is off.
        'warnings' => [
            'timeout_at' => (int) env('RUX_WARN_TIMEOUT_AT', 3),
            'timeout' => (string) env('RUX_WARN_TIMEOUT', '24h'),
            'ban_at' => (int) env('RUX_WARN_BAN_AT', 5),
            'ban' => (string) env('RUX_WARN_BAN', '24h'),
        ],

        // Standing rules ("if anyone asks for new locations, warn them"): a keyword hit is
        // checked by the model before rux acts, and one rule acts on one member at most once
        // per cooldown.
        'rules' => [
            'cooldown_seconds' => (int) env('RUX_RULE_COOLDOWN', 300),
            'checks_per_member_per_minute' => 3,
        ],

        // Watched channels (#chat and #bot-commands to begin with; admins add and remove them by
        // telling rux): every member message is checked against the standing rules, and against
        // the always-on guidelines (scams, slurs, harassment, sexual content, advertising)
        // unless this is off.
        'watch' => [
            'guidelines' => (bool) env('RUX_WATCH_GUIDELINES', true),
        ],

        // Spam is caught without AI, in every channel rux can read (SpamGuard): a member's
        // message is spam from the flood_messages-th within flood_seconds, from the duplicates-th
        // copy of the same text within duplicate_seconds, or with mentions or more people and
        // roles pinged, or emoji or more emoji. The spam is deleted, and the member is warned
        // once per rules.cooldown_seconds.
        'spam' => [
            'enabled' => (bool) env('RUX_SPAM', true),
            'flood_messages' => (int) env('RUX_SPAM_FLOOD_MESSAGES', 7),
            'flood_seconds' => (int) env('RUX_SPAM_FLOOD_SECONDS', 10),
            'duplicates' => (int) env('RUX_SPAM_DUPLICATES', 3),
            'duplicate_seconds' => (int) env('RUX_SPAM_DUPLICATE_SECONDS', 120),
            'mentions' => (int) env('RUX_SPAM_MENTIONS', 6),
            'emoji' => (int) env('RUX_SPAM_EMOJI', 25),
        ],

        // Orders per Discord user per minute that reach a worker at all.
        'messages_per_minute' => (int) env('RUX_MESSAGES_PER_MINUTE', 6),

        // GUILDS | GUILD_MESSAGES | DIRECT_MESSAGES | MESSAGE_CONTENT. MESSAGE_CONTENT is
        // privileged (Developer Portal → Bot → Message Content Intent): it lets rux read the
        // messages its rules apply to and hear "hey rux, …" without an @mention. When Discord
        // refuses it (close 4014) rux reconnects without it: DMs and @mentions keep working,
        // rules and name calls do not.
        'gateway_intents' => (int) env('RUX_GATEWAY_INTENTS', 1 | 512 | 4096 | 32768),
        'gateway_idle_timeout' => (int) env('RUX_GATEWAY_IDLE_TIMEOUT', 90),
    ],
];
