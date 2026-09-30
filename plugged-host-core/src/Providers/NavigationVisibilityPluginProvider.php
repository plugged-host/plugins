<?php

namespace PluggedHost\Core\Providers;

use Filament\Facades\Filament;
use Filament\Navigation\NavigationManager;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Filament\HiddenNavigationManager;
use PluggedHost\Core\Filament\ServerNavigation;

/**
 * Lets an admin hide sidebar entries without an edit to app/.
 *
 * Filament binds NavigationManager with `$this->app->scoped()` in its own service
 * provider (vendor/filament/filament/src/FilamentServiceProvider.php:75). Package
 * providers register before application providers, and plugins are loaded from inside
 * AppServiceProvider::register(), so re-registering the binding here reliably wins —
 * the same ordering the OAuth/UserCreationService rebinds depend on.
 *
 * scoped(), not singleton(): Filament resets the instance per request/Octane context, and
 * the manager caches whether navigation has been mounted. A singleton would carry one
 * panel's mounted items into the next request.
 */
class NavigationVisibilityPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(NavigationManager::class, fn (): NavigationManager => new HiddenNavigationManager());
    }

    /**
     * Start the server sidebar's "More" group collapsed, once per browser.
     *
     * Filament only writes a group's default collapsed state when the browser has no stored
     * sidebar state at all, so anyone who has used the panel before would see "More" open.
     * This adds it to the stored list the first time, then leaves the choice to the user.
     * Rendered at SIDEBAR_NAV_END, after Filament's own script, and before Alpine reads the
     * stored list.
     */
    public function boot(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::SIDEBAR_NAV_END,
            function (): Htmlable {
                if (Filament::getCurrentPanel()?->getId() !== 'server') {
                    return new HtmlString('');
                }

                $label = json_encode(ServerNavigation::moreLabel(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

                return new HtmlString(<<<HTML
                    <script>
                        (() => {
                            const label = {$label};
                            const flag = 'plugged-host:collapsed-by-default:' + label;
                            if (localStorage.getItem(flag) !== null) return;
                            localStorage.setItem(flag, '1');
                            let groups = [];
                            try { groups = JSON.parse(localStorage.getItem('collapsedGroups')) || []; } catch (e) {}
                            if (!groups.includes(label)) localStorage.setItem('collapsedGroups', JSON.stringify([...groups, label]));
                            const group = document.querySelector('.fi-sidebar-group[data-group-label="' + CSS.escape(label) + '"]');
                            if (!group) return;
                            group.querySelector('.fi-sidebar-group-items').style.display = 'none';
                            group.classList.add('fi-collapsed');
                        })();
                    </script>
                    HTML);
            },
        );
    }
}
