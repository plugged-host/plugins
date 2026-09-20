<?php

namespace PluggedHost\Servers\Filament\Server\Widgets;

use App\Filament\Server\Widgets\ServerCpuChart;

class OptimizedServerCpuChart extends ServerCpuChart
{
    protected ?string $pollingInterval = '5s';
}

