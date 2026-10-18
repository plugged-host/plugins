<?php

return [
    // The server sidebar's collapsed group (PluggedHost\Core\Filament\ServerNavigation).
    'nav' => [
        'more' => 'More',
    ],

    // A node with backups turned off (Support\NodeBackups, NodeAwareInitiateBackupService).
    'backups' => [
        'disabled' => 'Backups are turned off for servers on this node.',
    ],

    'role' => 'Role|Roles',
    'user' => 'User|Users',
    'cpu' => 'CPU',
    'memory' => 'Memory',
    'disk' => 'Disk Space',
    'no_limit' => 'No Limit',
    'unlimited' => 'Unlimited',
    'hint_unlimited' => '0 means unlimited',
    'name' => 'Server Name',
    'egg' => 'Egg',
    'left' => 'left',
    'variables' => 'Startup variables',

    'create_server' => 'Create server',
    'create_network_ports' => 'Create next network port',

    'choose_game_description' => 'Pick a game to get started. You can configure it on the next step.',
    'no_games' => 'No games available',
    'no_games_description' => 'There are no games you can deploy right now. Please contact the panel admin.',

    'configure_server_description' => 'Choose your setup, then deploy.',
    'deploy_server' => 'Deploy server',

    'choose_type' => 'Server software',
    'choose_type_description' => 'Which flavour of the game server do you want to run?',
    'choose_type_onboarding' => 'You choose this in the setup guide once the server exists, together with the Minecraft version - so the two always match. The guide opens by itself the first time you open your new server.',

    'choose_location' => 'Location',
    'choose_location_description' => 'Where should your server be hosted?',
    'location_unavailable' => 'No capacity for the selected resources',
    'no_locations' => 'No locations are configured. Please contact the panel admin.',

    'choose_plan' => 'Resources',
    'choose_plan_description' => 'Pick a plan, or switch to advanced for custom values.',
    'plan_unavailable' => 'Exceeds your remaining allowance',
    'advanced_resources' => 'Advanced',
    'advanced_resources_hint' => 'Set CPU, memory and disk manually instead of using a plan.',

    'server_details' => 'Details',
    'server_details_description' => 'Name your server and adjust its startup variables.',
    'eula_agree' => 'I agree to the Minecraft End User License Agreement (EULA)',
    'eula_description' => 'By creating this server, you agree to the Minecraft EULA (https://aka.ms/MinecraftEULA). The EULA will be automatically accepted on your server so it starts immediately without pausing.',
    'resize_plan_description' => 'Pick a plan, or switch to advanced to set custom values.',
    'plan_current' => 'Current plan',

    'change_version_description' => 'Switch to a different Minecraft version or server software.',
    'change_version_section' => 'Version and software',
    'change_version_section_description' => 'Pick the software and version to install. Applying reinstalls the server.',
    'change_version_apply' => 'Change and reinstall',
    'change_version_confirm' => 'Change version and reinstall?',
    'change_version_warning' => 'The server will be stopped and reinstalled with the selected software and version. World and configuration files are kept, but jar files and mod libraries are replaced. Mods and plugins built for the old version may stop working.',
    'server_software' => 'Server software',
    'minecraft_version' => 'Minecraft version',
    'minecraft_version_hint' => 'Only versions available for the selected software are listed.',
    'version_latest' => 'Latest (recommended)',

    'server_settings' => [
        'sections' => [
            'general' => 'General',
            'resources' => 'Resources',
            'startup' => 'Startup',
            'startup_command' => 'Startup command',
            'version' => 'Version',
        ],
        'jump_to' => 'Jump to',
        'save_resources' => 'Save resources',
    ],

    'content' => [
        'nav_plugins' => 'Plugins',
        'nav_mods' => 'Mods & Modpacks',
        'subheading_plugins' => 'Browse Modrinth and install plugins straight onto this server. Only plugins for your server software and Minecraft version are listed.',
        'subheading_mods' => 'Browse Modrinth and install mods and modpacks straight onto this server. Only content for your loader and Minecraft version is listed.',
        'search' => 'Search Modrinth...',
        'col_project' => 'Project',
        'col_author' => 'Author',
        'col_downloads' => 'Downloads',
        'col_categories' => 'Categories',
        'filter_kind' => 'Type',
        'filter_version' => 'Minecraft version',
        'kind_mod' => 'Mods',
        'kind_modpack' => 'Modpacks',
        'kind_plugin' => 'Plugins',
        'empty_heading' => 'Nothing found',
        'empty_body' => 'Try a different search term, or clear the Minecraft version filter - not every author lists every version they support.',
        'installed_badge' => 'Installed',
        'quick_install' => '1-Click Install',
        'versions' => 'Versions',
        'install' => 'Install',
        'install_heading' => 'Install :name',
        'install_submit' => 'Install',
        'version' => 'Version',
        'version_loose' => 'No build lists Minecraft :version, so all builds are shown. Take the newest one and test it - most work anyway.',
        'version_gone' => 'That version is no longer available on Modrinth.',
        'view' => 'View on Modrinth',
        'modpack_warning' => 'The server is switched to the loader and Minecraft version this pack needs, reinstalled, and then the pack files are placed. Your world is kept. The exact loader build of the pack cannot be pinned, so a pack that demands one specific build may still need manual work.',
        'client_only_warning' => 'The author marks this project as client-side only, so it may do nothing on a server.',
        'modpack_queued' => 'Modpack installation started',
        'modpack_queued_body' => ':name is being installed. The server is reinstalled first, then the pack files are placed - this takes a few minutes. You will be notified when it finishes.',
        'no_file' => 'That version has no downloadable file.',
        'pull_failed' => 'The server could not download the file. Please try again.',
        'installed' => 'Installation started',
        'installed_body' => ':file is being downloaded into :directory/. Restart the server to load it.',
        'modpack_done' => 'Modpack installed',
        'modpack_done_body' => ':name is installed and :count files were downloaded onto the server. Start it and watch the console.',
        'modpack_failed' => 'Modpack installation failed',
        'modpack_failed_body' => ':name could not be installed. Check the console and the file list before starting the server.',
        'modpack_reinstall_failed' => 'The server could not be reinstalled for this modpack, so none of the pack files were placed.',
        'modpack_broken' => 'That modpack file could not be read. It may use a format this panel does not support.',
        'modpack_unsupported' => 'That modpack needs a mod loader this server cannot install.',
        'modpack_download_failed' => 'The modpack could not be downloaded from Modrinth. Please try again.',
        'modpack_overrides_skipped' => 'The configuration files bundled with the pack were too large to apply automatically - download the pack from Modrinth and upload its overrides folder through the file manager.',
    ],

    // The typed editor for the config files a Minecraft server keeps in its own data
    // directory. Which files show up depends on the server software and on what it has
    // actually written, so a file being absent is ordinary rather than an error.
    'game_config' => [
        'nav_label' => 'Game Settings',
        'title' => 'Game Settings',
        'subheading' => 'Edit the configuration files on this server. Saved values take effect the next time it starts.',
        'empty_heading' => 'No configuration files yet',
        'empty_body' => 'None of the files this page can edit are on the server. Start it once so it writes them, then come back.',
        'unreachable' => 'The server could not be reached, so its configuration files could not be read. Please try again in a moment.',
        'general' => 'General',
        'properties_notice' => 'Comments, key order and any keys not shown here are kept exactly as they are. Ports and the RCON password are managed by the panel and cannot be edited.',
        'yaml_notice' => 'Saving this file drops the comments in it - the original is kept as :file. Lists and sections this form does not show are preserved.',
        'save' => 'Save',
        'raw_edit' => 'Edit as text',
        'raw_description' => 'Writes the file exactly as you leave it here. Nothing is checked, so a mistake can stop the server from starting.',
        'saved' => ':file saved',
        'saved_body' => 'Restart the server to apply the new values.',
        'no_changes' => 'Nothing to save',
        'no_changes_body' => 'This file already holds the values shown.',
        'failed' => 'Something went wrong',
        'read_failed' => 'The file could not be read from the server. Please try again.',
        'save_failed' => 'The file could not be written to the server, so nothing was changed.',
        'backup_failed' => 'A copy of the original could not be saved as :file, so the file was left untouched.',
        'invalid_number' => ':key has to be a number.',
        'invalid_choice' => '":value" is not a valid value for :key.',
        'restart' => 'Restart server',
        'restart_confirm' => 'Restart the server now?',
        'restart_warning' => 'The server stops and starts again so it reads the saved configuration. Anyone playing is disconnected while it restarts.',
        'restarting' => 'Server restarting',
        'restart_failed' => 'The server could not be restarted. Try again from the console.',
    ],

    // Whitelist and operators. A change reaches a running server through its console and a
    // stopped one through its files, and the wording has to keep that difference visible:
    // a file edit is only read on the next start.
    'players' => [
        'nav_label' => 'Players',
        'subheading' => 'Choose who may join this server and who has operator powers.',
        'unreachable_heading' => 'Player lists unavailable',
        'unreachable' => 'The server could not be reached, so its whitelist and operator list could not be read. Please try again in a moment.',
        'enforcement' => 'Whitelist',
        'enforcement_description' => 'While the whitelist is on, only the players listed below can join.',
        'enforced' => 'Only allow whitelisted players',
        'offline_mode' => 'This server does not check accounts with Mojang, so anyone can play under any name. The whitelist matches on the name alone.',
        'col_player' => 'Player',
        'col_whitelisted' => 'Whitelisted',
        'col_operator' => 'Operator',
        'op_level' => 'Level :level',
        'search' => 'Search players',
        'empty_heading' => 'No players yet',
        'empty_body' => 'Nobody is whitelisted or an operator on this server. Add a player to get started.',
        'whitelist' => 'Whitelist',
        'unwhitelist' => 'Remove from whitelist',
        'unwhitelist_confirm' => 'Remove :name from the whitelist?',
        'unwhitelist_warning' => 'They can no longer join, and are disconnected right away if they are playing.',
        'op' => 'Make operator',
        'deop' => 'Remove operator',
        'op_confirm' => 'Make :name an operator?',
        'deop_confirm' => 'Remove operator from :name?',
        'op_warning' => 'Operators can run commands, change the game and edit the world. Only give this to people you trust.',
        'deop_warning' => 'They keep their access to the server but lose every operator command.',
        'add' => 'Add player',
        'username' => 'Minecraft username',
        'username_hint' => 'The name has to be an existing Minecraft account.',
        'username_hint_offline' => 'This server does not check accounts, so the name is taken exactly as you type it.',
        'access' => 'Give them',
        'access_whitelist' => 'Access to the server',
        'access_operator' => 'Operator powers',
        'access_both' => 'Access and operator powers',
        'refresh' => 'Reload',
        'whitelisted' => ':name whitelisted',
        'unwhitelisted' => ':name removed from the whitelist',
        'opped' => ':name is now an operator',
        'deopped' => ':name is no longer an operator',
        'enforced_on' => 'Whitelist turned on',
        'enforced_off' => 'Whitelist turned off',
        'restart_note' => 'Saved to the server files - it applies the next time the server starts.',
        'failed' => 'Something went wrong',
        'unknown_user' => 'There is no Minecraft account called ":name", so nothing was changed.',
        'write_failed' => ':file could not be written to the server, so nothing was changed.',
        'properties_missing' => 'server.properties could not be read, so the whitelist setting was left as it was. Start the server once so it writes the file.',
    ],

    'plans' => [
        'starter' => 'Starter',
        'standard' => 'Standard',
        'pro' => 'Pro',
        'ultimate' => 'Ultimate',
        'extreme' => 'Extreme',
        'master' => 'Master',
    ],

    'game_types' => [
        'purpur' => [
            'label' => 'Vanilla / Paper / Spigot / Purpur',
            'description' => 'The standard server. Supports Bukkit, Spigot and Paper plugins.',
        ],
        'fabric' => [
            'label' => 'Fabric',
            'description' => 'Lightweight mod loader. Use for Fabric mods.',
        ],
        'neoforge' => [
            'label' => 'NeoForge',
            'description' => 'Modern Forge fork. Use for NeoForge mods.',
        ],
        'forge' => [
            'label' => 'Forge',
            'description' => 'The classic mod loader. Use for Forge mods and older modpacks.',
        ],
    ],

    'validation' => [
        'allocation_limit' => 'You can add :count more ports to this server.',
        'no_primary_allocation' => 'This server does not have a primary network allocation.',
        'no_available_port' => 'No available port was found after the current primary port.',
    ],

    'danger_zone' => 'Danger Zone',

    'admin_delete_user' => [
        'label' => 'Delete user & servers',
        'submit' => 'Delete user & servers',
        'heading' => 'Delete :username and their servers',
        'description' => 'This user owns :count server(s): :servers. Deleting the account destroys them and everything on them, including worlds, files, databases and backups. This cannot be undone.',
        'acknowledge' => 'Yes, permanently delete these :count server(s) and everything on them',
        'confirm_username' => 'Type the username (:username) to confirm',
        'force' => 'Delete even if a node does not respond',
        'force_helper' => 'The panel forgets each server even when its node cannot confirm the deletion. Use this only for a node that is gone for good: the container may be left running.',
    ],

    'modals' => [
        'delete_server_confirm' => 'Are you sure you want to delete this server?',
        'delete_server_warning' => 'This action cannot be undone and all data will be permanently lost.',
        'delete_server' => 'Delete Server',
    ],

    'notifications' => [
        'server_resources_updated' => 'Server Resource Limits updated',
        'might_need_restart' => 'To fully use the new resource limits a server restart might be required.',
        'manual_restart_needed' => 'Please manually restart your server to apply the new resource limits.',
        'server_resources_not_updated' => 'Resource limits not updated',

        'server_deleted' => 'Server Deleted',
        'server_deleted_success' => 'The server has been deleted successfully.',
        'server_delete_error' => 'Could not delete server',

        'change_version_started' => 'Reinstalling server',
        'change_version_started_body' => 'The selected software and version are being installed. This can take a few minutes.',
        'change_version_failed' => 'Could not change version',
        'invalid_loader' => 'That server software is not available for this game.',
        'unsupported_version' => 'Minecraft :version is not available for :loader.',

        'account_delete_failed' => 'Could not delete account',
        'account_delete_has_servers' => 'Cannot delete an account with active servers. Please delete your servers first.',
        'account_delete_last_admin' => 'Cannot delete the last root administrator.',
        'account_delete_server_failed' => 'The server ":server" could not be deleted (:error), so the account was left alone.',
        'account_delete_server_unreachable' => 'its node did not respond',
        'account_delete_partial' => ':count server(s) had already been removed before that happened.',
        'account_delete_servers_not_acknowledged' => 'Please confirm that the servers may be deleted.',
        'account_delete_username_mismatch' => 'That does not match the username.',
        'account_delete_unknown_error' => 'Unknown error. Please contact the panel admin.',
        'user_deleted' => 'User deleted',
        'user_deleted_body' => ':username and :count server(s) have been deleted.',

        'server_creation_failed' => 'Could not create server',
        'no_viable_node_found' => 'No viable node was found. Please contact the panel admin.',
        'no_viable_allocation_found' => 'No viable allocation was found. Please contact the panel admin.',
        'unknown_server_creation_error' => 'Unknown error. Please contact the panel admin.',
        'resource_limit_exceeded' => 'This would exceed your resource or server limit.',
        'invalid_location' => 'Please choose a valid location.',
        'no_plan_selected' => 'Please choose a plan, or switch to advanced and set custom values.',

        'ports_created' => 'Network ports created',
        'ports_created_body' => 'Port :address was added.',
        'ports_create_failed' => 'Could not create network ports',
        'ports_conflict' => 'One or more ports became unavailable. Please try again.',
        'ports_unknown_error' => 'The ports could not be created. Please contact the panel admin.',

        'primary_allocation_required' => 'Cannot remove primary allocation',
        'primary_allocation_required_body' => 'Assign another network allocation as primary before removing this one.',
        'locked_allocation_required' => 'Cannot remove locked allocation',
        'locked_allocation_required_body' => 'This network allocation is locked and cannot be removed.',
    ],

    // The waiting page a server sits on until its egg has installed. Deliberately free of
    // any percentage: wings reports no completion fraction, so a progress bar would be
    // invented. Phase, elapsed time and a clear failure state instead.
    'installing' => [
        'nav_label' => 'Setting up',
        'title' => 'Setting up your server',
        'title_reinstall' => 'Reinstalling your server',
        'title_failed' => 'Installation failed',
        'subheading' => ':server is being installed. This page updates itself and opens the panel as soon as it is ready.',
        'subheading_failed' => 'The install did not finish. Your files are untouched, but the server cannot start yet.',
        'status_body' => 'Most installs finish in a couple of minutes; larger modpacks take longer. You can close this tab and come back later.',
        'failed_body' => 'Something went wrong while installing. This is almost always a failed download or a version that is no longer available — get in touch and we will sort it out.',
        'elapsed' => 'Running for',
        'get_help' => 'Get help',
        'steps_heading' => 'Progress',
        'step_allocated' => 'Server created and resources reserved',
        'step_installing' => 'Downloading and installing :egg',
        'step_installing_generic' => 'Downloading and installing server files',
        'step_ready' => 'Starting up for the first time',
        'details_heading' => 'What is being set up',
        'detail_type' => 'Type',
        'while_you_wait' => 'While you wait',
        'ready_title' => 'Your server is ready',
        'ready_body' => ':server finished installing and can be started now.',
    ],

    // Feeds every perk-discovery surface through PluggedHost\Economy\Services\PerkDiscovery.
    // Amounts are substituted at runtime from the noprofit.space tiers and the store prices,
    // so no figure is restated here — change a tier and this copy follows.
    // The welcome tour (PluggedHost\Core\Livewire\PanelTourOverlay, steps in
    // Support\PanelTourSteps). It is drawn over the real panel, so every step sits next to the
    // thing it describes and names it by the label it carries on screen -- the whole point is
    // that someone told "go to Settings" can then find Settings.
    //
    // `panel` is the first part, on the server list; `server` the second, on a server's
    // console. `bullets` is an optional array; keep steps short, a wall of text is the thing
    // people skip.
    'tour' => [
        'title' => 'Welcome',
        'progress' => 'Step :step of :total',
        'acknowledge' => 'Show me around',
        'next' => 'Next',
        'back' => 'Back',
        'skip' => 'Skip tour',
        'finish' => 'Finish',
        'done_here' => 'Not now',
        'open_server' => 'Show me :name',
        'create_server' => 'Create my first server',
        'menu' => 'Replay the welcome tour',
        'panel' => [
            'welcome' => [
                'heading' => 'Welcome to :app',
                'body' => 'This is your game server panel: everything you rent from us lives here. We\'ll light up each part of it and tell you what it does. It takes about a minute.',
            ],
            'navigation' => [
                'heading' => 'The sidebar',
                'body' => 'Every page of your account is one click away here.',
                // Keyed by sidebar row: PanelTourSteps leaves out the rows that are not there.
                'bullets' => [
                    'home' => 'Home — your servers. You are on it now.',
                    'store' => 'Resource Store — more CPU, memory and disk for your credits.',
                    'plus' => ':app Plus — better locations, priority in the start queue and no in-game ads.',
                    'tokens' => 'Tokens — earn tokens and see where they went.',
                    'backups' => 'Backups — download a backup of any of your servers.',
                    'profile' => 'Profile Settings — your language, time zone and account.',
                ],
            ],
            'balance' => [
                'heading' => 'Your usage and balance',
                'body' => 'The bars show how much of your plan your servers use together. Underneath are your tokens and credits.',
                'bullets' => [
                    'Credits buy more resources in the Resource Store.',
                    'Tokens pay for :app Plus; earn them on the Tokens page.',
                    'Click the bars or the credits to open the Resource Store, the tokens to open the Tokens page.',
                ],
            ],
            'account' => [
                'heading' => 'Your account',
                'body' => 'Your name opens the account menu: your profile, signing out, and this tour if you want to see it again.',
            ],
            'create' => [
                'heading' => 'Create server',
                'body' => 'This makes a new server. Pick a game, a location and a size, and it is ready a minute later.',
            ],
            'servers' => [
                'heading' => 'Your servers',
                'body' => 'Every server you have is a card like this one. The bar on its left shows whether it is running, the numbers what it uses right now. Click a card to open that server — let\'s open :name together.',
            ],
            'empty' => [
                'heading' => 'Your servers',
                'body' => 'Your servers show up here, one card each, with whether they are running and what they use. You do not have one yet.',
            ],
            'create_first' => [
                'heading' => 'Create your first server',
                'body' => 'Start here: pick a game, a location and a size. The first time you open your new server, the tour shows you around inside it.',
            ],
        ],
        'server' => [
            'console' => [
                'heading' => 'The console',
                'body' => 'Your server\'s live output: everything it prints shows up here as it happens. Type a command in the line underneath and press Enter to run it.',
            ],
            'power' => [
                'heading' => 'Start, Restart, Stop',
                'body' => 'The power buttons for this server. Stop lets the game save and shut down cleanly; if it hangs while stopping, Stop turns into Kill.',
            ],
            'address' => [
                'heading' => 'Name, status and address',
                'body' => 'The Address is what players type to join. Click it to copy it, then send it to your friends. CPU, memory and disk are live.',
            ],
            'setup' => [
                'heading' => 'Finish the setup',
                'body' => 'This server still has its setup guide waiting: it picks the Minecraft version and software and gets the server ready to play on.',
            ],
            'sidebar' => [
                'heading' => 'Everything about this server',
                'body' => 'While a server is open, the sidebar belongs to it.',
                'bullets' => [
                    'Files — upload, edit and delete anything on the server.',
                    'Backups — take one before you change something, restore it if something breaks.',
                    'More — the pages you need less often: subdomains, network ports, databases, schedules, users and the activity log.',
                ],
            ],
            'sidebar_minecraft' => [
                'heading' => 'Everything about this server',
                'body' => 'While a server is open, the sidebar belongs to it.',
                'bullets' => [
                    'Files — upload, edit and delete anything on the server.',
                    'Plugins or Mods — install and update them from Modrinth, CurseForge and SpigotMC.',
                    'Worlds and Players — switch worlds, manage the whitelist, operators and bans.',
                    'Game Settings — the server.properties as a form.',
                    'Backups — take one before you change something, restore it if something breaks.',
                    'More — the pages you need less often: subdomains, network ports, databases and more.',
                ],
            ],
            'settings' => [
                'heading' => 'Settings',
                'body' => 'One page for everything about this server: rename it, give it more memory, change the version it runs, or reinstall it.',
            ],
            'home' => [
                'heading' => 'Back to your servers',
                'body' => 'The logo takes you back to your server list. That is the tour — replay it any time from the menu under your name.',
            ],
        ],
    ],

    // Admin settings form (PluggedHostCorePlugin, over EnvironmentWriterTrait). The sidebar
    // section carries its own inline labels; these keys cover the welcome-tour knobs.
    'settings' => [
        'section_tour' => 'Welcome tour',
        'section_tour_hint' => 'The first-login guide new users are walked through. Turning it off stops the redirect for everyone immediately.',
        'tour_enabled' => 'Show the welcome tour',
        'tour_enabled_hint' => 'Redirect users to the guided tour the first time they sign in.',
        'tour_version' => 'Tour version',
        'tour_version_hint' => 'Bump this to show the tour again to everyone who has already seen it. Leave it alone for wording fixes.',
    ],

    'suspended' => [
        'title' => 'Server suspended',
        'body' => 'This server is suspended. It cannot be started or managed until the suspension is lifted.',
        'by_abuse_scan' => 'Suspended :time by our automated abuse scan.',
        'by_admin' => 'Suspended :time by an administrator.',
        'by_system' => 'Suspended :time.',
        'deletes_at' => 'It will be deleted :time, with its files, unless the suspension is lifted before then.',
        'note_label' => 'Reason',
        'findings_label' => 'What the scan found',
        'more' => 'and :count more',
        'help' => 'If you think this is a mistake, ask us on Discord and include the server\'s name.',
        'help_admin' => 'If you think this is a mistake, contact the administrators of this panel and include the server\'s name.',
        'help_action' => 'Get help on Discord',
        'reason_field' => 'Reason',
        'reason_hint' => 'Shown to the server\'s owner on its suspended page.',
    ],

    'maintenance' => [
        'title' => 'Scheduled Maintenance',
        'badge' => 'Maintenance Mode',
        'heading' => "We'll be right back",
        'subheading' => 'We are currently performing scheduled maintenance and system upgrades to improve your experience. Services will resume shortly.',
        'status_active' => 'System Maintenance Active',
        'refresh_now' => 'Check Again',
        'refresh_auto' => 'Auto-checking in :seconds',
        'discord' => 'Discord Support',
        'status_page' => 'Public Status',
        'service_unavailable' => '503 • Service Temporarily Unavailable',
        'checking' => 'Checking…',
    ],

    'errors' => [
        'home' => 'Back to the panel',
        'status' => 'Public Status',
        'code' => 'Error :code',
        'titles' => [
            '400' => 'Bad request',
            '401' => 'Sign in required',
            '403' => 'No access',
            '404' => 'Page not found',
            '419' => 'Page expired',
            '429' => 'Too many requests',
            '500' => 'Something went wrong',
        ],
        'messages' => [
            '400' => 'This request could not be handled. Go back and try again.',
            '401' => 'Sign in to see this page.',
            '403' => "You don't have access to this page.",
            '404' => "This page doesn't exist or has moved.",
            '419' => 'This page was open too long. Reload it and try again.',
            '429' => 'Too many requests in a short time. Wait a moment, then try again.',
            '500' => 'We ran into a problem on our side. Try again in a moment.',
            'default' => 'Something went wrong with this request.',
        ],
    ],

    'admin_nav' => [
        'servers' => 'Servers',
        'users' => 'Users',
        'community' => 'Announcements & status',
        'subdomains' => 'Subdomains',
        'panel' => 'Panel',
        'advanced' => 'Advanced',
    ],
];
