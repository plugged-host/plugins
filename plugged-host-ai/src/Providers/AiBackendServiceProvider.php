<?php

namespace PluggedHost\Ai\Providers;

use Illuminate\Support\ServiceProvider;
use PluggedHost\Ai\Chat\ConfiguredProviders;
use PluggedHost\Ai\Contracts\ProviderSource;

/**
 * neo's AI on this panel unless plugged-host-noprofit binds noprofit.space's (singletonIf:
 * either may register first): the providers rux and neo call.
 */
class AiBackendServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singletonIf(ProviderSource::class, ConfiguredProviders::class);
    }
}
