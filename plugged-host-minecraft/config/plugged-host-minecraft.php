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
| Modrinth remains the zero-config default.
|
*/

return [
    'curseforge_api_key' => env('CURSEFORGE_API_KEY', ''),

    // Cap on the number of files extracted from a CurseForge modpack's overrides/ tree,
    // mirroring InstallModpackJob's own guard against a hostile archive.
    'overrides_limit' => (int) env('MINECRAFT_OVERRIDES_LIMIT', 2000),
];
