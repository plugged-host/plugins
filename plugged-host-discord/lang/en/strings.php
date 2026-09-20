<?php

return [
    'discord' => 'Discord',
    'discord_integration' => 'Discord Integration',
    'account' => 'Account',
    'profile_section_description' => 'Connect your Discord account and join our Discord server to receive the Discord Linked role and boosted resource limits.',

    'discord_account_status' => 'Discord Account Status',
    'badge_verified' => 'Verified Server Member',
    'badge_not_in_server' => 'Not in Discord Server',
    'last_checked' => 'Last checked',

    'join_discord_server' => 'Join Discord Server',
    'verify_membership' => 'Verify Membership',
    'unlink_discord' => 'Unlink Discord',
    'unlink_confirm' => 'Are you sure you want to unlink your Discord account? You will lose the Discord Linked role and its resource limits.',
    'link_discord_account' => 'Link Discord Account',
    'link_taken_title' => 'Discord account already linked',
    'link_taken_body' => 'That Discord account is already linked to a different panel account. One Discord account can only be linked to one panel account — unlink it there first, or sign in with the account that owns it.',

    'action_required' => 'Action Required: Join Server',
    'join_server_help' => 'Your Discord account is connected, but you have not joined our Discord server yet. Click "Join Discord Server" above to join, then click "Verify Membership" to receive the :role role.',

    'role_active_title' => ':role Role Active',
    'role_active_body' => 'You are a confirmed member of our Discord server! You currently have the :role role in the panel, granting you increased server resource limits.',

    'not_linked_heading' => 'Connect your Discord Account',
    'not_linked_description' => 'Link your Discord account and join our community server to automatically receive the :role role and enjoy enhanced resource limits for your game servers.',

    'misconfigured_title' => 'Discord Integration Not Configured',
    'misconfigured_body' => 'The administrator has not configured the Discord bot token or guild ID yet. Verification is temporarily unavailable.',

    'verified_title' => 'Discord Verified!',
    'verified_body' => 'Your membership in our Discord server has been confirmed. The ":role" role is now active on your account!',

    'not_in_server_title' => 'Not in Discord Server',
    'not_in_server_body' => 'Your Discord account was found, but you are not yet a member of our Discord server. Please join the server and try again.',

    'not_linked_title' => 'No Discord Account Linked',
    'not_linked_body' => 'Please link your Discord account first before verifying membership.',

    'error_title' => 'Verification Error',
    'error_body' => 'Could not verify Discord membership at this time. Please try again later.',

    'unlinked_title' => 'Discord Unlinked',
    'unlinked_body' => 'Your Discord account has been disconnected.',

    'need_more_stuff' => 'Need more stuff? Link Discord',

    'rewards_section_title' => 'Discord Rewards & Credits',
    'rewards_section_description' => 'Earn credits automatically by inviting friends and boosting our Discord community server.',
    'check_and_claim_rewards' => 'Check & Claim Rewards',
    'go_to_resource_store' => 'Resource Store',
    'invite_rewards_title' => 'Invite Rewards',
    'credits_per_invite' => 'Credits / Invite',
    'total_invites' => 'Counted Invites',
    'credits_earned' => 'Credits Earned',
    'invite_rewards_hint' => 'Create a personal invite link in Discord and share it with friends. You earn credits for every verified join (invited accounts must be at least :days days old to prevent alts).',
    'boost_rewards_title' => 'Server Boost Rewards',
    'boost_tier' => '{1} :count Server Boost|[2,*] :count Server Boosts',
    'boost_active' => 'Boosting (:count Boosts)',
    'boost_inactive' => 'Not Boosting',
    'boost_rewards_hint' => 'Boost our Discord server to unlock major credit bonuses: 1 boost = ~10 invites (500 credits), 2 boosts = ~25 invites (1,250 credits).',
    'credits' => 'Credits',
    'bot_token_required_title' => 'Bot Token Required',
    'bot_token_required_body' => 'The Discord Bot Token must be configured in settings by an administrator to track invites and boosts.',
    'rewards_claimed_title' => 'Rewards Claimed!',
    'rewards_claimed_body' => 'Successfully claimed :credits credits from Discord rewards! You can now spend them in the Resource Store.',
    'rewards_checked_title' => 'Rewards Checked',
    'rewards_checked_body' => 'Your Discord rewards are up to date. No new rewards available right now.',
    'tx_invite_reward' => 'Reward for :count Discord invite(s)',
    'tx_boost_reward' => 'Reward for Discord server boost (Tier :count)',
    'tx_invite_debt_settled' => ':credits credits went to settling your outstanding invite debt',

    // Escrow / leave / debt surfacing. An invite is only counted while the invited
    // member is still in the server, and only paid once it has matured.
    'pending_invites' => 'Pending',
    'left_invites' => 'Left',
    'credits_pending' => 'Credits Pending',
    'invite_debt' => 'Invite Debt',
    'invite_pending_hint' => 'New invites are held for :hours hours before credits are paid out. If the member leaves before then, the invite never pays.',
    'invite_left_hint' => 'Members who left the Discord server stop counting toward your invites, and credits already paid for them are reclaimed.',
    'invite_debt_hint' => 'Credits that could not be reclaimed when an invited member left. It is taken out of your future invite rewards before anything is paid.',

    // Invites from before join tracking existed. Discord only reports how many times each
    // code was used, never who joined on it, so these cannot be verified or rewarded.
    'legacy_invites' => 'Legacy',
    'legacy_invites_hint' => 'Legacy invites are from before invite tracking was running. Discord only reports how many times a link was used, not who joined, so these cannot be verified and pay no credits. They are shown for recognition only.',

    // Admin settings form labels for knobs added in v1.2.0.
    'settings' => [
        'secret_unchanged' => '(unchanged)',
        'secret_unset' => 'Not set',
        'bot_token_set_help' => 'A bot token is stored. Leave blank to keep it, or type a new one to replace it. Required for reading invite leaderboards, tracking joins, detecting boosts, and slash commands.',
        'bot_token_unset_help' => 'No bot token is stored. Required for reading invite leaderboards, tracking joins, detecting boosts, and slash commands.',
        'public_key' => 'Discord Public Key (Optional)',
        'public_key_set_help' => 'A public key is stored. Leave blank to keep it, or type a new one to replace it. Used to verify Ed25519 signatures when using HTTP Interactions instead of the Gateway WebSocket.',
        'public_key_unset_help' => 'No public key is stored. Only needed if you verify Discord HTTP Interactions instead of using the Gateway WebSocket.',

        'section_daemon' => 'Gateway Daemon & Supervision',
        'section_daemon_hint' => 'Advanced controls for the WebSocket gateway daemon and the supervisor that keeps it alive. The defaults suit most installs.',
        'gateway_idle_timeout' => 'Gateway Idle Timeout',
        'gateway_idle_timeout_hint' => 'Seconds of complete gateway silence after which the daemon drops the socket and reconnects. Discord acknowledges the heartbeat about every 41 seconds, so keep this comfortably above that (default: 90).',
        'supervisor_enabled' => 'Automatic restarts',
        'supervisor_enabled_hint' => 'Master switch for the supervisor. When on, the scheduler restarts the daemon after a crash or reboot. Turn off only to stop the daemon deliberately.',
        'supervisor_auto_install_trigger' => 'Reinstall scheduler entry automatically',
        'supervisor_auto_install_trigger_hint' => 'Reinstall the schedule:run crontab line by itself when nothing has ticked for a few minutes. The crontab is the only piece of this plugin a host rebuild can lose.',
        'supervisor_heartbeat_interval' => 'Heartbeat Interval',
        'supervisor_heartbeat_interval_hint' => 'How often the running daemon records that it is still alive (default: 15).',
        'supervisor_heartbeat_timeout' => 'Heartbeat Timeout',
        'supervisor_heartbeat_timeout_hint' => 'Silence from the daemon after which the supervisor considers it wedged and replaces it (default: 120).',
        'supervisor_max_backoff' => 'Maximum Restart Backoff',
        'supervisor_max_backoff_hint' => 'Ceiling on the growing delay between repeated failed start attempts (default: 900).',
        'supervisor_log_max_bytes' => 'Log Rotation Size',
        'supervisor_log_max_bytes_hint' => 'Rotate storage/logs/discord-bot.log once it grows past this size in bytes (default: 8388608, i.e. 8 MiB).',
        'supervisor_run_as' => 'Run Daemon As User',
        'supervisor_run_as_hint' => 'The system user the daemon must run as. Never root; usually www-data.',
        'supervisor_php_binary' => 'PHP Binary Override (Optional)',
        'supervisor_php_binary_hint' => 'Full path to the PHP interpreter used to spawn the daemon. Leave blank to auto-detect.',
    ],
];

