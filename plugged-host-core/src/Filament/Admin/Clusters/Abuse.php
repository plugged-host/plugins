<?php

namespace PluggedHost\Core\Filament\Admin\Clusters;

use App\Models\Server;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use PluggedHost\Core\Models\AbuseFinding;
use Throwable;

/**
 * One admin sidebar entry for abuse protection: what the checks found, and which nodes run the
 * agent, as tabs. The badge counts open critical findings (in report mode, the servers it would
 * have suspended). Its sidebar group is set by AdminNavigation.
 */
class Abuse extends Cluster
{
    protected static string|\BackedEnum|null $navigationIcon = 'tabler-shield-exclamation';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    protected static ?string $slug = 'abuse';

    public static function getNavigationLabel(): string
    {
        return 'Abuse';
    }

    public static function getClusterBreadcrumb(): ?string
    {
        return static::getNavigationLabel();
    }

    /** Hidden until the migration has created the tables it lists. */
    public static function canAccess(): bool
    {
        return (user()?->can('viewAny', Server::class) ?? false) && AbuseFinding::ready();
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            if (!AbuseFinding::ready()) {
                return null;
            }

            $open = AbuseFinding::query()->where('status', AbuseFinding::OPEN)->where('severity', 'critical')->count();

            return $open > 0 ? (string) $open : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }
}
