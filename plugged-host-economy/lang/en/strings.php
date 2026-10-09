<?php

/*
    plugged.host Economy — English.

    The `credits` block moved here verbatim from plugged-host-core when the economy
    was split out, keys and all, so every `plugged-host-economy::strings.credits.*`
    call reads the same copy it always did. Do not renumber or rename those keys
    without grepping every plugin for them first.

    The `tokens` block is new. Tokens are what start credits were called before the
    split; rows written by the old code still say "start credit" in their stored
    description, which is why nothing here tries to restate history.

    Keep this file and de/strings.php in step — a key present in one and missing in
    the other renders as the raw key path to the user.
*/

return [
    /*
        The two store pages. Credits buy resources (ResourceStorePage, slug `store`); tokens
        buy plugged.host Plus and PlusPlus (TokenStorePage, slug `token-store`). The currencies
        are not interchangeable, which is why each has a page of its own.
    */
    'resource_store' => [
        'title' => 'Resource Store',
        'nav_label' => 'Resource Store',
        'subheading' => 'Spend credits on more CPU, memory and disk. What you buy is yours to keep.',
        'what_credits_buy' => 'Credits buy CPU, memory and disk, kept for good.',
        'balance_hint' => 'Earn credits by inviting people to our Discord server or boosting it.',
        'resources_heading' => 'Your resources',
        'resources_hint' => 'The bright part is what your servers use, the tinted part is the rest of your limit, and the bar runs to where prices get steep. The first steps are cheap; every step costs a bit more than the one before.',
        'of_limit' => 'of :limit',
        'meter_label' => ':used in use of :limit',
        'price_rising' => 'Steps get dearer on the way to :limit, where they reach full price.',
        'price_climbing' => 'Past :limit every step costs more, and past :soft far more.',
        'price_steep' => 'Past :soft every step costs far more than the last.',
        'over' => ':amount over your limit. Shrink or delete a server to make room.',
        'history' => 'Credit history',
    ],

    'plus_page' => [
        'title' => 'plugged.host Plus',
        'nav_label' => 'plugged.host Plus',
        'subheading' => 'Better locations, no waiting in line and no ads in your game. Bought once, yours for good.',
        'owned_heading' => 'You have plugged.host Plus',
        'owned_body' => 'Plus locations, priority in the start queue and no in-game ads are yours for good. PlusPlus adds more on top.',
        'plus_pitch' => 'Everything most servers need, once, with no renewals.',
        'plusplus_pitch' => 'The add-on for servers that need the most: Premium hardware, no ads anywhere and more CPU.',
        'price_tokens' => ':cost tokens',
        'plusplus_term' => 'a week, or credits for two weeks',
        'credits' => 'credits',
        'get_plus' => 'Get Plus',
        'extend_hint' => 'Buying again before it ends adds to the time left.',
        'compare_heading' => 'What you get',
        'col_free' => 'Free',
        'col_plus' => 'Plus',
        'col_plusplus' => 'PlusPlus',
        'row_locations' => 'Locations',
        'row_queue' => 'Start queue on a busy node',
        'row_game_ads' => 'In-game ads (Minecraft)',
        'row_panel_ads' => 'Ads in the panel',
        'row_cpu' => 'CPU',
        'row_ai' => 'neo (AI) usage',
        'cell_locations_free' => 'Free',
        'cell_locations_plus' => 'Free and Plus',
        'cell_locations_plusplus' => 'Free, Plus and Premium',
        'cell_queue_free' => 'Waits in line',
        'cell_queue_plus' => 'Ahead of free',
        'cell_queue_plusplus' => 'At the front',
        'cell_game_ads_free' => 'A short message every hour',
        'cell_game_ads_plus' => 'None',
        'cell_game_ads_plusplus' => 'None',
        'cell_panel_ads_free' => 'Yes',
        'cell_panel_ads_plus' => 'Yes',
        'cell_panel_ads_plusplus' => 'None',
        'cell_cpu_free' => 'Your allowance',
        'cell_cpu_plus' => 'Your allowance',
        'cell_cpu_plusplus' => 'Your allowance +:cpu%',
        'cell_ai_free' => 'Standard',
        'cell_ai_plus' => '+:plus%',
        'cell_ai_plusplus' => '+:plusplus%',
        'faq_heading' => 'Questions',
        'faq_once_q' => 'Is Plus a subscription?',
        'faq_once_a' => 'No. You pay for Plus once and keep it. Only PlusPlus runs for a set time, and nothing renews by itself.',
        'faq_expiry_q' => 'What happens when PlusPlus ends?',
        'faq_expiry_a' => 'You keep Plus. Servers on Premium locations move to another location, and if your servers use more CPU than your allowance without PlusPlus, the difference comes off them evenly. Buy PlusPlus again before it ends to avoid both.',
        'faq_tokens_q' => 'How do I get tokens?',
        'faq_tokens_a' => 'On the Tokens page in the sidebar, by completing sponsored links.',
        'faq_credits_q' => 'Can I pay with credits?',
        'faq_credits_a' => 'PlusPlus, yes: credits buy two weeks at a time. Plus itself is paid with tokens.',
    ],

    'store' => [
        'balance_after' => ':balance after',
    ],

    'credits' => [
        'credits' => 'Credits',
        'credits_short' => 'cr',
        'earn_discord_rewards' => 'Earn Discord Rewards',
        'confirm_purchase' => 'Are you sure you want to purchase :item for :cost credits?',
        'purchase_successful' => 'Purchase Successful',
        'purchase_failed' => 'Purchase Failed',
        'insufficient_credits' => 'You do not have enough credits for this purchase.',
        'store_unavailable' => 'CPU, memory and disk cannot be bought right now. Try again in a minute.',
        'invalid_amount' => 'That amount cannot be bought at once.',
        'cpu_purchased' => 'Successfully upgraded your CPU limit by +:amount% for :cost credits.',
        'memory_purchased' => 'Successfully upgraded your RAM limit by +:amount :unit for :cost credits.',
        'disk_purchased' => 'Successfully upgraded your Disk limit by +:amount :unit for :cost credits.',
        'tx_cpu_purchase' => 'Purchased +:amount% CPU limit upgrade',
        'tx_memory_purchase' => 'Purchased +:amount :unit RAM limit upgrade',
        'tx_disk_purchase' => 'Purchased +:amount :unit Disk storage upgrade',
        'no_transactions' => 'No credit transactions yet.',
        'tx_economy_reset' => 'Credit economy reset',
        'tx_invite_clawback' => 'Invite reward reversed — invited member left Discord',
        'tx_resource_reclaim' => 'Purchased resources reclaimed to cover a reversed reward',
    ],

    'tokens' => [
        'tokens' => 'Tokens',
        'balance' => 'Token Balance',
        'earn_tokens' => 'Earn tokens',
        'tx_daily' => 'Daily token',
    ],

    /*
        plugged.host Plus and PlusPlus (MembershipService). Prices and days come from
        config through the service, never written out here.
    */
    'membership' => [
        'heading' => 'plugged.host Plus',
        'description' => 'Plus is yours for good. PlusPlus builds on it for a few days at a time, and is bought again to keep it.',
        'plus_title' => 'plugged.host Plus',
        'plus_term' => 'Once, for good',
        'perk_plus_locations' => 'The Plus locations',
        'perk_priority' => 'Priority in the start queue when a node is busy',
        'perk_no_game_ads' => 'No in-game ads on your Minecraft servers',
        'plusplus_title' => 'plugged.host PlusPlus',
        'plusplus_term' => 'Needs Plus',
        'perk_everything_in_plus' => 'Everything in Plus',
        'perk_premium_locations' => 'The Premium locations',
        'perk_no_ads' => 'No ads anywhere: no banners, popups, waiting screen before a start or in-game ads',
        'perk_front_of_queue' => 'The front of the start queue, ahead of Plus',
        'perk_cpu' => '+:cpu% CPU on your account',
        'perk_ai_plus' => ':percent% more neo (AI) usage',
        'perk_ai_plusplus' => 'Another :percent% more neo (AI) usage on top of Plus',
        'plusplus_expiry' => 'When PlusPlus runs out, servers on Premium locations move to another location, and CPU over your allowance comes off your servers evenly. Buying again before then adds to the time left.',
        'plusplus_active_heading' => 'PlusPlus is active',
        'plusplus_active_body' => 'It runs until :until.',
        'owned' => 'Owned',
        'days' => ':days days',
        'tokens_short' => 'tokens',
        'cta_tokens' => ':cost tokens',
        'plus_confirm' => 'Spend :cost tokens on plugged.host Plus? It is yours for good.',
        'plusplus_confirm_tokens' => 'Spend :cost tokens on :days days of plugged.host PlusPlus?',
        'plusplus_confirm_credits' => 'Spend :cost credits on :days days of plugged.host PlusPlus?',
        'balance_tokens' => 'You have :balance tokens.',
        'earn_link' => 'Get more tokens',
        'needs_plus' => 'PlusPlus needs plugged.host Plus first.',
        'plus_bought' => 'You have plugged.host Plus.',
        'plusplus_bought' => 'PlusPlus runs until :until.',
        'short_tokens' => 'You need :cost tokens for that.',
        'short_credits' => 'You need :cost credits for that.',
        'unavailable' => 'Plus is not on sale at the moment.',
        'failed' => 'That did not go through. Nothing was charged.',
        'tx_plus' => 'plugged.host Plus',
        'tx_plusplus' => 'plugged.host PlusPlus, :days days',
    ],

    /*
    | The Economy tab on the admin user page. Support-facing, not user-facing: the
    | wording assumes whoever is reading it knows what a ledger is and needs to know
    | that this writes one, because the alternative — editing the balance column —
    | is the thing this tab exists to stop anyone from wanting to do.
    */
    'admin' => [
        'tab' => 'Economy',
        'credits_heading' => 'Credits',
        'credits_hint' => 'Credits buy CPU, memory and disk in the store. Earned from Discord invites and boosts.',
        'tokens_heading' => 'Tokens',
        'tokens_hint' => 'Tokens buy plugged.host Plus and PlusPlus. Earned from sponsored links.',
        'balance_current' => 'Balance: :amount',
        'balance_unavailable' => 'No balance on record',
        'set_credits' => 'Set credits',
        'set_tokens' => 'Set tokens',
        'modal_credits' => 'Set credit balance',
        'modal_tokens' => 'Set token balance',
        'modal_description' => 'The difference is written to the ledger as an adjustment, so the balance and the transaction history cannot end up disagreeing. Setting a balance to what it already is writes nothing.',
        'balance_field' => 'New balance',
        'balance_field_hint' => 'Currently :amount.',
        'note_field' => 'Note',
        'note_field_hint' => 'Optional. Stored on the ledger entry next to your name — a ticket number is usually enough.',
        'tx_description' => 'Adjusted by :admin',
        'tx_description_note' => 'Adjusted by :admin — :note',
        'notify_set' => 'Balance set to :amount.',
        'notify_unchanged' => 'The balance is already :amount. Nothing was written.',
        'notify_failed' => 'The adjustment did not go through. Nothing was changed.',
    ],
    // Tokens — earn page + ledger (moved here from plugged-host-ads, WP1 earn-half)
    // Tokens — the earn page
    'tokens_nav' => 'Tokens',
    'tokens_title' => 'Tokens',
    'tokens_subheading' => 'Earn tokens and see where they went',
    'tokens_balance' => ':count tokens',
    'tokens_explainer' => 'Earn tokens by completing sponsored links. They pay for plugged.host Plus, waking a hibernated server and subdomains.',
    'tokens_none' => 'No way to earn tokens is set up right now.',
    'tokens_reward' => ':count tokens per completion',
    'tokens_used' => ':used of :cap today',
    'tokens_capped' => 'Come back tomorrow',
    'tokens_link_unavailable' => 'Unavailable right now',
    'tokens_history' => 'Recent activity',
    'tokens_earn_with' => 'Earn with :provider',

    /*
        The block this plugin renders onto plugged-host-economy's Economy page, under
        the token wallet. Same offers as the earn page; the CTA is a link to that page
        rather than the sponsored anchor itself, because the anchor only earns if
        linkvertise.js rewrote it — which only happens where the script is loaded.
    */
    'tokens_store_heading' => 'Earn tokens',
    'tokens_store_hint' => 'Complete a sponsored link and the tokens are on your balance by the time you get back.',

    // Tokens — ledger descriptions
    'start_provider_linkvertise' => 'Linkvertise',
    'tokens_tx_earn' => 'Earned with :provider',

    // Tokens — results
    'tokens_result_granted' => 'Added: +:amount tokens',
    'tokens_result_settled' => 'That one has already been paid out',
    'tokens_result_expired' => 'That took too long — please start again',
    'tokens_result_no_token' => 'The provider sent you back without a completion code',
    'tokens_result_no_claim' => 'No pending attempt found for your account',
    'tokens_result_replay' => 'That completion code has already been used',
    'tokens_result_capped' => 'You have reached today\'s limit for this option',
    'tokens_result_throttled' => 'Too many attempts — please slow down',
    'tokens_result_invalid' => 'That could not be verified, so no tokens were added',
    'tokens_result_error' => 'Something went wrong. No tokens were added.',

    'perks' => [
        'heading' => 'Get more out of your account',
        // The dashboard's collapsed one-line variant. The count belongs in the heading because
        // a closed bar has to say how much is behind it, or nobody opens it.
        'heading_compact' => 'Unlock more resources (:count)',
        'description' => 'Upgrades you have not claimed yet. Each one leaves this list once it is yours.',
        'discord_link_title' => 'Link Discord for :gain',
        'discord_link_body' => 'Connect your Discord account and join our server to raise your limits for free.',
        'discord_link_cta' => 'Link Discord',
        'discord_join_title' => 'Join our Discord for :gain',
        'discord_join_body' => 'Your account is linked, but the upgrade only lands once you are a member of the server.',
        'discord_join_cta' => 'Join server',
        'discord_earn_title' => 'Earn :credits credits per friend you invite',
        'discord_earn_body' => 'Invite people to our Discord with your own invite link, then spend the credits on more resources.',
        'discord_earn_body_boost' => 'Invite people with your own invite link, or boost the server for :credits credits, then spend it all on more resources.',
        'discord_earn_cta' => 'View invites',
        'store_title' => ':price credits buys :amount more RAM',
        'store_body' => 'You have :balance credits. Spend them on CPU, RAM or disk space.',
        'store_cta' => 'Open store',
        'gain_cpu' => '+:amount CPU',
        'gain_memory' => '+:amount RAM',
        'gain_disk' => '+:amount disk space',
        'gain_and' => 'and',
    ],
];
