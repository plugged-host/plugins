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
    'priority_plus_heading' => 'You have :app Plus',
    'priority_plus_body' => 'Plus puts your servers ahead of everyone without it in this line.',
    'priority_plusplus_heading' => 'You have :app PlusPlus',
    'priority_plusplus_body' => 'PlusPlus puts your servers at the front of this line, ahead of Plus.',
    'plus_offer_heading' => 'Skip ahead with :app Plus',
    'plus_offer_body' => 'Plus is :cost tokens, once, and yours for good: priority in this line for all your servers, the Plus locations and no in-game ads.',
    'plus_offer_cta' => 'Get Plus',
    'tokens_tx_priority_replaced' => 'Refund — priority passes were replaced by :app Plus',

    // The transfer offer.
    'transfer_heading' => 'Move to a quieter node',
    'transfer_body' => 'We can move this server to :node, which is not busy. Your files come with it.',
    'transfer_note' => 'The server goes offline while it copies and its address changes. It starts again by itself once the move finishes.',
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

    // Notifications.
    'notify_conflict' => 'This server cannot be started right now, so the queue entry was cancelled.',
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
    'section_general_desc' => 'Every start of a user who is not exempt waits on a short page and is released one at a time per node, once the node is under the busy threshold, so another container does not make every server on a busy node worse.',
    'enabled' => 'Queue starts',
    'enabled_helper' => 'Every start of a user who is not exempt and has no pass waits at least the minimum wait, then goes out one per node per release gap while the node is under the busy threshold. Off, starts always go straight through.',
    'include_restart' => 'Queue restarts too',
    'include_restart_helper' => 'A restart brings a container back up, so it costs the same headroom as a start. The server keeps running while the user waits.',
    'exempt_roles_skip' => 'Exempt roles skip the queue',
    'exempt_roles_skip_helper' => 'Uses the exempt role list from the plugged.host Ads plugin rather than keeping a second one. Root admins always skip.',
    'pressure_dimensions' => 'What counts as busy',
    'pressure_dimensions_helper' => 'Comma-separated: memory, cpu, disk. A node over the busy threshold on any of these releases nobody until it has room again or the maximum wait runs out. Disk is not recommended — a full disk does not empty itself while somebody waits, so it makes a line that never moves.',
    'threshold_percent' => 'Busy threshold',
    'threshold_percent_helper' => 'A node above this on the dimensions above holds its line until it is back under it, and takes no new servers meanwhile (the deployment pause in plugged.host Servers, with a lower line on small nodes). Lower holds starts back sooner; higher lets a node fill fuller before anyone waits longer than the minimum.',

    'section_timing' => 'Timing',
    'section_timing_desc' => 'How long a start may be held, and how quickly starts are let onto one node.',
    'min_wait' => 'Minimum wait',
    'min_wait_helper' => 'Every queued start waits at least this long, even on an idle node, so the page never just flashes past.',
    'max_wait' => 'Maximum wait',
    'max_wait_helper' => 'The ceiling. Past this the start goes out whatever the node looks like — a queue with no ceiling is an outage with a progress bar. Raised automatically if it is below the minimum.',
    'release_interval' => 'Gap between releases',
    'release_interval_helper' => 'Per node: one start goes out per gap, so a line of starts never lands on a node all at once. Entries at the ceiling ignore this.',
    'stale_after' => 'Drop silent entries after',
    'stale_after_helper' => 'Only entries that never became releasable. A closed tab still gets its start — the sweep dispatches it — so this is not a way to lose a request.',
    'pass_minutes' => 'Skip both waits for (minutes)',
    'pass_minutes_helper' => 'After a release, this user\'s next starts on that server skip this queue and the ad gate. Sitting through one wait and landing in the other is the worst thing this feature can do.',


    'section_transfer' => 'Moving off a busy node',
    'section_transfer_desc' => 'The other way out of the queue: move the server to a node that is not busy. Offered on the waiting page, always taken by the user, never automatic.',
    'transfer_enabled' => 'Offer the move',
    'transfer_enabled_helper' => 'Off, waiting is the only way through the queue.',
    'transfer_require_priority' => ':app Plus required',
    'transfer_require_priority_helper' => 'On, the move is part of what Plus buys. Off, anyone stuck in the queue can move.',
    'transfer_max_disk' => 'Hide the offer at or above',
    'transfer_max_disk_helper' => 'A transfer copies the whole data directory. Past a few gigabytes it stops being the fast way out. Servers with unlimited disk are never offered it.',
    'transfer_same_location' => 'Same location only',
    'transfer_same_location_helper' => 'On, only nodes sharing a location tag with the current one are considered. Off, the pool is every viable node, which can move somebody to another continent.',
];
