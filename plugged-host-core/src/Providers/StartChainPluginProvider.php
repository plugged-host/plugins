<?php

namespace PluggedHost\Core\Providers;

use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Power\StartChain;
use Throwable;

use function Livewire\before;

/**
 * The one Livewire hook in front of every server start (see StartChain). Plugins add their
 * gates to the StartChain singleton from their own providers.
 */
class StartChainPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StartChain::class);
    }

    public function boot(): void
    {
        try {
            // `before` and not `Livewire::listen`: listeners added with listen() land behind
            // Livewire's own component-hook listener, and the hook that handles `__dispatch`
            // calls the listener method itself; by the time a late listener ran, ListServers
            // would already have told wings to start the container.
            before('call', function ($component, $method, $params, $context, $returnEarly) {
                app(StartChain::class)->handle($component, $method, $params, $returnEarly);
            });
        } catch (Throwable) {
            // No interception rather than a panel that will not boot.
        }
    }
}
