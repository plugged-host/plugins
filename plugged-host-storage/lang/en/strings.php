<?php

return [
    'settings' => [
        // Agent connection.
        'section_agent' => 'Storage Agent',
        'section_agent_hint' => 'Connection to the Node.js storage agent that pulls mirrors and serves signed archives.',
        'agent_url' => 'Agent URL',
        'setup_section' => 'Set up a storage box',
        'setup_section_hint' => 'Run this command on the storage box as root. It installs or upgrades the agent, asks a few questions, and tells this panel the rest. The code in it works once, for an hour.',
        'setup_command' => 'Setup command',
        'agent_url_hint' => 'Base URL the agent inbound listener is reachable at, e.g. http://10.0.0.9:8687. Used for signed archive URLs and reachability probes.',
        'mirror_public_key' => 'Mirror SSH public key',
        'mirror_public_key_hint' => 'The storage box\'s ph-mirror key, printed by its install.sh. Every Wings node authorizes it with: curl -fsSL :url | sudo bash',
        'agent_secret' => 'Agent Secret (HMAC)',
        'signed_ttl' => 'Signed URL TTL (seconds)',
        'signed_ttl_hint' => 'Lifetime of signed archive-fetch and probe URLs, in seconds.',
        'agent_version' => 'Agent Bundle Version',
        'agent_version_hint' => 'The plugins release and the start of the agent code\'s hash, served to the agents, which update themselves when it changes.',

        // Secret handling (shared strings).
        'secret_unchanged' => 'leave blank to keep current',
        'secret_unset' => 'not set',
        'secret_set_help' => 'A secret is stored. Enter a new value only to replace it; leaving this blank keeps the current one.',
        'secret_unset_help' => 'No secret is stored yet. Enter one to enable authenticated agent traffic.',

        // Replication.
        'section_replication' => 'Replication',
        'section_replication_hint' => 'Delta-mirror cadence and retention. The panel schedules; the agent enforces.',
        'mirror_interval_min' => 'Mirror Interval (minutes)',
        'mirror_interval_min_hint' => 'How often delta mirrors run, in minutes.',
        'mirror_snapshots' => 'Mirror Snapshots Retained',
        'mirror_snapshots_hint' => 'Number of rolling mirror snapshots to keep.',
        'min_free_percent' => 'Minimum Free Space (%)',
        'min_free_percent_hint' => 'Refuse to replicate when free disk falls below this percentage.',
        'daemon_base' => 'Daemon Volume Base',
        'daemon_base_hint' => 'Default wings volume base path; a per-node column overrides it where present.',

        // Backup / S3.
        'section_backup' => 'Daily Backup',
        'section_backup_hint' => 'Daily panel-visible S3 backup through the backup service against an S3 backup host.',
        'daily_backup_enabled' => 'Enable Daily Backup',
        'daily_backup_enabled_hint' => 'Run the panel-visible daily S3 backup.',
        'daily_backup_hour' => 'Daily Backup Hour (0-23)',
        'daily_backup_hour_hint' => 'Hour of day the daily backup runs, in 24-hour local time.',

        // Failover.
        'section_failover' => 'Failover',
        'section_failover_hint' => 'Node-failure recovery. Auto switches from the latest backup once a node is offline past the detection window.',
        'failover_mode' => 'Failover Mode',
        'failover_mode_hint' => 'Off disables recovery; Notify only alerts; Auto switches from the latest backup automatically.',
        'failover_mode_off' => 'Off',
        'failover_mode_notify' => 'Notify only',
        'failover_mode_auto' => 'Automatic',
        'failover_after_failures' => 'Failover After N Failures',
        'failover_after_failures_hint' => 'Consecutive failed probes before a node is considered failed.',
        'failover_max_per_run' => 'Max Failovers Per Run',
        'failover_max_per_run_hint' => 'Safety cap on how many nodes may fail over in a single scheduler run.',
    ],
];
