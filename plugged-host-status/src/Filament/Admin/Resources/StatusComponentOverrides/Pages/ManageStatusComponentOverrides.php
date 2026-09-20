<?php

namespace PluggedHost\Status\Filament\Admin\Resources\StatusComponentOverrides\Pages;

use Filament\Resources\Pages\ManageRecords;
use PluggedHost\Status\Filament\Admin\Resources\StatusComponentOverrides\StatusComponentOverrideResource;

class ManageStatusComponentOverrides extends ManageRecords
{
    protected static string $resource = StatusComponentOverrideResource::class;
}
