<?php

namespace PluggedHost\Ads\Providers;

use Illuminate\Support\ServiceProvider;
use PluggedHost\Ads\Services\AdStartGate;
use PluggedHost\Ads\Services\StartGateService;
use PluggedHost\Core\Power\StartChain;

/**
 * Wiring for the start gate: the ad-funded waiting room, gate 30 in core's StartChain (which
 * owns the Livewire power-action hook since 2.2).
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
        $this->app->singleton(AdStartGate::class);
    }

    public function boot(): void
    {
        app(StartChain::class)->register('plugged-host-ads', 30, AdStartGate::class);
    }
}
