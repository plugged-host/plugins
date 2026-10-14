<?php

return [
    /*
    | The image FiveM servers run in (plugged-host-fivem/image, published by the fivem-image
    | workflow). `php artisan plugged-host:fivem-setup` makes it the cfx eggs' default and their
    | install container, and moves existing servers onto it.
    */
    'image' => env('FIVEM_IMAGE', 'ghcr.io/plugged-host/fivem:2.2'),

    /*
    | Cfx's two sources: the changelog API answers "which build is recommended / latest", the
    | download page lists every Linux build it still offers (and the one Enhanced build).
    */
    'changelog_api' => 'https://changelogs-live.fivem.net/api/changelog/versions/linux/server',
    'downloads_page' => 'https://docs.fivem.net/docs/server-download/?platform=legacy&os=linux',

    /*
    | txAdmin's official recipe index: the framework installer offers what it lists for the
    | server's game (fivem / redm tags).
    */
    'recipe_index' => env('FIVEM_RECIPE_INDEX', 'https://raw.githubusercontent.com/tabarra/txAdmin-recipes/main/indexv4.json'),

    /*
    | Whether owners may switch to FiveM for GTAV Enhanced. Its bundled txAdmin (v9 beta, build
    | 168) needs io_uring and hangs at start when the container may not use it, which Docker's
    | default seccomp profile (Docker 25+) forbids. Turn this on once the nodes' Docker allows the
    | io_uring_setup/enter/register syscalls. Switching back to Legacy is always allowed.
    */
    'enhanced' => (bool) env('FIVEM_ENHANCED', false),

    // How long Cfx's lists and the recipe index are cached, in seconds.
    'cache_ttl' => 600,

    // How often the player list refreshes itself, in seconds.
    'players_poll' => 12,
];
