<?php

/*
 * neo, the plugged.host support AI.
 *
 * Only panel-side settings live here. Provider keys, the failover chain and the 5-hour /
 * weekly usage limits belong to noprofit.space and are edited through this plugin's
 * settings form, which reads and writes them over the signed internal API.
 */
return [
    // Master switch. Ships dark: nothing renders and every entry point refuses until on.
    'enabled' => (bool) env('NEO_ENABLED', false),

    // Signs every call to noprofit.space's /api/v1/internal/neo/* (must match its .env).
    'gateway_secret' => (string) env('NEO_GATEWAY_SECRET', ''),
    'gateway_timeout' => (int) env('NEO_GATEWAY_TIMEOUT', 90),

    // Agent loop and context budget. Every model call re-sends the context, so these two
    // decide what one question costs against the user's limits.
    'max_steps' => (int) env('NEO_MAX_STEPS', 10),
    'context_tokens' => (int) env('NEO_CONTEXT_TOKENS', 16000),
    'max_message_chars' => 4000,

    // Tool limits.
    'tool_output_chars' => (int) env('NEO_TOOL_OUTPUT_CHARS', 12000),
    'max_read_bytes' => (int) env('NEO_MAX_READ_BYTES', 1048576),
    'max_write_bytes' => (int) env('NEO_MAX_WRITE_BYTES', 262144),
    'console_lines' => (int) env('NEO_CONSOLE_LINES', 80),

    // Per-user request rate limits on top of noprofit.space's token windows.
    'messages_per_minute' => (int) env('NEO_MESSAGES_PER_MINUTE', 10),
    'tools_per_minute' => (int) env('NEO_TOOLS_PER_MINUTE', 60),

    // Privacy and safety.
    'redact_secrets' => (bool) env('NEO_REDACT_SECRETS', true),
    'always_confirm_destructive' => (bool) env('NEO_ALWAYS_CONFIRM_DESTRUCTIVE', false),
    'retention_days' => (int) env('NEO_RETENTION_DAYS', 30),

    'support_url' => (string) env('NEO_SUPPORT_URL', 'https://discord.gg/pluggedhost'),
];
