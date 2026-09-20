<?php

namespace PluggedHost\Status\Filament\Admin\Resources\StatusNodes\Pages;

use Filament\Resources\Pages\ManageRecords;
use PluggedHost\Status\Filament\Admin\Resources\StatusNodes\StatusNodeResource;

class ManageStatusNodes extends ManageRecords
{
    protected static string $resource = StatusNodeResource::class;
}
