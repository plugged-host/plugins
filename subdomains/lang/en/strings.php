<?php

return [
    'no_domains' => 'No Domains',
    'domain' => 'Domain|Domains',

    'no_subdomains' => 'No Subdomains',
    'subdomain' => 'Subdomain|Subdomains',
    'limit' => 'Limit',
    'change_limit' => 'Change limit',
    'limit_changed' => 'Limit changed',
    'limit_reached' => 'Subdomain limit reached',
    'create_subdomain' => 'Create Subdomain',

    'name' => 'Name',
    'record_type' => 'Record type',
    'is_synced' => 'Is Synced?',
    'srv_target' => 'SRV target',
    'no_srv_target' => 'No SRV target',

    'sync' => 'Sync',

    'api_token' => 'Cloudflare API Token',
    'api_token_help' => 'The token needs to have read permissions for Zone.Zone and write for Zone.Dns. For better security you can also set the "Zone Resources" to exclude certain domains and add the panel ip to the "Client IP Address Filtering".',
    'api_token_set_help' => 'A Cloudflare API token is currently set. Leave this blank to keep it unchanged, or enter a new token to replace it.',
    'api_token_unset_help' => 'No Cloudflare API token is set yet. Enter one to enable Cloudflare DNS synchronisation.',
    'secret_unchanged' => '(unchanged)',
    'secret_unset' => 'Not set',

    'srv_service_section' => 'SRV Service Detection',
    'srv_service_section_help' => 'Map Egg IDs to service types so the correct SRV records (e.g. Minecraft or FiveM) are created automatically.',
    'minecraft_eggs' => 'Minecraft Egg IDs',
    'minecraft_eggs_help' => 'Comma-separated list of Egg IDs treated as Minecraft servers for SRV record creation. Empty: eggs tagged "minecraft" (not Bedrock or proxies).',
    'fivem_eggs' => 'FiveM Egg IDs',
    'fivem_eggs_help' => 'Comma-separated list of Egg IDs treated as FiveM servers for SRV record creation. Empty: eggs tagged "cfx".',

    'subdomain_blacklist' => 'Subdomain Blacklist',
    'subdomain_blacklist_help' => "Users won't be able to create subdomains with these entries. Patterns with '*' are supported, e.g. 'test*' will block all subdomains that start with 'test'.",

    'notifications' => [
        'synced' => 'Domain synced with cloudflare',
        'not_synced' => 'Could not sync domain with cloudflare',
    ],

    'validation' => [
        'on_blacklist' => 'The :attribute is not allowed.',
    ],
];
