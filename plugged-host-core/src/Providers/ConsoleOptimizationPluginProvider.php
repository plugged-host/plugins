<?php

namespace PluggedHost\Core\Providers;

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

