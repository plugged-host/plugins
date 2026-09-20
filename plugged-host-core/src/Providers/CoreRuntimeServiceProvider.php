<?php

namespace PluggedHost\Core\Providers;

use App\Filament\App\Resources\Servers\Pages\ListServers;
use App\Filament\Server\Pages\Console;
use App\Services\Servers\BuildModificationService;
use App\Services\Servers\ServerDeletionService;
use App\Services\Servers\TransferServerService;
use Closure;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use PluggedHost\Minecraft\Filament\Server\Pages\ServerOnboardingPage;
use PluggedHost\Servers\Filament\Server\Pages\ServerSettings;
use PluggedHost\Core\Livewire\AccountChips;
use PluggedHost\Core\Services\AccountSummary;
use PluggedHost\Core\Services\AllocationInvariant;
use PluggedHost\Core\Services\GuardedBuildModificationService;
use PluggedHost\Core\Services\GuardedServerDeletionService;
use PluggedHost\Core\Services\GuardedTransferServerService;
use Throwable;

class CoreRuntimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        AllocationInvariant::registerModelGuards();

        $this->app->bind(ServerDeletionService::class, GuardedServerDeletionService::class);
        $this->app->bind(TransferServerService::class, GuardedTransferServerService::class);
        $this->app->bind(BuildModificationService::class, GuardedBuildModificationService::class);

        // scoped(), not singleton(): the memo inside is per-request by design, and a singleton
        // would hand a queue worker one user's balances for the life of the process.
        $this->app->scoped(AccountSummary::class);

        Livewire::listen('mount', function (object $component): void {
            if ($component instanceof ListServers) {
                $component->activeTab = 'all';
            }
        });

        Livewire::listen('hydrate', function (object $component): void {
            if ($component instanceof ListServers) {
                $component->activeTab = 'all';
            }
        });

        Livewire::listen('update', function (object $component, string $path): ?Closure {
            if (!$component instanceof ListServers || $path !== 'activeTab') {
                return null;
            }

            return function () use ($component): void {
                $component->activeTab = 'all';
            };
        });

        // Utility classes for the plugin's own Blade views. Neither the panel's
        // Tailwind entry nor the nord theme scans plugins/**, so this bundle is
        // the only thing that compiles them; see resources/css/plugged-host.css.
        // Injected after the panel styles so it wins on equal specificity, and
        // guarded so a not-yet-built asset degrades to unstyled instead of 500ing
        // every page — `public/build` is replaced wholesale by a panel upgrade.
        FilamentView::registerRenderHook(
            PanelsRenderHook::STYLES_AFTER,
            function (): Htmlable {
                try {
                    $url = Vite::asset('plugins/plugged-host-core/resources/css/plugged-host.css');
                } catch (Throwable) {
                    return new HtmlString('');
                }

                return new HtmlString('<link rel="stylesheet" href="' . e($url) . '" />');
            },
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::STYLES_BEFORE,
            fn (): Htmlable => new HtmlString(<<<'HTML'
                <style>
                    .fi-main-ctn > footer,
                    .fi-simple-layout > footer {
                        display: none !important;
                    }
                </style>
                HTML),
        );

        // The server list's tab strip (My / Others' / All Servers), hidden because the
        // listeners above pin activeTab to 'all' and a tab strip you cannot change is noise.
        //
        // Two details here are load-bearing, both because every panel runs ->spa():
        //
        // 1. The style is injected into the page body, not STYLES_BEFORE. Livewire's
        //    wire:navigate classes every <style> in <head> as an asset it must never garbage
        //    collect (mergeNewHead() only removes non-assets), so a head style outlives the
        //    page that asked for it and keeps applying for the rest of the SPA session. As a
        //    head style with the selector below written as a bare `.fi-tabs`, this rule went
        //    on to hide the profile page's tab strip for anyone who reached it from the
        //    server list — the "tabs sometimes missing until you refresh" bug. The body is
        //    replaced wholesale on navigation, so a style placed here dies with the page.
        // 2. The selector is scoped to the list-page wrapper, so even a stray copy of this
        //    rule cannot reach the tabs on a page that wants them.
        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_START,
            fn (): Htmlable => new HtmlString(<<<'HTML'
                <style>
                    .fi-resource-list-records-page .fi-tabs {
                        display: none !important;
                    }
                </style>
                HTML),
            ListServers::class,
        );

        $this->registerServerSettingsStyles();
    }

    /**
     * The combined server settings page: sticky jump-nav and scroll-reveal.
     *
     * PAGE_START, scoped to that one page, for both of the reasons the server-list rule
     * above spells out — a <style> in <head> outlives the page that asked for it under
     * wire:navigate, and `html { scroll-behavior }` in particular would then follow the
     * user around the whole panel. In the body it dies with the page.
     *
     * Plain CSS against the panel's own runtime palette variables rather than Tailwind
     * utilities, so this needs no `yarn build` and cannot render unstyled if
     * public/build is ever replaced by an upgrade before the next one.
     *
     * The reveal classes are applied by Alpine (see ServerSettings::revealing()), never by
     * CSS alone: .ph-reveal is what hides a section, and nothing adds it unless Alpine is
     * alive to take it back off again.
     */
    protected function registerServerSettingsStyles(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_START,
            fn (): Htmlable => new HtmlString(<<<'HTML'
                <style>
                    html {
                        scroll-behavior: smooth;
                    }
                </style>
                HTML),
            ServerSettings::class,
        );
    }

    public function boot(): void
    {
        Livewire::component('plugged-host-account-chips', AccountChips::class);

        // --- Topbar ---------------------------------------------------------------------
        //
        // Both hooks are registered *unscoped* and filter inside the closure, which is not
        // laziness: ViewManager::renderHook() only renders `renderHooks[$name]['']` unless the
        // call site passes scopes, and Filament's topbar.blade.php calls both of these with no
        // scopes at all. A hook registered with `scopes: 'app'` would therefore never render.

        // The wordmark beside the logo. APP_LOGO is set, so Filament's logo component takes its
        // <img> branch and brandName is never drawn.
        FilamentView::registerRenderHook(
            PanelsRenderHook::TOPBAR_LOGO_AFTER,
            function (): Htmlable {
                if (!in_array(Filament::getCurrentOrDefaultPanel()?->getId(), AccountChips::PANELS, true)) {
                    return new HtmlString('');
                }

                return new HtmlString(
                    (string) view('plugged-host-core::filament.components.brand-text')->render(),
                );
            },
        );

        // Tokens / credits / quota, immediately left of the notification bell.
        FilamentView::registerRenderHook(
            PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
            function (): Htmlable {
                if (!AccountChips::shouldRender()) {
                    return new HtmlString('');
                }

                return new HtmlString(
                    (string) view('plugged-host-core::filament.components.account-chips-host')->render(),
                );
            },
        );

        // --- Server console -------------------------------------------------------------
        //
        // Pointer to the first-run setup guide. Scoped to the console because that is where a new
        // owner lands, and PAGE_START rather than a head style so it dies with the page under
        // wire:navigate.
        //
        // canAccess() is the only condition: it already answers "Minecraft server, not
        // installing, may write files, setup still pending", so the callout and the nav item can
        // never disagree about whether there is anything to set up. Guarded because it reads the
        // egg's variables — a page must not 500 because the guide could not decide.
        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_START,
            function (): Htmlable {
                try {
                    if (!ServerOnboardingPage::canAccess()) {
                        return new HtmlString('');
                    }

                    $url = ServerOnboardingPage::getUrl();
                } catch (Throwable) {
                    return new HtmlString('');
                }

                return new HtmlString((string) view(
                    'plugged-host-core::filament.components.onboarding-callout',
                    ['url' => $url],
                )->render());
            },
            Console::class,
        );
    }
}
