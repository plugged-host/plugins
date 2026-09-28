<?php

namespace PluggedHost\Core\Filament;

use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;

/**
 * The server sidebar, shortened: the pages people open every day stay on top in a fixed order
 * (Minecraft's Aternos-style ones included), and the ones most people rarely open fold into a
 * "More" group that starts collapsed. Nothing is removed -- every page is one click further at
 * most, and a URL still opens it directly.
 *
 * Classes are named as strings because most belong to optional plugins; an absent one simply
 * never matches. Anything not listed (a page a plugin adds later) keeps its own place, after
 * the everyday pages.
 */
final class ServerNavigation
{
    /** Top to bottom. */
    public const EVERYDAY = [
        'PluggedHost\\Minecraft\\Filament\\Server\\Pages\\ServerInstallingPage',
        'App\\Filament\\Server\\Pages\\Console',
        'PluggedHost\\Minecraft\\Filament\\Server\\Pages\\ServerOnboardingPage',
        'App\\Filament\\Server\\Resources\\Files\\FileResource',
        'PluggedHost\\Minecraft\\Filament\\Server\\Pages\\ServerContent',
        'PluggedHost\\Minecraft\\Filament\\Server\\Pages\\ServerWorlds',
        'PluggedHost\\Minecraft\\Filament\\Server\\Pages\\ServerPlayersPage',
        'PluggedHost\\Minecraft\\Filament\\Server\\Pages\\GameConfigPage',
        'App\\Filament\\Server\\Resources\\Backups\\BackupResource',
        'PluggedHost\\Servers\\Filament\\Server\\Pages\\ServerSettings',
    ];

    /** Folded into "More", in this order. */
    public const MORE = [
        'Boy132\\Subdomains\\Filament\\Server\\Resources\\Subdomains\\SubdomainResource',
        'App\\Filament\\Server\\Resources\\Allocations\\AllocationResource',
        'App\\Filament\\Server\\Resources\\Databases\\DatabaseResource',
        'App\\Filament\\Server\\Resources\\Schedules\\ScheduleResource',
        'App\\Filament\\Server\\Resources\\Subusers\\SubuserResource',
        'PluggedHost\\Servers\\Filament\\Server\\Pages\\ServerTransferPage',
        'App\\Filament\\Server\\Resources\\Activities\\ActivityResource',
    ];

    public static function moreLabel(): string
    {
        return trans('plugged-host-core::strings.nav.more');
    }

    public static function moreGroup(): NavigationGroup
    {
        return NavigationGroup::make(fn (): string => self::moreLabel())->collapsed();
    }

    /**
     * @param  array<NavigationItem>  $items
     * @return array<NavigationItem>
     */
    public static function arrange(array $items): array
    {
        return array_map(function (NavigationItem $item): NavigationItem {
            $item = clone $item;
            $key = (string) $item->getKey();

            $everyday = array_search($key, self::EVERYDAY, true);
            if ($everyday !== false) {
                return $item->sort($everyday);
            }

            $more = array_search($key, self::MORE, true);
            if ($more !== false || self::isOpenInAdmin($item)) {
                return $item->group(self::moreLabel())->sort($more === false ? count(self::MORE) : $more);
            }

            return $item->sort(count(self::EVERYDAY) + (int) $item->getSort());
        }, $items);
    }

    /**
     * The panel's own "Open in Admin" link (ServerPanelProvider): a plain item keyed by its
     * label, which is only ever shown to admins.
     */
    private static function isOpenInAdmin(NavigationItem $item): bool
    {
        return in_array($item->getKey(), [trans('server/console.open_in_admin'), 'Open in Admin'], true);
    }
}
