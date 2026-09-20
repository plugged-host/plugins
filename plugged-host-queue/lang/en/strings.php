<?php

/*
|--------------------------------------------------------------------------
| plugged.host Queue — English strings
|--------------------------------------------------------------------------
|
| Keep this file and lang/de/strings.php on the same key set. A key present in
| one and missing from the other renders as the raw dotted path on a page a
| paying customer is looking at.
|
| Tone rule for everything on the waiting page: say what is happening and why,
| in the fewest words that are still true. Somebody whose server will not start
| is already annoyed, and a cheerful euphemism for "the machine is full" is how
| that becomes a ticket.
|
*/

return [
    // The page itself.
    'page_title' => 'Waiting to start',
    'page_subheading' => 'Your start request for :server is in the queue.',

    'heading' => 'Getting your server ready',
    'body_start' => 'This node is busy right now. Your server will start automatically as soon as there is room — you do not need to press anything.',
    'body_restart' => 'This node is busy right now. Your restart will run automatically as soon as there is room — your server keeps running until then.',

    // Position in the line.
    'position_label' => 'Your place',
    'position_value' => ':position in line',
    'position_next' => 'You are next',
    'position_priority' => 'Priority',

    'waited' => 'Waiting :time',
    'cap_note' => 'Starts within :seconds seconds at the latest',
    'cap_reached' => 'Starting now',

    // Why the wait is happening, over the live reading the release uses.
    'reason_memory' => 'The memory on this node is above :limit%. Starting another server now would slow down every server on it, including yours.',
    'reason_cpu' => 'The CPU on this node is above :limit%. Starting another server now would slow down every server on it, including yours.',
    'reason_disk' => 'The storage on this node is above :limit%. Starting another server now would slow down every server on it, including yours.',
    'reason_cleared' => 'The node has room again. Your server is starting.',

    'usage_memory' => 'Memory',
    'usage_cpu' => 'CPU',
    'usage_disk' => 'Storage',

    // Priority — what tokens buy. Two tiers; prices and lengths come from the config,
    // never from this file, so an admin who changes one does not leave a lie behind.
    'priority_active_heading' => 'Priority is active',
    'priority_active_body' => 'Your starts skip ahead of everyone without priority for the next :remaining. You are still in this line because the node is full for everybody — but you are at the front of it.',
    'priority_active_ad_free' => 'Advertising is switched off across the panel for the next :remaining.',
    'priority_heading' => 'Skip the queue for the next few days',
    'priority_body' => 'Tokens buy a priority window on your whole account: every start goes ahead of everyone without one, and it unlocks moving a server to a quieter node. Buying again extends the window rather than replacing it.',
    'priority_balance' => 'You have :balance tokens.',
    'priority_earn_link' => 'Get more tokens',
    'priority_cta' => ':cost tokens',
    'priority_tier_standard' => 'Priority',
    'priority_tier_ad_free' => 'Priority, ad-free',
    'priority_tier_days' => ':days days',
    'priority_badge_ads' => 'Ads stay',
    'priority_badge_no_ads' => 'No ads',
    'priority_tier_ads_note' => 'The longer window. Advertising stays as it is, including this page.',
    'priority_tier_no_ads_note' => 'Shorter, and the panel goes quiet with it: no banners, no popups, no ad-block checks, and no waiting screen before a start.',

    // The transfer offer.
    'transfer_heading' => 'Move to a quieter node',
    'transfer_body' => 'We can move this server to :node, which is not busy. Your files come with it.',
    'transfer_note' => 'The server goes offline while it copies, and its IP address and port change. You start it yourself once the move finishes.',
    'transfer_confirm' => "Move this server to :node?\n\nThe server stays offline until the copy finishes, and its IP address and port will change. Anything pointing at the old address — a domain, a bookmark, a player's server list — has to be updated.",
    'transfer_cta' => 'Move the server',
    'transfer_too_big' => 'This server is too large to move quickly, so waiting here is the faster option.',

    // The sponsored link.
    'direct_heading' => 'Something to look at while you wait',
    'direct_body' => 'Opens in a new tab. Your place in the line is kept, and the wait keeps running here.',
    'direct_cta' => 'Open',

    // Leaving.
    'leave_cta' => 'Cancel and go back',
    'pass_note' => 'After your server starts, the next :minutes minutes of starts skip this page.',

    // Token ledger entries. These end up in the user's own transaction list.
    'tokens_tx_priority' => 'Queue priority for :days days',
    'tokens_tx_priority_ad_free' => 'Ad-free queue priority for :days days',
    'tokens_tx_priority_refund' => 'Refund — queue priority could not be activated',

    // Notifications.
    'notify_conflict' => 'This server cannot be started right now, so the queue entry was cancelled.',
    'notify_priority_bought' => 'Priority is active for the next :days days.',
    'notify_priority_bought_ad_free' => 'Priority is active for :days days, and advertising is off for that whole window.',
    'notify_priority_short' => 'You need :cost tokens for priority.',
    'notify_priority_unavailable' => 'Priority is not available at the moment.',
    'notify_priority_failed' => 'Something went wrong activating priority. No tokens were spent.',
    'notify_transfer_started' => 'The move has started. Your server will be back once the files finish copying.',
    'notify_transfer_no_node' => 'There is no quieter node to move this server to right now.',
    'notify_transfer_conflict' => 'This server is busy with something else, so it cannot be moved right now.',
    'notify_transfer_unavailable' => 'Moving servers is not available at the moment.',
    'notify_transfer_failed' => 'The move could not be started. Nothing has changed.',

    /*
    |--------------------------------------------------------------------------
    | Admin settings form
    |--------------------------------------------------------------------------
    |
    | Admin Area → Plugins → plugged.host Queue. The helpers are written for
    | somebody who has not read the source: each one says what the number does
    | to a real user, not what field it maps to.
    |
    */
    'section_general' => 'Start queue',
    'section_general_desc' => 'Holds starts back while a node is out of headroom, instead of letting another container make every server on it worse.',
    'enabled' => 'Queue starts on busy nodes',
    'enabled_helper' => 'Off, starts always go straight through. Nodes above the pressure line are still measured, just not acted on.',
    'include_restart' => 'Queue restarts too',
    'include_restart_helper' => 'A restart brings a container back up, so it costs the same headroom as a start. The server keeps running while the user waits.',
    'exempt_roles_skip' => 'Exempt roles skip the queue',
    'exempt_roles_skip_helper' => 'Uses the exempt role list from the plugged.host Ads plugin rather than keeping a second one. Root admins always skip.',
    'pressure_dimensions' => 'What counts as busy',
    'pressure_dimensions_helper' => 'Comma-separated: memory, cpu, disk. Disk is not recommended — a full disk does not empty itself while somebody waits, so it makes a line that never moves. Thresholds live in the plugged.host Core plugin.',
    'threshold_percent' => 'Busy threshold',
    'threshold_percent_helper' => 'The queue\'s own line, separate from the allocator\'s 95% ceiling. A node above this on the pressure dimensions sends new starts through the queue. Lower catches load sooner and makes people wait more often; higher lets a node fill fuller before anyone is held.',
    'paced_on_unavailable' => 'Pace nodes that cannot be read',
    'paced_on_unavailable_helper' => 'When a node\'s utilisation cannot be measured, still let only one start through per release gap rather than the whole line at once. Off, an unreadable node runs free — a stampede onto a daemon that is already struggling.',

    'section_timing' => 'Timing',
    'section_timing_desc' => 'How long a start may be held, and how quickly a node that has cooled is allowed to fill back up.',
    'min_wait' => 'Minimum wait',
    'min_wait_helper' => 'The floor. Without it, a node that cools between the click and the first poll produces a page that flashes past.',
    'max_wait' => 'Maximum wait',
    'max_wait_helper' => 'The ceiling. Past this the start goes out whatever the node looks like — a queue with no ceiling is an outage with a progress bar. Raised automatically if it is below the minimum.',
    'release_interval' => 'Gap between releases',
    'release_interval_helper' => 'Per node. A node that just dropped under the line has not got room for everything waiting on it. Entries at the ceiling ignore this.',
    'stale_after' => 'Drop silent entries after',
    'stale_after_helper' => 'Only entries that never became releasable. A closed tab still gets its start — the sweep dispatches it — so this is not a way to lose a request.',
    'pass_minutes' => 'Skip both waits for (minutes)',
    'pass_minutes_helper' => 'After a release, this user\'s next starts on that server skip this queue and the ad gate. Sitting through one wait and landing in the other is the worst thing this feature can do.',

    'section_priority' => 'Priority',
    'section_priority_desc' => 'What tokens buy. One purchase, account-wide, for a window of days — not a single skip. Sold on the waiting page and in the resource store.',
    'priority_token_cost' => 'Token cost',
    'priority_token_cost_helper' => 'Tokens are minted by the plugged.host Ads plugin. Its earn page reads this number to explain the price, so changing it here changes it there.',
    'priority_days' => 'Window (days)',
    'priority_days_helper' => 'Buying again while a window is open extends it rather than replacing it.',
    'priority_ad_free_enabled' => 'Also sell an ad-free tier',
    'priority_ad_free_enabled_helper' => 'A second, dearer tier that switches off every ad surface for its window: banners, the social bar, the popunder, the ad-block check and the start gate. Turning this off stops selling it; windows already bought run to their end.',
    'priority_ad_free_cost' => 'Ad-free token cost',
    'priority_ad_free_cost_helper' => 'Should be dearer than the plain tier. It gives up the advertising that pays for the capacity being skipped.',
    'priority_ad_free_days' => 'Ad-free window (days)',
    'priority_ad_free_days_helper' => 'Deliberately shorter than the plain window. The two are tracked separately, so buying the plain tier during an ad-free window adds priority without adding quiet.',

    'section_transfer' => 'Moving off a busy node',
    'section_transfer_desc' => 'The other way out of the queue: move the server to a node that is not busy. Offered on the waiting page, always taken by the user, never automatic.',
    'transfer_enabled' => 'Offer the move',
    'transfer_enabled_helper' => 'Off, waiting is the only way through the queue.',
    'transfer_require_priority' => 'Priority required',
    'transfer_require_priority_helper' => 'On, the move is part of what the tokens buy. Off, anyone stuck in the queue can move.',
    'transfer_max_disk' => 'Hide the offer at or above',
    'transfer_max_disk_helper' => 'A transfer copies the whole data directory. Past a few gigabytes it stops being the fast way out. Servers with unlimited disk are never offered it.',
    'transfer_same_location' => 'Same location only',
    'transfer_same_location_helper' => 'On, only nodes sharing a location tag with the current one are considered. Off, the pool is every viable node, which can move somebody to another continent.',
];
