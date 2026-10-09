<?php

namespace PluggedHost\Core\Filament\Admin\Resources\AbuseFindings\Pages;

use Filament\Resources\Pages\ManageRecords;
use PluggedHost\Core\Filament\Admin\Resources\AbuseFindings\AbuseFindingResource;
use PluggedHost\Core\Services\Abuse\AbuseEnforcer;

class ManageAbuseFindings extends ManageRecords
{
    protected static string $resource = AbuseFindingResource::class;

    public function getSubheading(): ?string
    {
        return match (AbuseEnforcer::mode()) {
            'suspend' => 'Servers with a critical finding are suspended automatically. Review findings need a look from you.',
            'report' => 'Report-only mode: critical findings are recorded and alerted, but nothing is suspended. Switch to suspend in the Core plugin settings.',
            default => 'Abuse protection is off in the Core plugin settings: nothing new is recorded.',
        };
    }
}
