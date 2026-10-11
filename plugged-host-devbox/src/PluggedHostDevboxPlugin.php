<?php

namespace PluggedHost\Devbox;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Devboxes: VS Code on the server for Plus (see Providers\DevboxPluginProvider). Its UI is the
 * "Open in VS Code" part of the server settings' VS Code section (Livewire\OpenInVsCode); the
 * endpoints, schedule and cleanup run regardless of panel.
 */
class PluggedHostDevboxPlugin implements Plugin
{
    public function getId(): string
    {
        return 'plugged-host-devbox';
    }

    public function register(Panel $panel): void {}

    public function boot(Panel $panel): void {}
}
