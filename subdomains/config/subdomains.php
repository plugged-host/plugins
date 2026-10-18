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
        : 'www,panel,admin,api,auth,login,billing,client,node*,dash*,status,cp,webmail,mail,email,smtp,pop,imap,ns*,dns*,vpn,ssl,secure,autodiscover,ftp,ssh,sftp,pma,mysql,database,proxy,gateway,cloud*,dev,stage,staging,test,prod,internal,support,help,shop,store,docs,legal,terms,privacy,cdn,assets,static,app,bot,discord,fivem,minecraft,mc',
    // Egg ids per SRV service. Empty: eggs are recognised by their tags and features instead
    // (SRVServiceType::fromServer(): `minecraft` = Minecraft Java, `cfx` = FiveM / RedM).
    'egg_mappings' => [
        'minecraft' => array_values(array_filter(array_map('intval', explode(',', (string) env('SUBDOMAINS_MINECRAFT_EGGS', ''))))),
        'fivem' => array_values(array_filter(array_map('intval', explode(',', (string) env('SUBDOMAINS_FIVEM_EGGS', ''))))),
    ],
    // Egg ids that may have subdomains. Empty: every egg recognised as Minecraft Java or
    // FiveM / RedM (Subdomain::isServerEggSupported()).
    'supported_eggs' => array_values(array_filter(array_map('intval', explode(',', (string) env('SUBDOMAINS_SUPPORTED_EGGS', ''))))),
    'require_discord' => (bool) env('SUBDOMAINS_REQUIRE_DISCORD', true),
    // Only applies while plugged-host-discord is enabled (it is the one that knows who is in the server).
    'require_discord_guild' => (bool) env('SUBDOMAINS_REQUIRE_DISCORD_GUILD', true),
];
