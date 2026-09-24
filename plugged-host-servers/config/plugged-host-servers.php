<?php

/*
|--------------------------------------------------------------------------
| plugged-host-servers
|--------------------------------------------------------------------------
|
| Provisioning: the create/configure pages, plans, locations, node capacity,
| role resource limits and the network-ports action.
|
| WP1: these two blocks moved out of plugged-host-core unchanged. The env
| variable names are identical (UCS_*, NODES_*), so .env needs no edit.
| The admin settings form for them now lives on this plugin's own tab.
|
*/

return [
    'user-creatable-servers' => [
        'default_limits_enabled' => (bool) env('UCS_DEFAULT_LIMITS_ENABLED', false),
        'default_cpu' => (int) env('UCS_DEFAULT_CPU', 0),
        'default_memory' => (int) env('UCS_DEFAULT_MEMORY', 0),
        'default_disk' => (int) env('UCS_DEFAULT_DISK', 0),
        'default_server_limit' => filled(env('UCS_DEFAULT_SERVER_LIMIT'))
            ? (int) env('UCS_DEFAULT_SERVER_LIMIT')
            : null,

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
         * code and a deployment tag) belongs to that tier; one token purchase of `cost` unlocks
         * the whole tier for a user, permanently. Premium ranks above Plus: a node tagged with
         * both is premium, and unlocking premium also unlocks Plus. All defaults are safe, so
         * none is required.
         */
        'plus' => [
            'tag' => env('UCS_PLUS_TAG', 'plus'),
            'cost' => (int) env('UCS_PLUS_COST', 20),
        ],

        'premium' => [
            'tag' => env('UCS_PREMIUM_TAG', 'premium'),
            'cost' => (int) env('UCS_PREMIUM_COST', 40),
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
            // Minecraft: Java Edition — see /var/www/javaegg.json
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
    | Node pressure thresholds
    |--------------------------------------------------------------------------
    |
    | How full a node has to be, right now, before we treat it as full. Read by
    | PluggedHost\Servers\Services\NodeCapacity against live wings utilisation
    | (Node::statistics()), never against committed allocation sums.
    |
    | hot_percent — memory, CPU *or* disk above this and the node is hot, which
    | is what sends a start into the queue instead of straight through.
    | storage_percent — disk above this and the node stops accepting new servers.
    | storage_guard_strict — refuse a node that cannot be measured (default off).
    |
    */
    'nodes' => [
        'hot_percent' => (float) env('NODES_HOT_PERCENT', 95),
        'storage_percent' => (float) env('NODES_STORAGE_PERCENT', 90),
        'storage_guard_strict' => (bool) env('NODES_STORAGE_GUARD_STRICT', false),
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
];

