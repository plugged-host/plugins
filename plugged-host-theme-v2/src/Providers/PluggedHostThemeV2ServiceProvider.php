<?php

namespace PluggedHost\ThemeV2\Providers;

use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use PluggedHost\ThemeV2\Filament\Pages\ThemeAwareEditProfile;
use PluggedHost\ThemeV2\Livewire\ThemeV2Balance;
use PluggedHost\ThemeV2\Support\ThemeV2Access;
use Throwable;

class PluggedHostThemeV2ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $configPath = __DIR__ . '/../../config/plugged-host-theme-v2.php';
        if (file_exists($configPath)) {
            $this->mergeConfigFrom($configPath, 'plugged-host-theme-v2');
        }
    }

    public function boot(): void
    {
        Livewire::component('plugged-host-theme-v2.edit-profile', ThemeAwareEditProfile::class);
        Livewire::component(ThemeAwareEditProfile::class, ThemeAwareEditProfile::class);
        Livewire::component('plugged-host-theme-v2.balance', ThemeV2Balance::class);

        // The /plugged-host/theme-v2/toggle route went with the per-user preference (WP2):
        // there is only one theme now, so there is nothing to toggle.

        // Inject Google Fonts asynchronously via preconnect links in head (non-render-blocking)
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_START,
            function (): Htmlable {
                if (!ThemeV2Access::shouldRenderTheme()) {
                    return new HtmlString('');
                }

                return new HtmlString(<<<'HTML'
                    <link rel="preconnect" href="https://fonts.googleapis.com">
                    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
                    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:ital,wght@0,400..700;1,400..700&family=Space+Grotesk:wght@300;400;500;600;700&display=swap">
                HTML);
            },
        );

        // The big notification button, immediately under the logo and above the nav tree.
        FilamentView::registerRenderHook(
            PanelsRenderHook::SIDEBAR_NAV_START,
            fn (): Htmlable => $this->renderSidebarNotifications(),
        );

        /*
         * The token / credit balance, at the foot of the sidebar above the avatar row.
         *
         * SIDEBAR_FOOTER renders *after* `.fi-sidebar-footer` in the DOM (see
         * vendor/filament/filament/resources/views/livewire/sidebar.blade.php), so on its own
         * this would land below the avatar. theme.css gives `.fi-sidebar-footer` `order: 1`,
         * which sorts it past anything this hook emits and puts the balance above it — the
         * same relationship to the avatar that UIExample's header pill has.
         */
        FilamentView::registerRenderHook(
            PanelsRenderHook::SIDEBAR_FOOTER,
            fn (): Htmlable => $this->renderSidebarBalance(),
        );
    }

    /**
     * plugged-host-core's own chips stay gone: it hangs AccountChips off GLOBAL_SEARCH_BEFORE,
     * a hook that exists only inside topbar.blade.php, and this theme has no topbar on any
     * panel, so nothing has to be switched off for them not to appear. The balance they used
     * to carry is back as Theme v2's own component at SIDEBAR_FOOTER below, styled from
     * `--phv2-*` properties rather than the `dark:` variants that made core's version render
     * as pale pills on this theme's unconditionally dark sidebar.
     *
     * A render hook that throws takes the whole panel down with it, so every failure mode
     * below degrades to "no notification button" rather than a 500 — which requires rendering
     * *inside* the try. Returning the View object would defer Blade compilation to whoever
     * casts the Htmlable to string, which is outside this catch; core's own hooks render
     * eagerly for the same reason.
     */
    private function renderSidebarNotifications(): Htmlable
    {
        try {
            if (!ThemeV2Access::shouldRenderTheme()) {
                return new HtmlString('');
            }

            $unreadNotificationsCount = $this->unreadNotificationsCount();

            return new HtmlString((string) view('plugged-host-theme-v2::sidebar.notifications', [
                'unreadNotificationsCount' => $unreadNotificationsCount,
                'notificationsLabel' => $unreadNotificationsCount
                    ? trans_choice(
                        'filament-panels::layout.actions.open_database_notifications.label_with_unread_count',
                        $unreadNotificationsCount,
                        ['count' => Number::format($unreadNotificationsCount, locale: app()->getLocale())],
                    )
                    : trans('filament-panels::layout.actions.open_database_notifications.label'),
            ])->render());
        } catch (Throwable) {
            return new HtmlString('');
        }
    }

    /**
     * Registered on all three panels, like every hook here, but only *mounted* on two of them.
     *
     * The panel test has to happen here and not in the component. It was in the component, as a
     * `shouldRender()` method, on the assumption that Livewire asks a component whether it wants
     * to render the way Filament asks a widget `canView()` — it does not, so the balance mounted
     * on the admin panel too. Livewire has no opt-out for a *first* render at all (`skipRender()`
     * only preserves a previous DOM), so the only place a "do not mount this" decision can live
     * is the caller, i.e. here. ThemeV2Balance::shouldMount() holds the conditions and memoises
     * the two reads it needs statically, so asking it costs nothing that render() would not have
     * spent anyway.
     *
     * @see \PluggedHost\ThemeV2\Livewire\ThemeV2Balance::shouldMount()
     */
    private function renderSidebarBalance(): Htmlable
    {
        try {
            if (!ThemeV2Balance::shouldMount()) {
                return new HtmlString('');
            }

            return new HtmlString((string) view('plugged-host-theme-v2::sidebar.balance-host')->render());
        } catch (Throwable) {
            return new HtmlString('');
        }
    }

    /**
     * Mirrors what `Filament\Notifications\Livewire\DatabaseNotifications` counts, so the
     * badge on our bell agrees with the badge on the topbar bell it forwards to.
     */
    private function unreadNotificationsCount(): int
    {
        $panel = Filament::getCurrentPanel();

        if (!$panel?->hasDatabaseNotifications()) {
            return 0;
        }

        $user = user();

        if (!$user) {
            return 0;
        }

        return (int) cache()->remember("user:{$user->id}:unread_notifs_count", 15, function () use ($user): int {
            return $user->unreadNotifications()->where('data->format', 'filament')->count();
        });
    }
}
