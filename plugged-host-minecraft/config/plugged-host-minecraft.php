<?php

/*
|--------------------------------------------------------------------------
| plugged-host-minecraft
|--------------------------------------------------------------------------
|
| Onboarding, content browser, modpacks and mod detection.
|
| CurseForge (WP12) needs a server-wide Core API key. Without it, CurseForge
| search / modpack install / fingerprint detection degrade cleanly and
| Modrinth remains the zero-config default. SpigotMC (plugins only, via
| Spiget) needs no key and can be switched off.
|
*/

return [
    'curseforge_api_key' => env('CURSEFORGE_API_KEY', ''),

    // SpigotMC plugins on Paper-family servers, read through Spiget (no key). See SpigotCatalog.
    'spigotmc_enabled' => (bool) env('MINECRAFT_SPIGOTMC_ENABLED', true),

    // Cap on the number of files extracted from a CurseForge modpack's overrides/ tree,
    // mirroring InstallModpackJob's own guard against a hostile archive (Modrinth packs have
    // a fixed 96 MiB cap on theirs instead).
    'overrides_limit' => (int) env('MINECRAFT_OVERRIDES_LIMIT', 2000),

    // The User-Agent sent to Modrinth, CurseForge, Spiget, Mojang and the loaders' APIs, which
    // ask for one that names the application and a contact. Empty: "<app name>-panel/<release>
    // (<mail.from.address, else the panel URL>)", see Support\UserAgent.
    'user_agent' => (string) env('MINECRAFT_USER_AGENT', ''),
];
