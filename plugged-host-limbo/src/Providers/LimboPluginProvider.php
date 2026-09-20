<?php

namespace PluggedHost\Limbo\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Limbo\Console\Commands\LimboSweepCommand;
use PluggedHost\Limbo\Filament\Server\Pages\RestoreFromLimboPage;
use Throwable;

/**
 * Wires limbo: the minute idle sweep, the human-readable activity descriptions (injected with
 * Lang::addLines so no upstream lang file is touched, fact 13), and registration of the restore
 * page as an ad interstitial (ads → limbo, by class-string so ads can be uninstalled alone).
 */
class LimboPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // Scheduled unconditionally; the command itself honours LIMBO_ENABLED and the missing
            // table, so it is a cheap no-op while the feature is dark.
            $schedule->command(LimboSweepCommand::class)
                ->everyMinute()
                ->withoutOverlapping(10)
                ->name('plugged-host-limbo:limbo-sweep');
        });
    }

    public function boot(): void
    {
        $this->injectActivityDescriptions();
        $this->registerInterstitial();
        $this->registerApiRoutes();
    }

    /**
     * Client-API endpoints (WP7). Reproduces RouteServiceProvider's client stack so Scramble
     * documents them by path prefix with no core edit.
     *
     * The restore endpoint deliberately lives OUTSIDE AuthenticateServerAccess (fact 15): that
     * middleware refuses a suspended server, and a limbo server IS suspended, so it would 403 the
     * one call that has to work on it. Authorization is a direct owner check instead — a subuser
     * with start permission has no business spending the owner's tokens.
     */
    private function registerApiRoutes(): void
    {
        \Illuminate\Support\Facades\Route::middleware(['api', 'client-api', 'throttle:api.client'])
            ->prefix('/api/client')
            ->scopeBindings()
            ->group(function (): void {
                \Illuminate\Support\Facades\Route::get('/limbo', function (\Illuminate\Http\Request $request) {
                    $limbo = app(\PluggedHost\Limbo\Services\LimboService::class);
                    if (!$limbo->tableReady()) {
                        return response()->json(['data' => []]);
                    }
                    $rows = \PluggedHost\Limbo\Models\ServerLimboState::query()
                        ->whereIn('server_id', \App\Models\Server::query()->where('owner_id', $request->user()->id)->pluck('id'))
                        ->get(['server_id', 'state', 'limbo_at', 'restore_cost']);

                    return response()->json(['data' => $rows]);
                })->name('api:client:limbo.index');

                \Illuminate\Support\Facades\Route::get('/limbo/{server:uuid}', function (\Illuminate\Http\Request $request, \App\Models\Server $server) {
                    abort_unless((int) $server->owner_id === (int) $request->user()->id, 404);
                    $state = app(\PluggedHost\Limbo\Services\LimboService::class)->state($server);
                    abort_if($state === null, 404);

                    return response()->json(['data' => $state->only(['state', 'limbo_at', 'restore_cost', 'restored_at'])]);
                })->name('api:client:limbo.show');

                \Illuminate\Support\Facades\Route::post('/limbo/{server:uuid}/restore', function (\Illuminate\Http\Request $request, \App\Models\Server $server) {
                    // Owner-only, and NOT gated by AuthenticateServerAccess (the server is suspended).
                    abort_unless((int) $server->owner_id === (int) $request->user()->id, 404);
                    $result = app(\PluggedHost\Limbo\Services\LimboService::class)->restore($request->user(), $server);

                    return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
                })->name('api:client:limbo.restore');
            });
    }

    /**
     * Give the custom activity events human-readable descriptions in both locales without
     * editing upstream's activity lang file (fact 13).
     */
    private function injectActivityDescriptions(): void
    {
        foreach (['en', 'de'] as $locale) {
            try {
                $lines = trans('plugged-host-limbo::activity', [], $locale);

                if (is_array($lines)) {
                    Lang::addLines($lines, $locale);
                }
            } catch (Throwable) {
                //
            }
        }
    }

    /**
     * Register the restore page for the waiting-room ad treatment instead of the generic slot.
     * By class-string behind class_exists so limbo does not hard-depend on ads.
     */
    private function registerInterstitial(): void
    {
        $service = 'PluggedHost\\Ads\\Services\\AdDisplayService';

        if (!class_exists($service) || !method_exists($service, 'registerInterstitialRoute')) {
            return;
        }

        try {
            // Same shape as the queue's waiting page: a callable the registry consults per render.
            $service::registerInterstitialRoute(static fn (): bool => RestoreFromLimboPage::isCurrentRoute());
        } catch (Throwable) {
            //
        }
    }
}
