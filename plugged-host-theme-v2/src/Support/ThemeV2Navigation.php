<?php

namespace PluggedHost\ThemeV2\Support;

use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationItem;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Route;

/**
 * The app panel's sidebar. This started out shaped 1:1 after UIExample's
 * `views/components/top.ejs` — an ungrouped "Home" row followed by collapsible,
 * icon-bearing groups whose items carried no icon of their own — and is now **flat and four
 * rows long by request**: Home, Store, Profile Settings, and Admin Panel for admins. Every
 * destination is one top-level row carrying its own icon, with no group headers and nothing
 * indented underneath.
 *
 * That is a deliberate divergence from the reference, and it buys three things beyond the
 * look the user asked for:
 *   - every row can be the same big 2.75rem button, matching `.fi-btn`, which a group header plus a dense child list cannot be;
 *   - `NavigationBuilder::getNavigation()` wraps ungrouped items in a single *unlabelled*
 *     group, so there is exactly one `.fi-sidebar-group` and no `.fi-sidebar-group-btn`
 *     in the tree at all; and
 *   - four rows fit above the fold with the notification button, the
 *     token/credit balance and the avatar row, which is the whole of what this column is
 *     meant to show.
 *
 * Icons are deliberately Heroicons rather than Pelican's Tabler set — UIExample's inline
 * SVGs *are* Heroicons (24x24 outline, stroke-width 1.5), so this is the only way the
 * glyphs themselves match rather than merely rhyming.
 *
 * UIExample's "Web Hosting" and "Networking" groups have no counterpart here and are
 * omitted rather than faked; its Wallet / Staking / J4R / Referrals / AFK items live under
 * Economy there and simply do not exist in this panel.
 *
 * Rows that *did* have counterparts and are gone by request rather than for want of one:
 *   - UIExample's Compute group (New Server + SFTP Credentials here). It became a sidebar
 *     "New Server" button, which was removed by request too: creating a server starts from
 *     the Create server button on the servers list;
 *   - the Economy group's "Earn Tokens" row was dropped. `/tokens` itself is still a live
 *     page, owned by plugged-host-ads and still linked from the start gate and the store —
 *     Theme v2 only stopped putting it in the sidebar;
 *   - Customization, whose destination is a tab on the profile page and so is still one
 *     click in from the Profile Settings row. That tab holds the Theme v2 opt-in toggle,
 *     which is why it had a row of its own and a cog in the old search row; neither is
 *     needed for it to stay reachable;
 *   - the admin panel's Servers / Nodes / Users deep links. `/admin` builds its own
 *     navigation with all three at the top of it, so a shortcut list here duplicated the
 *     first screen it lands on.
 */
class ThemeV2Navigation
{
    public static function buildAppNavigation(): NavigationBuilder
    {
        // Only ->items(), never ->group(): getNavigation() folds ungrouped items into one
        // unlabelled group, which is what keeps the rendered tree free of group headers.
        return (new NavigationBuilder())->items(static::appItems());
    }

    /**
     * The whole app sidebar as one flat list of four rows, in the order it reads top to
     * bottom: home, the shop, the user's own settings, then the way into the admin panel.
     *
     * @return array<NavigationItem>
     */
    private static function appItems(): array
    {
        $items = [
            // Home — the panel's landing page *is* the server list, so unlike UIExample
            // (where /dashboard and /servers are separate) this row carries the count
            // badge that its "My Servers" item would have.
            NavigationItem::make(trans('plugged-host-theme-v2::strings.nav.home'))
                ->icon(Heroicon::OutlinedHome)
                ->url('/')
                ->badge(fn () => static::ownedServerCount() ?: null)
                ->isActiveWhen(fn () => request()->is('/')),
        ];

        // Store stands in for the whole Economy group now that Earn Tokens is gone, so it
        // takes the shopping-bag glyph rather than the group's banknotes: a single row that
        // goes to a shop should not be labelled as if it were the section.
        if (Route::has('filament.app.pages.store')) {
            $items[] = NavigationItem::make(trans('plugged-host-theme-v2::strings.nav.store'))
                ->icon(Heroicon::OutlinedShoppingBag)
                ->url(route('filament.app.pages.store'))
                ->isActiveWhen(fn () => request()->is('store*'));
        }

        // Storage Backups Portal
        $backupsPortalUrl = env('BACKUPS_PORTAL_URL', 'https://backups.plugged.host');
        $items[] = NavigationItem::make(trans('plugged-host-theme-v2::strings.nav.backups'))
            ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
            ->url($backupsPortalUrl)
            ->openUrlInNewTab(false)
            ->isActiveWhen(fn () => request()->is('backups*'));

        // Profile Settings inherits the user-circle its Account group used to carry. It is
        // also the only route to the Customization tab now that that row is gone, and the
        // active test is deliberately loose about the tab for it — landing on the profile
        // page at all should light this row up, whichever tab is showing.
        $items[] = NavigationItem::make(trans('plugged-host-theme-v2::strings.nav.profile_settings'))
            ->icon(Heroicon::OutlinedUserCircle)
            ->url(fn () => static::profileUrl())
            ->isActiveWhen(fn () => request()->is('profile'));

        // Crosses into the admin panel, which builds its own navigation — so this can never
        // be the active row here, and `isActive` is left at its false default. It keeps the
        // padlock its Admin group carried.
        if (user()?->isAdmin()) {
            $items[] = NavigationItem::make(trans('plugged-host-theme-v2::strings.nav.admin_panel'))
                ->icon(Heroicon::OutlinedLockClosed)
                ->url('/admin');
        }

        return $items;
    }

    public static function profileUrl(): string
    {
        return Route::has('filament.app.auth.profile')
            ? route('filament.app.auth.profile')
            : '/profile';
    }

    public static function ownedServerCount(): int
    {
        $user = user();

        if (!$user) {
            return 0;
        }

        return $user->directAccessibleServers()->where('owner_id', $user->id)->count();
    }
}
