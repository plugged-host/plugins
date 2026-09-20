<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Credits — the resource currency
    |--------------------------------------------------------------------------
    |
    | Credits buy resources, and nothing else. They are earned socially (Discord
    | invites and boosts, see plugged-host-discord) and spent in the resource
    | store on CPU, memory, disk and server slots.
    |
    | The env keys keep their historical CREDITS_* names: none of them is set in
    | .env, so every value here is the live default, and renaming them would have
    | been churn with no migration to show for it.
    |
    */
    'credits' => [
        'enabled' => (bool) env('CREDITS_ENABLED', true),
        'starting_balance' => (int) env('CREDITS_STARTING_BALANCE', 0),

        /*
         * Maximum total resource limits attainable via role and credit upgrades.
         * Defaults: 600% CPU, 24 GB (24576 MB) RAM, 100 GB (102400 MB) Disk.
         */
        'max_cpu' => (int) env('CREDITS_MAX_TOTAL_CPU', 600),
        'max_memory' => (int) env('CREDITS_MAX_TOTAL_MEMORY', 24576),
        'max_disk' => (int) env('CREDITS_MAX_TOTAL_DISK', 102400),

        /*
         * Pricing rates in credits for resource upgrades.
         */
        'price_cpu_50' => (int) env('CREDITS_PRICE_CPU_50', 100),
        'price_memory_1024' => (int) env('CREDITS_PRICE_MEMORY_1024', 150),
        'price_disk_5120' => (int) env('CREDITS_PRICE_DISK_5120', 75),
        'price_server_slot' => (int) env('CREDITS_PRICE_SERVER_SLOT', 250),

        /*
         * When a reward is clawed back (a Discord invite that left the server) and the
         * balance cannot cover it, repossess purchased resources at the same store rate
         * they were bought at — but only out of headroom no server is using. Turn this
         * off to leave resources alone and carry the whole remainder as debt instead.
         *
         * See PluggedHost\Economy\Services\CreditRecoveryService.
         */
        'reclaim_resources' => (bool) env('CREDITS_RECLAIM_RESOURCES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tokens — the priority currency
    |--------------------------------------------------------------------------
    |
    | Tokens are the second currency and they are deliberately not credits: they
    | are earned by watching ads (Linkvertise, see plugged-host-ads) and spent on
    | priority in the start queue. Credits grow the Discord server, tokens pay for
    | the hardware, so the two must never be exchangeable — a single currency would
    | let one of those goals fund the other and neither would be measurable.
    |
    | Tokens used to be "start credits" and used to buy a way past the start-gate
    | ad. That mechanic is gone: an ad currency that removes ads pays the host
    | nothing. The ledger tables were renamed rather than recreated, so every
    | balance earned under the old name carried over.
    |
    | daily_grant is a free token per user per day, handed out lazily the first
    | time their balance is read. It defaults to 0 — at 1/day it would fund a free
    | priority pass every fortnight, which is the whole point of the pass.
    |
    */
    'tokens' => [
        'enabled' => (bool) env('TOKENS_ENABLED', true),
        'daily_grant' => (int) env('TOKENS_DAILY_GRANT', 0),
    ],
];
