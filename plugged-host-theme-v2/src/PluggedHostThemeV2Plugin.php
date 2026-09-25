<?php

namespace PluggedHost\ThemeV2;

use Filament\Actions\Action;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Support\Assets\Theme;
use Illuminate\Foundation\Vite;
use PluggedHost\ThemeV2\Filament\Pages\ThemeAwareEditProfile;
use PluggedHost\ThemeV2\Support\ThemeV2Navigation;

class PluggedHostThemeV2Plugin implements Plugin
{
    public function getId(): string
    {
        return 'plugged-host-theme-v2';
    }

    public function register(Panel $panel): void
    {
        // The profile page. Kept as the panel's profile() page (and its two Livewire aliases in
        // the service provider) so routing does not change; it is now a plain EditProfile with
        // no Theme section, since there is only one theme.
        $panel->profile(ThemeAwareEditProfile::class, false);

        // Normalize user menu item icon sizes (profile and logout default to large upstream,
        // which makes them visually oversized compared to standard 20px action icons).
        // Preserve upstream PanelProvider routing for profile to the app panel so that
        // tenant-free routes on tenant-aware panels (e.g. server) do not load /server/profile.
        $panel->userMenuItems([
            'profile' => fn (Action $action) => $action
                ->iconSize(null)
                ->url(fn () => ThemeAwareEditProfile::getUrl(panel: 'app')),
            'logout' => fn (Action $action) => $action->iconSize(null),
        ]);

        // Theme v2 is the only theme. This is the single change that lets nord-theme be deleted
        // (WP2 fact 10): nothing here references plugins/nord-theme any more.
        $panel->viteTheme([]);
        $panel->theme(Theme::make('app')->html(
            fn () => app(Vite::class)('plugins/plugged-host-theme-v2/resources/css/theme.css'),
        ));

        // Theme v2 is dark-only. Enforce dark mode on the panel, strip the user-menu
        // theme switcher, and keep client state permanently locked to dark.
        $panel->darkMode(true, isForced: true);
        $panel->themeSwitcher(false);

        /*
         * Theme v2 has no topbar on any panel: the sidebar carries the wallet chips, the
         * notification bell and the avatar menu. Filament falls the user menu and database
         * notifications back to the sidebar when hasTopbar() is false; ThemeV2Balance is
         * supplied at SIDEBAR_FOOTER by the service provider.
         *
         * topNavigation goes off with it — `.fi-body-has-top-navigation .fi-sidebar` is
         * `lg:-translate-x-full`, so leaving it on would hide the sidebar.
         */
        $panel->topNavigation(false);
        $panel->topbar(false);

        // The sidebar's notification button forwards to the real trigger, which must exist from
        // first paint (a lazy placeholder never intersects while display:none), so eager-load it.
        $panel->lazyLoadedDatabaseNotifications(false);

        if ($panel->hasTenancy()) {
            // Guard against Filament attempting to resolve tenant billing/menu items
            // when no tenant is active in the current request (e.g. un-scoped routes).
            $panel->tenantMenu(fn () => Filament::getTenant() !== null);
        }

        if ($panel->getId() === 'app') {
            // The client panel has no navigation upstream (AppPanelProvider calls
            // ->navigation(false)); Theme v2 supplies UIExample's shape.
            $panel->navigation(fn () => ThemeV2Navigation::buildAppNavigation());
        }
    }

    public function boot(Panel $panel): void {}
}
