<?php

namespace PluggedHost\Servers\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Servers\Http\Controllers\CreateServerController;
use PluggedHost\Servers\Support\AccountAllowance;
use PluggedHost\Servers\Services\GameCatalog;
use PluggedHost\Servers\Services\LocationCatalog;
use PluggedHost\Servers\Services\PlanCatalog;

/**
 * Client-API provisioning endpoints (WP7): the read side of the create page plus a create call,
 * under /api/client/provision. User-scoped (not server-scoped), so no ServerSubject stack — the
 * client-api middleware resolves the API key to a user. Scramble documents them by path prefix.
 *
 * Reproduces RouteServiceProvider's client stack exactly.
 */
class ServersApiProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['api', 'client-api', 'throttle:api.client'])
            ->prefix('/api/client')
            ->scopeBindings()
            ->group(function (): void {
                Route::prefix('/provision')->group(function (): void {
                    Route::get('/games', function (Request $request) {
                        return response()->json(['data' => app(GameCatalog::class)->games()]);
                    })->name('api:client:servers.provision.games');

                    Route::get('/plans', function (Request $request) {
                        $user = $request->user();
                        $limits = AccountAllowance::forUser($user);

                        return response()->json(['data' => $limits ? app(PlanCatalog::class)->forUser($user, $limits) : []]);
                    })->name('api:client:servers.provision.plans');

                    Route::get('/locations', function (Request $request) {
                        // Flag presence, latency, tier, whether this account may use it and what
                        // it requires (Plus locations: plugged.host Plus; Premium: PlusPlus).
                        // `premium` is kept for older clients: true only for the premium tier.
                        return response()->json(['data' => app(LocationCatalog::class)
                            ->available(user: $request->user())
                            ->map(fn (array $l) => [
                                'code' => $l['code'],
                                'name' => $l['name'],
                                'node_count' => $l['node_count'],
                                'latency' => $l['latency'],
                                'available' => $l['available'],
                                'tier' => $l['tier'],
                                'premium' => $l['tier'] === LocationCatalog::PREMIUM,
                                'unlocked' => $l['unlocked'],
                                'requires' => $l['requires'] ?? null,
                            ])]);
                    })->name('api:client:servers.provision.locations');

                    // POST /provision/servers — create via API. A dedicated FormRequest +
                    // controller rather than a closure, because it must reproduce
                    // ServerProvisioner::prepare()'s server-side plan/location resolution and
                    // AccountAllowance gating exactly. See CreateServerController.
                    Route::post('/servers', CreateServerController::class)
                        ->name('api:client:servers.provision.create');
                });
            });
    }
}
