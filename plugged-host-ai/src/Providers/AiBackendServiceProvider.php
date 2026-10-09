<?php

namespace PluggedHost\Ai\Providers;

use Illuminate\Support\ServiceProvider;
use PluggedHost\Ai\Chat\ConfiguredProviders;
use PluggedHost\Ai\Contracts\NeoBackend;
use PluggedHost\Ai\Contracts\NeoSettingsStore;
use PluggedHost\Ai\Contracts\ProviderSource;
use PluggedHost\Ai\Services\EnvNeoSettingsStore;
use PluggedHost\Ai\Services\LocalNeoBackend;

/**
 * neo's AI on this panel unless plugged-host-noprofit binds noprofit.space's (singletonIf:
 * either may register first): the providers rux and neo call, where neo's turns run and are
 * metered, and where the settings form keeps providers and limits.
 */
class AiBackendServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singletonIf(ProviderSource::class, ConfiguredProviders::class);
        $this->app->singletonIf(NeoBackend::class, LocalNeoBackend::class);
        $this->app->singletonIf(NeoSettingsStore::class, EnvNeoSettingsStore::class);
    }
}
