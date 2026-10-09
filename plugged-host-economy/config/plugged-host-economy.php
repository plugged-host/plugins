<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Credits — the resource currency
    |--------------------------------------------------------------------------
    |
    | Credits buy resources, and nothing else. They are earned socially (Discord
    | invites and boosts, see plugged-host-discord) and spent in the resource
    | store on CPU, memory and disk.
    |
    | The env keys keep their historical CREDITS_* names: none of them is set in
    | .env, so every value here is the live default, and renaming them would have
    | been churn with no migration to show for it.
    |
    */
    'credits' => [
        // The CPU, memory and disk caps and prices come from noprofit.space with each
        // account's plan (NoprofitCreditSync); a new account's starting credits are
        // granted there too.
        'enabled' => (bool) env('CREDITS_ENABLED', true),

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

    /*
    |--------------------------------------------------------------------------
    | plugged.host Plus and PlusPlus
    |--------------------------------------------------------------------------
    |
    | Plus is bought once with tokens and kept for good: the Plus locations,
    | priority in the start queue, and no in-game ads on Minecraft servers.
    |
    | PlusPlus needs Plus and runs for a while, paid with tokens or credits, and
    | is bought again to keep it: everything in Plus, the Premium locations, no
    | ads anywhere, the highest priority in the start queue, and cpu_bonus more
    | CPU (in %, on top of the account's allowance).
    |
    | neo (AI) usage: Plus raises the limits to plus_ai_percent of the free ones,
    | PlusPlus by another plusplus_ai_percent on top of Plus (125 x 125 = 156%).
    | noprofit.space meters neo and applies the percentage the panel sends. When it runs out, servers
    | on Premium locations move out and CPU over the allowance comes off the
    | servers, evenly (plugged-host-servers, plusplus:expire).
    |
    | These replaced the queue's priority passes and the one-time Plus/Premium
    | location unlock; whoever had bought the unlock got Plus.
    |
    */
    'membership' => [
        'enabled' => (bool) env('MEMBERSHIP_ENABLED', true),
        'plus_token_cost' => (int) env('PLUS_TOKEN_COST', 25),
        'plusplus_token_cost' => (int) env('PLUSPLUS_TOKEN_COST', 10),
        'plusplus_token_days' => (int) env('PLUSPLUS_TOKEN_DAYS', 7),
        'plusplus_credit_cost' => (int) env('PLUSPLUS_CREDIT_COST', 150),
        'plusplus_credit_days' => (int) env('PLUSPLUS_CREDIT_DAYS', 14),
        'plusplus_cpu_bonus' => (int) env('PLUSPLUS_CPU_BONUS', 50),
        'plus_ai_percent' => (int) env('PLUS_AI_PERCENT', 125),
        'plusplus_ai_percent' => (int) env('PLUSPLUS_AI_PERCENT', 125),
    ],

    /*
     * Plans, when this panel sets them (without plugged-host-noprofit; with it noprofit.space
     * does and these are not used). See Services\LocalComputePlans and Support\ComputePricing.
     *
     * tiers: what an account starts with, the Discord tier while its linked Discord account is
     * in the server. max_*: the limit where a store step costs its full price (no ceiling: past
     * it prices climb along the curve). CPU in %, memory and disk in MB.
     */
    'compute' => [
        'tiers' => [
            'default' => [
                'cpu' => (int) env('COMPUTE_DEFAULT_CPU', 400),
                'memory' => (int) env('COMPUTE_DEFAULT_MEMORY', 12288),
                'disk' => (int) env('COMPUTE_DEFAULT_DISK', 51200),
            ],
            'discord' => [
                'cpu' => (int) env('COMPUTE_DISCORD_CPU', 400),
                'memory' => (int) env('COMPUTE_DISCORD_MEMORY', 16384),
                'disk' => (int) env('COMPUTE_DISCORD_DISK', 51200),
            ],
        ],
        'max_cpu' => (int) env('COMPUTE_MAX_CPU', 600),
        'max_memory' => (int) env('COMPUTE_MAX_MEMORY', 24576),
        'max_disk' => (int) env('COMPUTE_MAX_DISK', 102400),
        'pricing' => [
            'cpu_unit_percent' => (int) env('COMPUTE_CPU_STEP', 50),
            'cpu_price_credits' => (int) env('COMPUTE_CPU_PRICE', 100),
            'memory_unit_mb' => (int) env('COMPUTE_MEMORY_STEP', 1024),
            'memory_price_credits' => (int) env('COMPUTE_MEMORY_PRICE', 150),
            'storage_unit_mb' => (int) env('COMPUTE_DISK_STEP', 5120),
            'storage_price_credits' => (int) env('COMPUTE_DISK_PRICE', 75),
            'curve_floor_percent' => 50,
            'soft_limit_percent' => 125,
            'soft_limit_multiplier' => 4,
            'doubling_percent' => 5,
        ],
        'starting_credits' => (int) env('CREDITS_STARTING_BALANCE', 0),
    ],
];
