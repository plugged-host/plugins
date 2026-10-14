<?php

namespace PluggedHost\FiveM;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * FiveM plugin: server.cfg as a form, one-click frameworks (txAdmin's recipes), artifact
 * updates with auto-update, FiveM for GTAV Enhanced, and a live player list, for servers whose
 * egg is tagged `cfx`. The artifact work itself happens in the plugged.host FiveM image
 * (image/fivem-runtime); these pages set what it should do.
 *
 * Per-panel discovery mirrors the other plugins', minus the topbar (theme-v2 owns it).
 */
class PluggedHostFiveMPlugin implements Plugin
{
    public function getId(): string
    {
        return 'plugged-host-fivem';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "PluggedHost\\FiveM\\Filament\\$id\\Pages");
    }

    public function boot(Panel $panel): void {}
}
