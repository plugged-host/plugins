<?php

/*
    plugged.host Economy — English.

    The `credits` block moved here verbatim from plugged-host-core when the economy
    was split out, keys and all, so every `plugged-host-economy::strings.credits.*`
    call reads the same copy it always did. Do not renumber or rename those keys
    without grepping both this plugin and plugged-host-core, which still calls a few
    of them from its widgets.

    The `tokens` block is new. Tokens are what start credits were called before the
    split; rows written by the old code still say "start credit" in their stored
    description, which is why nothing here tries to restate history.

    Keep this file and de/strings.php in step — a key present in one and missing in
    the other renders as the raw key path to the user.
*/

return [
    /*
        The page's own identity, and the only place the word "Economy" is defined.

        The class is still ResourceStorePage and the slug is still `store` — five other
        surfaces resolve it and the URL is in people's history — so the rename is a
        label. "Resource Store" survives as `credits.resource_store` because widgets in
        plugged-host-core and a perk in PerkDiscovery still use it as the name of the
        *upgrade section*, which is what that string always described.
    */
    'economy' => [
        'title' => 'Economy',
        'nav_label' => 'Economy',
        'subheading' => 'Your balances, everything they buy, and how to earn more of both.',
        'earn_heading' => 'Earn tokens',
    ],

    'credits' => [
        'credits' => 'Credits',
        'credits_short' => 'cr',
        'resource_store' => 'Resource Store',
        'store_subheading' => 'Upgrade your server resources and limits with credits earned from Discord rewards and activity.',
        'store_description' => 'Upgrade limits in Store',
        'current_balance' => 'Current Balance',
        'balance_hint' => 'Earn credits by linking Discord, inviting members, or boosting our Discord server.',
        'earn_discord_rewards' => 'Earn Discord Rewards',
        'available_upgrades' => 'Resource Upgrades',
        'available_upgrades_description' => 'Expand your personal allowance. Upgraded resources are permanently attached to your account.',
        'max_tier' => 'Max Tier',
        'limit' => 'Current',
        'max' => 'Max Cap',
        'current_limit' => 'Current Limit',
        'servers_count' => 'servers',
        'server_slot' => 'Server Slot',
        'cpu_upgrade_desc' => 'Increase your total CPU limit up to 600% to run higher-performance servers.',
        'memory_upgrade_desc' => 'Increase your total RAM limit up to 24 GB for larger modpacks and players.',
        'disk_upgrade_desc' => 'Increase your total disk storage up to 100 GB for world saves and backups.',
        'server_slot_upgrade_desc' => 'Increase how many individual servers you can create simultaneously.',
        'confirm_purchase' => 'Are you sure you want to purchase :item for :cost credits?',
        'purchase_successful' => 'Purchase Successful',
        'purchase_failed' => 'Purchase Failed',
        'insufficient_credits' => 'You do not have enough credits for this purchase.',
        'max_cpu_reached' => 'You have already reached the maximum CPU limit (600%).',
        'max_memory_reached' => 'You have already reached the maximum RAM limit (24 GB).',
        'max_disk_reached' => 'You have already reached the maximum Disk limit (100 GB).',
        'cpu_purchased' => 'Successfully upgraded your CPU limit by +:amount% for :cost credits.',
        'memory_purchased' => 'Successfully upgraded your RAM limit by +:amount :unit for :cost credits.',
        'disk_purchased' => 'Successfully upgraded your Disk limit by +:amount :unit for :cost credits.',
        'server_slot_purchased' => 'Successfully added :slots server slot(s) for :cost credits.',
        'tx_cpu_purchase' => 'Purchased +:amount% CPU limit upgrade',
        'tx_memory_purchase' => 'Purchased +:amount :unit RAM limit upgrade',
        'tx_disk_purchase' => 'Purchased +:amount :unit Disk storage upgrade',
        'tx_server_slot_purchase' => 'Purchased +:slots server slot upgrade',
        'recent_transactions' => 'Recent Transactions',
        'no_transactions' => 'No credit transactions yet.',
        'date' => 'Date',
        'description' => 'Description',
        'amount' => 'Amount',
        'balance' => 'Balance',
        'need_more_upgrade_store' => 'Need more? Upgrade in Resource Store',

        // Shown when existing servers already allocate more than the current limit
        // allows — a role was removed, lowered, or resources were reclaimed. Buying
        // more is one fix, shrinking a server is the other.
        'over_quota' => 'Over your limit by :amount — shrink or delete a server',
        'over_quota_slots' => 'Over your server limit by :amount — delete a server',
        'tx_economy_reset' => 'Credit economy reset',
        'tx_invite_clawback' => 'Invite reward reversed — invited member left Discord',
        'tx_resource_reclaim' => 'Purchased resources reclaimed to cover a reversed reward',
    ],

    'tokens' => [
        'tokens' => 'Tokens',
        'tokens_short' => 'tk',
        'balance' => 'Token Balance',
        'balance_hint' => 'Tokens buy priority in the start queue. Earn them by completing a sponsored link.',
        'earn_tokens' => 'Earn tokens',
        'insufficient' => 'You do not have enough tokens for this.',
        'tx_daily' => 'Daily token',
        'what_credits_buy' => 'CPU, RAM, disk and server slots — kept forever.',
        'what_tokens_buy' => 'Priority in the start queue, for a window of days.',
    ],

    /*
        The priority pass, sold here and on the queue's waiting page. The window
        lengths and prices are never written out in this file — they come from
        plugged-host-queue's config through `PriorityPassService::tiers()`, so an
        admin who changes the price does not leave a lie behind in a translation.
    */
    'priority' => [
        'heading' => 'Skip the start queue',
        'description' => 'When a node is busy, starts are held in a line. Priority puts every start on your account in front of everyone without it — for days, on all your servers, not just once.',
        'active_heading' => 'Priority is active',
        'active_body' => 'Your starts go ahead of everyone without priority for the next :remaining.',
        'active_ad_free' => 'Advertising is switched off across the panel for the next :remaining.',
        'extend_hint' => 'Buying again extends your window instead of replacing it.',
        'tier_standard' => 'Priority',
        'tier_ad_free' => 'Priority, ad-free',
        'tier_days' => ':days days',
        'badge_ads' => 'Ads stay',
        'badge_no_ads' => 'No ads',
        'perk_queue' => 'Every start jumps the line',
        'perk_transfer' => 'Unlocks moving a server to a quieter node',
        'perk_ads_stay' => 'Advertising stays as it is',
        'perk_no_ads' => 'No banners, no popups, no ad-block checks, and no waiting screen before a start',
        'cta' => ':cost tokens',
        'confirm' => 'Spend :cost tokens on :days days of priority?',
        'balance' => 'You have :balance tokens.',
        'earn_link' => 'Get more tokens',
        'notify_bought' => 'Priority is active for the next :days days.',
        'notify_bought_ad_free' => 'Priority is active for :days days, and advertising is off for that whole window.',
        'notify_short' => 'You need :cost tokens for that.',
        'notify_unavailable' => 'Priority is not available at the moment.',
        'notify_failed' => 'Something went wrong activating priority. No tokens were spent.',
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
        'credits_hint' => 'Credits buy CPU, memory, disk and server slots in the store. Earned from Discord invites and boosts.',
        'tokens_heading' => 'Tokens',
        'tokens_hint' => 'Tokens buy priority in the start queue. Earned from sponsored links.',
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
    'tokens_subheading' => 'Earn tokens and skip ahead when the nodes are busy',
    'tokens_balance' => ':count tokens',
    'tokens_explainer' => 'Tokens buy priority in the start queue: :cost of them puts you ahead of everyone without priority for :days days, on every server you own. A dearer tier in the resource store buys a shorter window that switches every ad off with it, including the ad-funded wait before a large server starts.',
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

];
