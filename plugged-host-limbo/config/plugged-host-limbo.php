<?php

/*
|--------------------------------------------------------------------------
| plugged-host-limbo (WP5)
|--------------------------------------------------------------------------
|
| Idle-server hibernation. After a period with no panel activity a server is
| stopped, then moved to the limbo node; the owner pays a token ransom to
| restore it. Ships DARK: LIMBO_ENABLED defaults false.
|
*/

return [
    // Global kill switch. The sweep is a no-op while false, so the plugin ships dark.
    'enabled' => (bool) env('LIMBO_ENABLED', false),

    // The thresholds, in hours since last panel activity.
    'stop_hours' => (int) env('LIMBO_STOP_HOURS', 48),
    'transfer_hours' => (int) env('LIMBO_TRANSFER_HOURS', 72),

    // The limbo node is matched by this tag (must NOT be a country code, and must be excluded
    // from UCS_DEPLOYMENT_TAGS). Owned by plugged-host-storage::StorageService::limboNode().
    'node_tag' => env('LIMBO_NODE_TAG', 'limbo'),

    // Moves to the limbo node in flight at once (and stops per sweep): a move takes minutes and
    // the limbo node is small.
    'max_per_run' => (int) env('LIMBO_MAX_PER_RUN', 3),

    // Apply the storage exemptions (plugged-host-storage's agent EXCLUDES: node_modules, caches,
    // logs, …) before a move: a wings transfer cannot skip them, so they are deleted from the
    // server first and do not come back on restore. prune_keep patterns, and everything under
    // them, are left alone (FiveM's `alpine` is its runtime; without it the server cannot start).
    'prune' => (bool) env('LIMBO_PRUNE', true),
    'prune_keep' => ['alpine'],

    // Ransom. Early = restored within the window after entering limbo; late = after it.
    'cost_early' => (int) env('LIMBO_COST_EARLY', 5),
    'cost_late' => (int) env('LIMBO_COST_LATE', 10),
    'cost_window_hours' => (int) env('LIMBO_COST_WINDOW_HOURS', 72),
];
