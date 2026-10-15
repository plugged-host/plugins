<?php

namespace PluggedHost\Core\Filament;

use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Navigation\NavigationManager;
use PluggedHost\Core\Support\NodeBackups;

/**
 * A NavigationManager that drops sidebar entries listed as hidden.
 *
 * Filament gives every navigation item a key equal to the page or resource class that
 * registered it (`NavigationItem::make(...)->key(static::class)` in
 * vendor/filament/filament/src/Pages/Page.php and
 * Resources/Resource/Concerns/HasNavigation.php), so a fully-qualified class name is
 * enough to identify one entry across all three panels without touching upstream code.
 *
 * Why here and not on the page: `Page::$shouldRegisterNavigation` is protected with no
 * public setter, and the pages we want to hide (Mounts, Webhooks, upstream Settings and
 * Startup) live in app/, which must stay byte-identical to the release tarball.
 *
 * Filtering happens in getNavigationItems() because that is the single point get() reads
 * from — it covers both the items copied off the panel in the constructor and the ones
 * appended by mountNavigation() via Panel::navigationItems().
 *
 * Known limitation: Panel\Concerns\HasRoutes::getRedirectUrl() builds `new
 * NavigationManager` directly rather than resolving it, so hiding an entry does not change
 * which page a panel lands on. That is harmless for every panel here — the server panel
 * lands on Console (sort 1) and the app panel on the server list.
 */
class HiddenNavigationManager extends NavigationManager
{
    /**
     * Entries the combined server Settings page replaces outright. Not admin-configurable:
     * leaving them visible would show three pages that all edit the same server, and the
     * combined page carries everything they did.
     *
     * The routes stay registered, so an existing bookmark or a link from elsewhere in the
     * panel still resolves.
     *
     * @var array<int, class-string>
     */
    public const REPLACED = [
        \App\Filament\Server\Pages\Settings::class,
        \App\Filament\Server\Pages\Startup::class,
    ];

    /**
     * @return array<NavigationItem>
     */
    public function getNavigationItems(): array
    {
        $hidden = static::hidden();

        $items = $hidden === [] ? parent::getNavigationItems() : array_values(array_filter(
            parent::getNavigationItems(),
            fn (NavigationItem $item): bool => !in_array($item->getKey(), $hidden, true),
        ));

        // No Backups page on a node with backups turned off (Support\NodeBackups).
        if ($this->panel->getId() === 'server' && NodeBackups::disabledFor(Filament::getTenant())) {
            $items = array_values(array_filter(
                $items,
                fn (NavigationItem $item): bool => $item->getKey() !== \App\Filament\Server\Resources\Backups\BackupResource::class,
            ));
        }

        // The server sidebar: everyday pages on top, the rest folded into "More". The admin
        // sidebar: regrouped by AdminNavigation.
        return match ($this->panel->getId()) {
            'server' => ServerNavigation::arrange($items),
            'admin' => AdminNavigation::arrange($items),
            default => $items,
        };
    }

    /**
     * The admin panel's groups come from AdminNavigation instead of AdminPanelProvider, so
     * their order is ours.
     *
     * @return array<string | int, NavigationGroup | string>
     */
    public function getNavigationGroups(): array
    {
        return $this->panel->getId() === 'admin' ? AdminNavigation::groups() : parent::getNavigationGroups();
    }

    /**
     * The hidden set: what an admin configured, plus what the combined Settings page
     * replaces.
     *
     * @return array<int, string>
     */
    public static function hidden(): array
    {
        return array_values(array_unique([...self::configured(), ...self::REPLACED]));
    }

    /**
     * Only what an admin configured, without the always-hidden pair.
     *
     * Stored as one comma-joined string in .env (NAV_HIDDEN_ITEMS) exactly like
     * UCS_DEPLOYMENT_TAGS, because EnvironmentWriterTrait writes flat strings.
     *
     * The settings UI reads this rather than hidden(): REPLACED is not the admin's choice,
     * so showing it as a selected option would imply it can be deselected.
     *
     * @return array<int, string>
     */
    public static function configured(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('plugged-host-core.navigation.hidden', '')),
        )));
    }
}
