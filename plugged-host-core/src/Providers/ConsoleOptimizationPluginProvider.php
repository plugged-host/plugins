<?php

namespace PluggedHost\Core\Providers;

use App\Filament\Server\Widgets\ServerCpuChart;
use App\Filament\Server\Widgets\ServerMemoryChart;
use App\Filament\Server\Widgets\ServerNetworkChart;
use App\Filament\Server\Widgets\ServerOverview;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Slows the console page's stat widgets from Pelican's 1s poll to 5s.
 *
 * Every open console tab polls these four widgets, and every poll is a full panel request:
 * at 1s a couple of dozen open consoles kept all PHP-FPM workers busy (2026-10-10, ~20
 * Livewire requests/s, 4 cores pinned all day).
 *
 * Swapping in subclasses does not work, and did nothing from 1.2.0 until this fix:
 * `Livewire::component(Upstream::class, Lighter::class)` is overwritten when Filament
 * registers each widget under its own class name at panel boot, and Livewire 4 builds a
 * class-named component with `new $class` (no alias lookup, no container). So the interval
 * is set on the upstream instance itself, in the `render` hook, which fires before the view
 * reads `getPollingInterval()` into `wire:poll` — on the first render and on every update.
 */
class ConsoleOptimizationPluginProvider extends ServiceProvider
{
    protected const POLLING_INTERVAL = '5s';

    /** @var class-string[] */
    protected const WIDGETS = [
        ServerOverview::class,
        ServerCpuChart::class,
        ServerMemoryChart::class,
        ServerNetworkChart::class,
    ];

    public function boot(): void
    {
        Livewire::listen('render', function (object $component): void {
            foreach (self::WIDGETS as $widget) {
                if ($component instanceof $widget) {
                    $interval = self::POLLING_INTERVAL;

                    // Bound closure because the property is protected and has no setter.
                    (function () use ($interval): void {
                        $this->pollingInterval = $interval;
                    })->call($component);

                    return;
                }
            }
        });
    }
}
