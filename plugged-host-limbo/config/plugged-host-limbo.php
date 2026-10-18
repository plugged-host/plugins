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
    // Move the tag to move limbo: new servers are parked on the newly tagged node, the ones on
    // the old node stay parked there (each server's node is recorded, migration 021).
    'node_tag' => env('LIMBO_NODE_TAG', 'limbo'),

    // No new moves while the limbo node's data disk is used past this percentage (core's node
    // telemetry). The limbo node is also a storage agent's box, and its mirror passes stop when
    // that disk runs low. 0 turns the check off.
    'max_disk_percent' => (int) env('LIMBO_MAX_DISK_PERCENT', 85),

    // Moves to the limbo node in flight at once (and stops per sweep): a move takes minutes and
    // the limbo node is small.
    'max_per_run' => (int) env('LIMBO_MAX_PER_RUN', 3),

    // Apply the storage exemptions (plugged-host-storage's agent EXCLUDES: node_modules, caches,
    // logs, …) before a move: a wings transfer cannot skip them, so they are deleted from the
    // server first and do not come back on restore. ExemptionPruner only deletes a match that is
    // what its pattern means (no source code, no folder named like a file pattern). prune_keep
    // names, and everything under them, are left alone: FiveM's `alpine` is its runtime,
    // `libraries` holds Minecraft loaders' jars, `src` is source code.
    'prune' => (bool) env('LIMBO_PRUNE', true),
    'prune_keep' => ['alpine', 'libraries', 'src'],

    // Ransom. Early = restored within the window after entering limbo; late = after it.
    'cost_early' => (int) env('LIMBO_COST_EARLY', 5),
    'cost_late' => (int) env('LIMBO_COST_LATE', 10),
    'cost_window_hours' => (int) env('LIMBO_COST_WINDOW_HOURS', 72),
];
