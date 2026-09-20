<?php

/*
|--------------------------------------------------------------------------
| plugged-host-status
|--------------------------------------------------------------------------
|
| The public status pipeline: collect (every minute), evaluate, export the
| data/*.json + feed.xml the static status site reads, and prune old rows.
|
| WP15: these keys used to live under plugged-host-core.status.*. They moved
| here unchanged in spelling — the env variable names are identical, so .env
| needs no edit. Defaults are neutral for open-source publishing; the
| operator sets the real values in .env.
|
*/

return [
    // Where the JSON/RSS artifacts are written. Blank => export degrades to a
    // logged-once no-op and the collector keeps storing snapshots (WP15).
    'export_path' => env('STATUS_EXPORT_PATH', ''),

    // Public status site URL, used only in the RSS <link>. Neutral by default.
    'rss_base_url' => env('STATUS_RSS_BASE_URL', ''),

    // Endpoint probes.
    'site_health_url' => env('STATUS_SITE_HEALTH_URL', 'http://127.0.0.1/healthz'),
    'site_health_host' => env('STATUS_SITE_HEALTH_HOST', ''),
    'panel_health_url' => env('STATUS_PANEL_HEALTH_URL', ''),

    // Retention + node pressure classification.
    'history_days' => (int) env('STATUS_HISTORY_DAYS', 90),
    'resource_threshold_percent' => (float) env('STATUS_RESOURCE_THRESHOLD_PERCENT', 90),
];
