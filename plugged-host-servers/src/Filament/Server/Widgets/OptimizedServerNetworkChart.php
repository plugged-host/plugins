<?php

namespace PluggedHost\Servers\Filament\Server\Widgets;

use App\Filament\Server\Widgets\ServerNetworkChart;

class OptimizedServerNetworkChart extends ServerNetworkChart
{
    protected ?string $pollingInterval = '5s';
}

