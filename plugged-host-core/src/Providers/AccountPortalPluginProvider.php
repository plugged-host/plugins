<?php

namespace PluggedHost\Core\Providers;

use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Contracts\AccountPortal;
use PluggedHost\Core\Services\PanelAccountPortal;

/**
 * The standalone account portal. singletonIf: plugged-host-noprofit binds its own, and which
 * provider registers first must not matter.
 */
class AccountPortalPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singletonIf(AccountPortal::class, PanelAccountPortal::class);
    }
}
