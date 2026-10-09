<?php

namespace PluggedHost\Core\Filament;

use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;

/**
 * The admin sidebar's groups: what is used every day on top, the rarely touched rest folded
 * into a collapsed Advanced group at the bottom.
 *
 *   Dashboard
 *   Servers                  Servers, Nodes, Eggs, Available Resources, Abuse
 *   Users                    Users, Roles
 *   Announcements & status   Announcements, Status page (incidents, nodes, components)
 *   Subdomains               Domains, SRV Targets
 *   Panel                    Settings, Plugins, Health, Panel Logs
 *   Advanced (collapsed)     API Keys, Webhooks, Database Hosts, Backup Hosts, Mounts
 *
 * Entries are matched by their navigation key (the page or resource class, see
 * HiddenNavigationManager), so upstream code stays untouched; an entry this list does not
 * know keeps its own group and lands after these. The groups themselves replace the ones
 * AdminPanelProvider registers, because Filament orders groups by registration and upstream's
 * Server / User / Advanced would otherwise always come first.
 */
final class AdminNavigation
{
    /** @var array<string, array<int, string>> group => entries in order; '' is the ungrouped top */
    private const LAYOUT = [
        '' => [
            \App\Filament\Admin\Pages\Dashboard::class,
        ],
        'servers' => [
            \App\Filament\Admin\Resources\Servers\ServerResource::class,
            \App\Filament\Admin\Resources\Nodes\NodeResource::class,
            \App\Filament\Admin\Resources\Eggs\EggResource::class,
            'PluggedHost\\Servers\\Filament\\Admin\\Pages\\AvailableResources',
            \PluggedHost\Core\Filament\Admin\Clusters\Abuse::class,
        ],
        'users' => [
            \App\Filament\Admin\Resources\Users\UserResource::class,
            \App\Filament\Admin\Resources\Roles\RoleResource::class,
        ],
        'community' => [
            'Boy132\\Announcements\\Filament\\Admin\\Resources\\Announcements\\AnnouncementResource',
            'PluggedHost\\Status\\Filament\\Admin\\Clusters\\StatusPage',
        ],
        'subdomains' => [
            'Boy132\\Subdomains\\Filament\\Admin\\Resources\\CloudflareDomains\\CloudflareDomainResource',
            'Boy132\\Subdomains\\Filament\\Admin\\Resources\\SrvTargets\\SrvTargetResource',
        ],
        'panel' => [
            \App\Filament\Admin\Pages\Settings::class,
            \App\Filament\Admin\Resources\Plugins\PluginResource::class,
            \App\Filament\Admin\Pages\Health::class,
            \App\Filament\Admin\Pages\ListLogs::class,
        ],
        'advanced' => [
            \App\Filament\Admin\Resources\ApiKeys\ApiKeyResource::class,
            \App\Filament\Admin\Resources\Webhooks\WebhookResource::class,
            \App\Filament\Admin\Resources\DatabaseHosts\DatabaseHostResource::class,
            \App\Filament\Admin\Resources\BackupHosts\BackupHostResource::class,
            \App\Filament\Admin\Resources\Mounts\MountResource::class,
        ],
    ];

    private const COLLAPSED = ['advanced'];

    public static function label(string $group): string
    {
        return trans("plugged-host-core::strings.admin_nav.{$group}");
    }

    /**
     * Keyed by label: Filament matches an item's group string against these keys.
     *
     * @return array<string, NavigationGroup>
     */
    public static function groups(): array
    {
        $groups = [];

        foreach (array_keys(self::LAYOUT) as $group) {
            if ($group === '') {
                continue;
            }

            $label = self::label($group);
            $groups[$label] = NavigationGroup::make($label)->collapsed(in_array($group, self::COLLAPSED, true));
        }

        return $groups;
    }

    /**
     * @param  array<NavigationItem>  $items
     * @return array<NavigationItem>
     */
    public static function arrange(array $items): array
    {
        return array_map(function (NavigationItem $item): NavigationItem {
            foreach (self::LAYOUT as $group => $keys) {
                $position = array_search($item->getKey(), $keys, true);

                if ($position !== false) {
                    return $item->group($group === '' ? null : self::label($group))->sort($position);
                }
            }

            return $item;
        }, $items);
    }
}
