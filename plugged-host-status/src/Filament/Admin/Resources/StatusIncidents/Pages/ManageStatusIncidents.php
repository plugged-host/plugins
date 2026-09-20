<?php

namespace PluggedHost\Status\Filament\Admin\Resources\StatusIncidents\Pages;

use Filament\Resources\Pages\ManageRecords;
use PluggedHost\Status\Filament\Admin\Resources\StatusIncidents\StatusIncidentResource;

class ManageStatusIncidents extends ManageRecords
{
    protected static string $resource = StatusIncidentResource::class;
}
