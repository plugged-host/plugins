<?php

namespace PluggedHost\Queue\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Queue\Console\Commands\QueueSweepCommand;
use PluggedHost\Queue\Filament\Server\Pages\StartQueuePage;
use PluggedHost\Queue\Services\PriorityPassService;
use Throwable;

/**
 * The queue's only service provider: the sweep schedule, and two lines of
 * cooperation with plugged-host-ads.
 *
 * What is *not* here is the reason this plugin is small. The interception itself — the
 * Livewire hook that turns a Start click into a place in the line — lives in ads'
 * `PowerActionInterceptor`, because Livewire's `EventBus::trigger()` runs every
 * `before('call')` listener whatever the first one returns, so two plugins hooking the
 * same call would each cancel it and only one of the two answers would survive. Ads got
 * there first, so ads owns the hook and asks this plugin by class name before it does
 * anything of its own.
 */
class QueuePluginProvider extends ServiceProvider
{
    /** Ads' registry of interstitial pages, and of who is excused from advertising. */
    protected const ADS_DISPLAY = 'PluggedHost\\Ads\\Services\\AdDisplayService';

    public function register(): void
    {
        // Scoped, not singleton: the pass service memoises the priority window because
        // the ad-exemption check reads it on every render, and a memo that outlived the
        // request would still be answering "no pass" an hour after one was bought. The
        // container resets scoped bindings between requests and between queue jobs,
        // which is exactly the lifetime that memo is correct for.
        $this->app->scoped(PriorityPassService::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // The sweep is what makes the waiting page optional. A user who presses
            // Start and closes the tab has still asked for a start, and this is what
            // delivers it. withoutOverlapping because a slow node reading must not
            // stack two sweeps releasing the same entry.
            $schedule->command(QueueSweepCommand::class)
                ->everyMinute()
                ->withoutOverlapping(5);
        });
    }

    public function boot(): void
    {
        $this->registerAsInterstitial();
        $this->injectActivityDescriptions();
        $this->registerClientApi();
    }

    /**
     * Client-API (WP7): GET /api/client/servers/{server}/queue — position, wait, reason, node
     * slot status. Server-scoped, standard middleware, Scramble-documented by path prefix.
     */
    protected function registerClientApi(): void
    {
        \Illuminate\Support\Facades\Route::middleware(['api', 'client-api', 'throttle:api.client'])
            ->prefix('/api/client')
            ->scopeBindings()
            ->group(function (): void {
                \Illuminate\Support\Facades\Route::prefix('/servers/{server:uuid}')
                    ->middleware([
                        \App\Http\Middleware\Activity\ServerSubject::class,
                        \App\Http\Middleware\Api\Client\Server\AuthenticateServerAccess::class,
                        \App\Http\Middleware\Api\Client\Server\ResourceBelongsToServer::class,
                    ])
                    ->group(function (): void {
                        \Illuminate\Support\Facades\Route::get('/queue', function (\App\Models\Server $server) {
                            $svc = app(\PluggedHost\Queue\Services\StartQueueService::class);
                            $entry = $svc->waitingEntry($server);

                            return response()->json(['data' => [
                                'waiting' => $entry !== null,
                                'position' => $svc->position($entry),
                                'wait_remaining' => $svc->remaining($entry),
                                'node_slot_free' => $entry ? $svc->nodeSlotFree((int) $entry->node_id) : null,
                            ]]);
                        })->name('api:client:queue.status');
                    });
            });
    }

    /**
     * Human-readable descriptions for the queue's activity events, injected into the '*'
     * namespace so they render as sentences rather than raw dotted keys (WP7, fact 13).
     */
    protected function injectActivityDescriptions(): void
    {
        foreach (['en', 'de'] as $locale) {
            try {
                $lines = trans('plugged-host-queue::activity', [], $locale);

                if (is_array($lines)) {
                    \Illuminate\Support\Facades\Lang::addLines($lines, $locale);
                }
            } catch (\Throwable) {
                //
            }
        }
    }

    /**
     * Tell plugged-host-ads that the waiting page is an interstitial.
     *
     * Two things follow from that registration, both of which we want. Ads keeps its
     * generic in-content unit out of this document — the waiting page places its own
     * units deliberately, above and below the two offers, and the same zone twice in one
     * page splits the fill and is exactly what a network audits for. And ads serves its
     * page-level scripts (social bar, popunder) here as it does on its own gate, which
     * is the point of a screen somebody is knowingly spending time on.
     *
     * By string and behind class_exists, like every other reach into ads from here: the
     * queue works with the ads plugin absent, it is just quieter.
     */
    protected function registerAsInterstitial(): void
    {
        $display = self::ADS_DISPLAY;

        if (!Plugins::available($display) || !method_exists($display, 'registerInterstitialRoute')) {
            return;
        }

        try {
            $display::registerInterstitialRoute(static fn (): bool => StartQueuePage::isCurrentRoute());
        } catch (Throwable) {
            // A registry that will not take our callback costs us an ad placement
            // decision, not a queue. Nothing here is allowed to break a boot.
        }
    }
}
