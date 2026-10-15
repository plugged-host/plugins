<?php

namespace PluggedHost\Core\Providers;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Http\Middleware\ForceJsonOnApi;

/**
 * Puts ForceJsonOnApi in front of every request, as global middleware rather than in the `api`
 * group: requests that match no route (a mistyped /api path) never reach a group, and they
 * should get a JSON 404 too. The kernel's global list is set when it is resolved, before
 * providers boot, so prepending here adds to it.
 */
class ApiResponsesPluginProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        $kernel = $this->app->make(Kernel::class);

        if (method_exists($kernel, 'prependMiddleware')) {
            $kernel->prependMiddleware(ForceJsonOnApi::class);
        }
    }
}
