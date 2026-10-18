<?php

/*
|--------------------------------------------------------------------------
| plugged-host-storage (WP9)
|--------------------------------------------------------------------------
|
| The storage box (and optionally the backup server): 30-minute delta-mirror
| replication and node-failure recovery. The Node.js agent (agent/) pulls the
| mirrors and serves signed archives; the panel schedules failover detection and
| serves each agent its work-plan, updates, and report sink.
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

    // The storage box's ph-mirror public key (its install.sh prints it). Wings nodes authorize it
    // with /plugged-host/storage/node/install.sh, so the agent can pull server files over SSH.
    'mirror_public_key' => (string) env('STORAGE_MIRROR_PUBLIC_KEY', ''),

    // The addresses the storage box reaches the nodes from (comma-separated IPs or CIDRs). Set, the
    // node installer pins its key to them (authorized_keys from=), so the key only works from there.
    'mirror_from' => (string) env('STORAGE_MIRROR_FROM', ''),

    // The backup server (optional): a second box running the same agent, faster than the storage
    // box but less stable. Both copy every server; downloads and failover restores come from the
    // backup server while its copy is as fresh as the storage box's (StorageService::servingBox).
    // Set up with: php artisan plugged-host:storage-setup --backup
    'backup_agent_url' => env('STORAGE_BACKUP_AGENT_URL', ''),
    'backup_agent_secret' => env('STORAGE_BACKUP_AGENT_SECRET', ''),
    'backup_mirror_public_key' => (string) env('STORAGE_BACKUP_MIRROR_PUBLIC_KEY', ''),
    'backup_mirror_from' => (string) env('STORAGE_BACKUP_MIRROR_FROM', ''),

    // Signed-URL lifetime (seconds) for archive fetch / reachability probes.
    'signed_ttl' => (int) env('STORAGE_SIGNED_TTL', 900),

    // Replication cadence + retention, enforced by the agent on each box. The snapshot count goes
    // out with every work-plan; the interval and the free-space floor are written into the box's
    // agent config by its installer, so a change reaches a box when its setup command is run
    // there again. The panel also uses the interval itself (doctor, which box serves an archive).
    'mirror_interval_min' => (int) env('STORAGE_MIRROR_INTERVAL_MIN', 30),
    'mirror_snapshots' => (int) env('STORAGE_MIRROR_SNAPSHOTS', 3),
    'min_free_percent' => (float) env('STORAGE_MIN_FREE_PERCENT', 10),

    // The wings default volume base; a real per-node column overrides it where present.
    'daemon_base' => env('STORAGE_DAEMON_BASE', '/var/lib/pelican/volumes'),

    // A server deleted by an admin (or by the panel itself) keeps its copy on the storage box
    // this many days, listed for its owner on the backups portal. Owners' own deletions: none.
    'deleted_hold_days' => (int) env('STORAGE_DELETED_HOLD_DAYS', 7),
    // Kill switch for the agent's cleanup of copies whose server is gone (holds are still recorded).
    'purge_deleted' => (bool) env('STORAGE_PURGE_DELETED', true),

    // Failover.
    'failover_mode' => env('STORAGE_FAILOVER_MODE', 'auto'),          // off | notify | auto
    'failover_after_failures' => (int) env('STORAGE_FAILOVER_AFTER_FAILURES', 5),
    'failover_max_per_run' => (int) env('STORAGE_FAILOVER_MAX_PER_RUN', 5),
    'failover_max_in_flight' => (int) env('STORAGE_FAILOVER_MAX_IN_FLIGHT', 3), // recoveries installing/restoring at once (the agent builds 3 archives at a time)

    // Agent auto-update bundle version currently served by the panel.
    // Normally empty: the version is the plugins release + the agent code's hash (AgentRelease).
    // Set STORAGE_AGENT_VERSION only to force one.
    'agent_version' => env('STORAGE_AGENT_VERSION'),
];

