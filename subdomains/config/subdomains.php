<?php

return [
    'token' => env('SUBDOMAINS_CLOUDFLARE_TOKEN', env('CLOUDFLARE_TOKEN')),
    'default_limit' => (int) env('SUBDOMAINS_DEFAULT_LIMIT', 3),
    'token_costs' => [
        1 => (int) env('SUBDOMAINS_TOKEN_COST_1', 1),
        2 => (int) env('SUBDOMAINS_TOKEN_COST_2', 3),
        3 => (int) env('SUBDOMAINS_TOKEN_COST_3', 8),
        'default' => (int) env('SUBDOMAINS_TOKEN_COST_DEFAULT', 15),
    ],
    'subdomain_blacklist' => filled(env('SUBDOMAINS_BLACKLIST'))
        ? env('SUBDOMAINS_BLACKLIST')
        : 'www,panel,admin,api,auth,login,billing,client,node*,dash*,status,cp,webmail,mail,email,smtp,pop,imap,ns*,dns*,vpn,ssl,secure,autodiscover,ftp,ssh,sftp,pma,mysql,database,proxy,gateway,cloud*,dev,stage,staging,test,prod,internal,support,help,shop,store,docs,legal,terms,privacy,cdn,assets,static,app,bot,discord,fivem,minecraft,mc,plugged',
    'egg_mappings' => [
        'minecraft' => array_filter(array_map('intval', explode(',', env('SUBDOMAINS_MINECRAFT_EGGS', '12')))),
        'fivem' => array_filter(array_map('intval', explode(',', env('SUBDOMAINS_FIVEM_EGGS', '11')))),
    ],
    'supported_eggs' => array_filter(array_map('intval', explode(',', env('SUBDOMAINS_SUPPORTED_EGGS', '11,12')))),
    'dual_records' => (bool) env('SUBDOMAINS_DUAL_RECORDS', true),
    'require_discord' => (bool) env('SUBDOMAINS_REQUIRE_DISCORD', true),
    'require_discord_guild' => (bool) env('SUBDOMAINS_REQUIRE_DISCORD_GUILD', true),
];
