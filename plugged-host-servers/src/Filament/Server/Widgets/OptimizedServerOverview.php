<?php

namespace PluggedHost\Servers\Filament\Server\Widgets;

use App\Filament\Server\Widgets\ServerOverview;

class OptimizedServerOverview extends ServerOverview
{
    protected ?string $pollingInterval = '5s';
}

