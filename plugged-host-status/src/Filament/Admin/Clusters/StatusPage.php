<?php

namespace PluggedHost\Status\Filament\Admin\Clusters;

use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;

/**
 * One admin sidebar entry for the public status page: incidents, the nodes it shows and the
 * component controls are tabs of it instead of three rows of their own. Its sidebar group is
 * set by core's AdminNavigation.
 */
class StatusPage extends Cluster
{
    protected static string|\BackedEnum|null $navigationIcon = 'tabler-activity-heartbeat';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    protected static ?string $slug = 'status';

    public static function getNavigationLabel(): string
    {
        return trans('plugged-host-status::strings.admin.status_page');
    }

    public static function getClusterBreadcrumb(): ?string
    {
        return static::getNavigationLabel();
    }
}
