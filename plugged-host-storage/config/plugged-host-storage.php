<?php

/*
|--------------------------------------------------------------------------
| plugged-host-storage (WP9)
|--------------------------------------------------------------------------
|
| The storage box: 30-minute delta-mirror replication, a daily panel-visible
| S3 backup, and node-failure recovery. The Node.js agent (agent/) pulls the
| mirrors and serves signed archives; the panel schedules failover detection
| and serves the agent its work-plan, updates, and report sink.
|
| NOTE on failover mode: the operator asked for FULLY AUTOMATIC failover once a
| node is unreachable for ~5 minutes ("node offline > 5 min => auto switch from
| latest backup"). That OVERRIDES the plan's conservative `notify` default, so
| STORAGE_FAILOVER_MODE defaults to `auto` here. The storage-agent second-witness
| probe is used as corroboration when reachable, but the panel's own 5-minute
| offline detection is the trigger. The `failed_over` fence still stops the old
| node restarting the stale copy.
|
*/

return [
    // Shared HMAC secret with the agent (STORAGE_AGENT_SECRET on both sides).
    'agent_secret' => env('STORAGE_AGENT_SECRET', ''),

    // Base URL the agent's inbound HTTP listener is reachable at, e.g. http://10.0.0.9:8687.
    // Used for signed archive URLs (wings pulls from here) and reachability probes.
    'agent_url' => env('STORAGE_AGENT_URL', ''),

    // Signed-URL lifetime (seconds) for archive fetch / reachability probes.
    'signed_ttl' => (int) env('STORAGE_SIGNED_TTL', 900),

    // Replication cadence + retention (mostly informative on the panel side; the agent enforces).
    'mirror_interval_min' => (int) env('STORAGE_MIRROR_INTERVAL_MIN', 30),
    'mirror_snapshots' => (int) env('STORAGE_MIRROR_SNAPSHOTS', 3),
    'min_free_percent' => (float) env('STORAGE_MIN_FREE_PERCENT', 10),

    // The wings default volume base; a real per-node column overrides it where present.
    'daemon_base' => env('STORAGE_DAEMON_BASE', '/var/lib/pelican/volumes'),

    // Daily panel-visible S3 backup (through InitiateBackupService against an S3 BackupHost).
    'daily_backup_enabled' => (bool) env('STORAGE_DAILY_BACKUP', true),
    'daily_backup_hour' => (int) env('STORAGE_DAILY_BACKUP_HOUR', 4),

    // Failover.
    'failover_mode' => env('STORAGE_FAILOVER_MODE', 'auto'),          // off | notify | auto
    'failover_after_failures' => (int) env('STORAGE_FAILOVER_AFTER_FAILURES', 5),
    'failover_max_per_run' => (int) env('STORAGE_FAILOVER_MAX_PER_RUN', 5),

    // Agent auto-update bundle version currently served by the panel.
    'agent_version' => env('STORAGE_AGENT_VERSION', '1.3.2'),
];

