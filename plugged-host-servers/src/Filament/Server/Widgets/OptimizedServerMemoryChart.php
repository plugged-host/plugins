<?php

namespace PluggedHost\Servers\Filament\Server\Widgets;

use App\Filament\Server\Widgets\ServerMemoryChart;

class OptimizedServerMemoryChart extends ServerMemoryChart
{
    protected ?string $pollingInterval = '5s';
}

