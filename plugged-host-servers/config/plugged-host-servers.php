<?php

/*
|--------------------------------------------------------------------------
| plugged-host-servers
|--------------------------------------------------------------------------
|
| Provisioning: the create page, plans, locations, node capacity, the
| account allowance (the noprofit.space plan) and the network-ports action.
|
| WP1: these two blocks moved out of plugged-host-core unchanged. The env
| variable names are identical (UCS_*, NODES_*), so .env needs no edit.
| The admin settings form for them now lives on this plugin's own tab.
|
*/

return [
    /*
     * Seconds a create or resize waits for the account's provisioning lock (one at a time per
     * account, ServerProvisioner::oneAtATime()) before it gives up as "busy".
     */
    'provision_lock_wait' => (int) env('UCS_PROVISION_LOCK_WAIT', 30),

    'user-creatable-servers' => [
        'database_limit' => (int) env('UCS_DEFAULT_DATABASE_LIMIT', 0),
        'allocation_limit' => (int) env('UCS_DEFAULT_ALLOCATION_LIMIT', 0),
        'backup_limit' => (int) env('UCS_DEFAULT_BACKUP_LIMIT', 0),

        'can_users_update_servers' => (bool) env('UCS_CAN_USERS_UPDATE_SERVERS', true),
        'can_users_delete_servers' => (bool) env('UCS_CAN_USERS_DELETE_SERVERS', false),

        'deployment_tags' => env('UCS_DEPLOYMENT_TAGS', ''),
        'deployment_ports' => env('UCS_DEPLOYMENT_PORTS', ''),
        'allowed_eggs' => env('UCS_ALLOWED_EGGS', ''),

        /*
         * Paid location tiers (WP13). A node tagged with a tier's `tag` (alongside its country
         * code and a deployment tag) belongs to that tier; a node tagged with both is premium.
         * The Plus locations come with Plus, the Premium ones with PlusPlus, both sold by
         * plugged-host-economy (Support\PremiumAccess). All defaults are safe, so none is
         * required.
         */
        'plus' => [
            'tag' => env('UCS_PLUS_TAG', 'plus'),
        ],

        'premium' => [
            'tag' => env('UCS_PREMIUM_TAG', 'premium'),
        ],

        /*
         * Resource presets offered as tiles on the server configuration page. A preset
         * is only selectable when it fits inside the user's remaining role allowance;
         * the "Advanced" toggle always allows custom values within the same allowance.
         */
        'plans' => [
            [
                'id' => 'starter',
                'label' => 'plugged-host-servers::strings.plans.starter',
                'cpu' => 100,
                'memory' => 2048,
                'disk' => 10240,
            ],
            [
                'id' => 'standard',
                'label' => 'plugged-host-servers::strings.plans.standard',
                'cpu' => 200,
                'memory' => 4096,
                'disk' => 20480,
            ],
            [
                'id' => 'pro',
                'label' => 'plugged-host-servers::strings.plans.pro',
                'cpu' => 300,
                'memory' => 8192,
                'disk' => 40960,
            ],
            [
                'id' => 'ultimate',
                'label' => 'plugged-host-servers::strings.plans.ultimate',
                'cpu' => 400,
                'memory' => 12288,
                'disk' => 51200,
            ],
            [
                'id' => 'extreme',
                'label' => 'plugged-host-servers::strings.plans.extreme',
                'cpu' => 500,
                'memory' => 16384,
                'disk' => 76800,
            ],
            [
                'id' => 'master',
                'label' => 'plugged-host-servers::strings.plans.master',
                'cpu' => 600,
                'memory' => 24576,
                'disk' => 102400,
            ],
        ],

        /*
         * Per-game "type" variants, keyed by egg UUID (not id, so the mapping survives
         * re-importing the egg on another panel). Each type names the egg's
         * startup_commands key and docker_images key to deploy with — the user never
         * sees that choice, they just pick the software.
         *
         * An egg absent from this list simply has no type step.
         */
        'catalog' => [
            // Minecraft: Java Edition — plugged-host-minecraft/egg/egg-minecraft-java.json,
            // imported by `php artisan plugged-host:minecraft-setup`.
            '2f6a1c58-9e4b-4d7a-8c31-5b0e7f9a4d62' => [
                'variable' => 'MC_SOFTWARE',
                'types' => [
                    'purpur' => [
                        'label' => 'plugged-host-servers::strings.game_types.purpur.label',
                        'description' => 'plugged-host-servers::strings.game_types.purpur.description',
                        'startup' => 'Jar (Purpur / Fabric)',
                        'image' => 'Java 25',
                    ],
                    'fabric' => [
                        'label' => 'plugged-host-servers::strings.game_types.fabric.label',
                        'description' => 'plugged-host-servers::strings.game_types.fabric.description',
                        'startup' => 'Jar (Purpur / Fabric)',
                        'image' => 'Java 25',
                    ],
                    'neoforge' => [
                        'label' => 'plugged-host-servers::strings.game_types.neoforge.label',
                        'description' => 'plugged-host-servers::strings.game_types.neoforge.description',
                        'startup' => 'Modloader (Forge / NeoForge)',
                        'image' => 'Java 25',
                    ],
                    'forge' => [
                        'label' => 'plugged-host-servers::strings.game_types.forge.label',
                        'description' => 'plugged-host-servers::strings.game_types.forge.description',
                        'startup' => 'Modloader (Forge / NeoForge)',
                        'image' => 'Java 25',
                    ],
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Node storage guard
    |--------------------------------------------------------------------------
    |
    | How full a node's disk has to be, right now, before it takes no new
    | servers. Read by PluggedHost\Servers\Services\NodeCapacity against live
    | wings utilisation (Node::statistics()), never against committed allocation
    | sums. Memory and CPU are the deployment pause's (below) and the start
    | queue's line (START_QUEUE_THRESHOLD_PERCENT), not a setting here.
    |
    | storage_percent — disk above this and the node stops accepting new servers.
    | storage_guard_strict — refuse a node that cannot be measured (default off).
    |
    */
    'nodes' => [
        'storage_percent' => (float) env('NODES_STORAGE_PERCENT', 90),
        'storage_guard_strict' => (bool) env('NODES_STORAGE_GUARD_STRICT', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Deployment pause
    |--------------------------------------------------------------------------
    |
    | PluggedHost\Servers\Services\DeploymentPause, evaluated every minute: a
    | node over the start queue's line (START_QUEUE_THRESHOLD_PERCENT) on
    | memory or CPU takes no new servers, transfers or queue moves until it is
    | back under it. A location whose nodes are all paused shows as Full. Tags
    | and the node's `public` flag are never changed.
    |
    | small_memory_gb / small_threads — a node under either is small, and its
    |     line is small_offset points lower.
    | average_minutes — a paused node opens again once its reading and its
    |     average over this many minutes are both under its line.
    | unreachable_after — readings in a row without an answer before a node
    |     is paused for not answering.
    |
    */
    'deploy_pause' => [
        'enabled' => (bool) env('NODES_DEPLOY_PAUSE_ENABLED', true),
        'small_memory_gb' => (float) env('NODES_DEPLOY_PAUSE_SMALL_MEMORY_GB', 34),
        'small_threads' => (int) env('NODES_DEPLOY_PAUSE_SMALL_THREADS', 9),
        'small_offset' => (float) env('NODES_DEPLOY_PAUSE_SMALL_OFFSET', 10),
        'average_minutes' => (int) env('NODES_DEPLOY_PAUSE_AVERAGE_MINUTES', 5),
        'unreachable_after' => (int) env('NODES_DEPLOY_PAUSE_UNREACHABLE_AFTER', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Node allocation balancing
    |--------------------------------------------------------------------------
    |
    | Balances new server allocations across nodes sharing the same location tag.
    | Rather than allocating randomly or by committed limit sums, it ranks nodes
    | by their rolling 5-minute average of RAM and CPU usage, picking the coolest.
    |
    | window_minutes — rolling window for telemetry samples (default: 5).
    | cpu_weight — relative weight for CPU utilization (default: 1.0).
    | memory_weight — relative weight for memory utilization (default: 1.0).
    |
    */
    'balance' => [
        'enabled' => (bool) env('NODES_BALANCE_ENABLED', true),
        'window_minutes' => (int) env('NODES_BALANCE_WINDOW_MINUTES', 5),
        'cpu_weight' => (float) env('NODES_BALANCE_CPU_WEIGHT', 1.0),
        'memory_weight' => (float) env('NODES_BALANCE_MEMORY_WEIGHT', 1.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Server transfers
    |--------------------------------------------------------------------------
    |
    | Self-service moves to another location (Services\Transfers). The copy is
    | wings' own streaming transfer; everything around it is ours: picking the
    | node inside the location, a clean stop, live progress read from the
    | source node's websocket, retries on another node, and putting the server
    | back online where it was when a move gives up. The source copy is only
    | ever deleted by upstream once the new node has verified its checksum.
    |
    | enabled — owners, and subusers given "transfer", can move their servers.
    | cooldown_hours — minimum time between two finished moves of one server.
    | max_attempts — nodes tried before a move gives up.
    | stop_timeout — seconds a clean stop gets before the server is killed.
    | stall_minutes — no bytes moving for this long while copying = stalled.
    | finalize_minutes — after the last byte, how long the new node may take
    |     to confirm (it may still be pulling the container image).
    | batch_parallel — "Lighten the load" (admin node page): how many of a
    |     batch's moves run at once per source node; the rest wait their turn.
    |
    */
    'transfers' => [
        'enabled' => (bool) env('TRANSFERS_ENABLED', true),
        'cooldown_hours' => (int) env('TRANSFERS_COOLDOWN_HOURS', 6),
        'max_attempts' => (int) env('TRANSFERS_MAX_ATTEMPTS', 3),
        'stop_timeout' => (int) env('TRANSFERS_STOP_TIMEOUT', 90),
        'stall_minutes' => (int) env('TRANSFERS_STALL_MINUTES', 10),
        'finalize_minutes' => (int) env('TRANSFERS_FINALIZE_MINUTES', 20),
        'batch_parallel' => (int) env('TRANSFERS_BATCH_PARALLEL', 2),
    ],
];

