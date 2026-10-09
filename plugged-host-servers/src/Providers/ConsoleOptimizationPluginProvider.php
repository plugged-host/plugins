<?php

namespace PluggedHost\Servers\Providers;

use App\Filament\Server\Widgets\ServerCpuChart;
use App\Filament\Server\Widgets\ServerMemoryChart;
use App\Filament\Server\Widgets\ServerNetworkChart;
use App\Filament\Server\Widgets\ServerOverview;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use PluggedHost\Servers\Filament\Server\Widgets\OptimizedServerCpuChart;
use PluggedHost\Servers\Filament\Server\Widgets\OptimizedServerMemoryChart;
use PluggedHost\Servers\Filament\Server\Widgets\OptimizedServerNetworkChart;
use PluggedHost\Servers\Filament\Server\Widgets\OptimizedServerOverview;

/**
 * Swaps the console page's stat widgets for this plugin's lighter versions. Lived in
 * plugged-host-core until 2.2, which made core depend on servers' widget classes.
 */
class ConsoleOptimizationPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        Livewire::component(ServerCpuChart::class, OptimizedServerCpuChart::class);
        Livewire::component(ServerMemoryChart::class, OptimizedServerMemoryChart::class);
        Livewire::component(ServerNetworkChart::class, OptimizedServerNetworkChart::class);
        Livewire::component(ServerOverview::class, OptimizedServerOverview::class);
    }
}

