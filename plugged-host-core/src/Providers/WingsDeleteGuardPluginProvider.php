<?php

namespace PluggedHost\Core\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Nodes\WingsDeleteGuard;

/** Wraps the panel's calls to wings so deleting a server cannot crash wings (see WingsDeleteGuard). */
class WingsDeleteGuardPluginProvider extends ServiceProvider
{
    public function boot(): void
    {
        Http::globalMiddleware(new WingsDeleteGuard());
    }
}
