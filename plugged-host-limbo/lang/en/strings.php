<?php

return [
    'title' => 'Server hibernated',
    'restore' => 'Restore server',
    'confirm' => 'Restoring will spend :cost tokens and move your server back to a live node.',
    'restored' => 'Your server is being restored.',
    'restoring' => 'Your server is on its way back to a live node. This takes a few minutes; the console opens again as soon as it has arrived.',
    'insufficient' => 'You need :cost tokens to restore this server.',
    'failed' => 'Restore could not be started. No tokens were spent.',
    'location_full' => 'There is no room for this server there right now. Pick a location below. No tokens were spent.',
    'pick_title' => 'Choose a location',
    'pick_body' => 'The location this server came from is full right now. Pick one of your locations to restore it there. Its address will change.',
    'pick_none' => 'None of your locations has room for this server right now. Try again later.',
    'ms' => ':ms ms',
    'body' => 'This server was idle for a while, so it was moved to hibernation to free up capacity. Restore it to bring it back online.',

    'entered' => 'Hibernated :time',
    'cost_label' => 'Restore cost',
    'balance_label' => 'Your balance',
    'tokens' => ':count tokens',

    'settings' => [
        'section_general' => 'General',
        'section_general_hint' => 'Master switch for the hibernation sweep. While off, the sweep is a no-op and the plugin ships dark.',
        'enabled' => 'Enable hibernation',
        'enabled_hint' => 'When on, idle servers are stopped and moved to the limbo node on the schedule below.',

        'section_schedule' => 'Hibernation schedule',
        'section_schedule_hint' => 'Thresholds measured in hours since a server last saw panel activity, plus where and how fast servers move.',
        'stop_hours' => 'Stop after (hours)',
        'stop_hours_hint' => 'Hours of no panel activity before the server is stopped.',
        'transfer_hours' => 'Transfer after (hours)',
        'transfer_hours_hint' => 'Hours of no panel activity before a stopped server is moved to the limbo node.',
        'node_tag' => 'Limbo node tag',
        'node_tag_hint' => 'Tag that identifies the limbo node. Must not be a country code and must be excluded from UCS deployment tags.',
        'max_per_run' => 'Max transfers at once',
        'max_per_run_hint' => 'How many servers may be on their way to the limbo node at the same time (also the most servers one sweep stops). A move takes minutes, so this keeps the limbo node from being flooded.',

        'section_ransom' => 'Restore cost',
        'section_ransom_hint' => 'Token ransom the owner pays to restore a hibernated server back to a live node.',
        'cost_early' => 'Early restore cost (tokens)',
        'cost_early_hint' => 'Tokens charged when a server is restored within the cost window after entering limbo.',
        'cost_late' => 'Late restore cost (tokens)',
        'cost_late_hint' => 'Tokens charged when a server is restored after the cost window has passed.',
        'cost_window_hours' => 'Cost window (hours)',
        'cost_window_hours_hint' => 'Hours after entering limbo during which the early cost applies; after this, the late cost applies.',
    ],
];
