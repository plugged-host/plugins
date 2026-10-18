<?php

return [
    /*
     * Devboxes: a private Linux box next to a game server for VS Code (Remote-SSH), Plus only.
     * Each is a hidden server Wings runs (egg EGG_UUID, owned by the system user below) with the
     * game server's volume mounted at /server. Needs the node's data directory (Wings'
     * system.data, by default /var/lib/pelican/volumes) in `allowed_mounts` of the node's Wings
     * config.yml and a Wings restart (https://docs.plugged.host/nodes/devboxes/), the node
     * firewall from the plugin's README, and plugged-host-abuse enabled. Off until switched on.
     */
    'enabled' => (bool) env('DEVBOX_ENABLED', false),

    // The image Wings runs. Empty: this release's tag, ghcr.io/plugged-host/devbox:<VERSION>
    // (e.g. 2.2.0, published by CI). A digest (ghcr.io/plugged-host/devbox@sha256:…) pins one
    // exact build; a leading ~ makes Wings use a local image without pulling. Devboxes pick a
    // change up at their next start.
    'image' => (string) env('DEVBOX_IMAGE', ''),

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

    // Devboxes one person may have running at once: their own, and the shared ones on servers
    // they own (they pay for those).
    'running_per_user' => (int) env('DEVBOX_RUNNING_PER_USER', 2),

    // A devbox nobody used for this long is deleted with its home (days).
    'cleanup_days' => (int) env('DEVBOX_CLEANUP_DAYS', 14),

    /*
     * VS Code in the browser. Each devbox answers at https://<slug>.<web_domain> through the edge
     * (nginx on the panel box, see https://docs.plugged.host/nodes/devboxes/), which asks this
     * panel who may in. Empty switches the web version off. Never the panel's own domain or one
     * under it (DevboxWeb::allowedDomain()): the panel's session cookie reaches those hosts.
     */
    'web_domain' => strtolower(trim((string) env('DEVBOX_WEB_DOMAIN', ''), '. ')),

    // Public IPv4 of the edge; each devbox's DNS record (<slug>.<web_domain>, proxied) points there.
    // One level under the domain: Cloudflare's free edge certificate covers *.<domain> only.
    'web_origin_ip' => (string) env('DEVBOX_WEB_ORIGIN_IP', ''),

    // Cloudflare API token for those records (Zone DNS edit): the subdomains plugin's token when
    // that plugin is installed, otherwise this one.
    'cloudflare_token' => (string) env('DEVBOX_CLOUDFLARE_TOKEN', ''),

    // The devbox CA and the edge's client certificate (nginx reads edge.crt/edge.key/ca.crt here).
    'pki_path' => (string) env('DEVBOX_PKI_PATH', storage_path('app/private/plugged-host-devbox')),
];
