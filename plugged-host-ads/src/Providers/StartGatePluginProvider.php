<?php

namespace PluggedHost\Ads\Providers;

use Illuminate\Support\ServiceProvider;
use PluggedHost\Ads\Services\PowerActionInterceptor;
use PluggedHost\Ads\Services\StartGateService;
use Throwable;

use function Livewire\before;

/**
 * Wiring for the start gate — the Livewire power-action interception behind the ads.
 *
 * Picked up automatically: PluginService registers every class in src/Providers/,
 * so there is no list anywhere that has to learn about this file. It was called
 * StartCreditsPluginProvider until the gate became the centre of the feature; the
 * name has followed the gate ever since, including through the 2026-08-31 rename of
 * start credits to tokens. Because registration is by directory scan, two names
 * present at once would register the Livewire hook twice — every gated start
 * intercepted twice — so a renamed file must never be left beside its replacement.
 *
 * Singletons because the gate service is consulted by the interceptor on every
 * power click and then again by the page it redirects to, and both of those read
 * the same config and cache keys. Nothing here holds request state.
 *
 * The off-site earning that used to be wired here — LinkvertiseProvider, RewardRegistry,
 * TokenEarnService, and the earn render hooks — moved to plugged-host-economy in the 1.2.0
 * re-division (EarnPluginServiceProvider): tokens are economy's, the gate is ads'. What is
 * left here is only the gate's trigger.
 */
class StartGatePluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StartGateService::class);
        $this->app->singleton(PowerActionInterceptor::class);
    }

    public function boot(): void
    {
        try {
            // `before` and not `Livewire::listen`: listeners added with listen()
            // land behind Livewire's own component-hook listener, and the hook that
            // handles `__dispatch` calls the listener method itself — by the time a
            // late listener ran, ListServers would already have told wings to start
            // the container. See PowerActionInterceptor's docblock.
            before('call', function ($component, $method, $params, $context, $returnEarly) {
                app(PowerActionInterceptor::class)->handle($component, $method, $params, $returnEarly);
            });
        } catch (Throwable) {
            // No interception rather than a panel that will not boot.
        }
    }
}
