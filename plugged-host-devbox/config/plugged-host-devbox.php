<?php

return [
    /*
     * Devboxes: a private Linux box next to a game server for VS Code (Remote-SSH), Plus only.
     * Each is a hidden server Wings runs (egg EGG_UUID, owned by the system user below) with the
     * game server's volume mounted at /server. Needs `allowed_mounts: [/var/lib/pelican/volumes]`
     * in the node's Wings config.yml (docs/deploy/node-scan/update-nodes.mjs --devbox).
     */
    'enabled' => (bool) env('DEVBOX_ENABLED', true),

    // The image Wings runs. A leading ~ makes Wings use a local image without pulling.
    'image' => (string) env('DEVBOX_IMAGE', 'ghcr.io/plugged-host/devbox:2.2'),

    // The account that owns every devbox server; nobody signs in as it.
    'system_user' => (string) env('DEVBOX_SYSTEM_USER', 'ph-devbox'),

    // Where the devbox reaches the panel (boot report, heartbeat, connect codes).
    'panel_url' => rtrim((string) (env('DEVBOX_PANEL_URL') ?: env('APP_URL', 'http://localhost')), '/'),

    // Resources per devbox, on top of the game server's own: cpu in % (100 = one thread), MiB.
    'tiers' => [
        'plus' => [
            'cpu' => (int) env('DEVBOX_PLUS_CPU', 200),
            'memory' => (int) env('DEVBOX_PLUS_MEMORY', 2048),
            'disk' => (int) env('DEVBOX_PLUS_DISK', 5120),
        ],
        'plusplus' => [
            'cpu' => (int) env('DEVBOX_PLUSPLUS_CPU', 400),
            'memory' => (int) env('DEVBOX_PLUSPLUS_MEMORY', 4096),
            'disk' => (int) env('DEVBOX_PLUSPLUS_DISK', 10240),
        ],
    ],

    // Stopped by the panel after this long without a connection (minutes).
    'idle_minutes' => (int) env('DEVBOX_IDLE_MINUTES', 15),

    // Stopped by the panel this long after it was started, connected or not (hours).
    'max_hours' => (int) env('DEVBOX_MAX_HOURS', 12),

    // Devboxes one person may have running at once.
    'running_per_user' => (int) env('DEVBOX_RUNNING_PER_USER', 2),

    // A devbox nobody used for this long is deleted with its home (days).
    'cleanup_days' => (int) env('DEVBOX_CLEANUP_DAYS', 14),
];
