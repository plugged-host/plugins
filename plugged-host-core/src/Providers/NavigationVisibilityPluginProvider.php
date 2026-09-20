<?php

namespace PluggedHost\Core\Providers;

use Filament\Navigation\NavigationManager;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Filament\HiddenNavigationManager;

/**
 * Lets an admin hide sidebar entries without an edit to app/.
 *
 * Filament binds NavigationManager with `$this->app->scoped()` in its own service
 * provider (vendor/filament/filament/src/FilamentServiceProvider.php:75). Package
 * providers register before application providers, and plugins are loaded from inside
 * AppServiceProvider::register(), so re-registering the binding here reliably wins —
 * the same ordering the OAuth/UserCreationService rebinds depend on.
 *
 * scoped(), not singleton(): Filament resets the instance per request/Octane context, and
 * the manager caches whether navigation has been mounted. A singleton would carry one
 * panel's mounted items into the next request.
 */
class NavigationVisibilityPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(NavigationManager::class, fn (): NavigationManager => new HiddenNavigationManager());
    }
}
