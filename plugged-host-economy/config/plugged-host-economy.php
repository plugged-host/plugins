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
];
