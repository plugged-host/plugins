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
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use PluggedHost\Core\Livewire\PanelTourOverlay;
use PluggedHost\Core\Services\AllocationInvariant;
use PluggedHost\Core\Services\GuardedBuildModificationService;
use PluggedHost\Core\Services\GuardedServerDeletionService;
use PluggedHost\Core\Services\GuardedTransferServerService;
use PluggedHost\Core\Support\PanelTour;
use Throwable;

class CoreRuntimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        AllocationInvariant::registerModelGuards();

        $this->app->bind(ServerDeletionService::class, GuardedServerDeletionService::class);
        $this->app->bind(TransferServerService::class, GuardedTransferServerService::class);
        $this->app->bind(BuildModificationService::class, GuardedBuildModificationService::class);


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

    }

    public function boot(): void
    {
        // Mail templates echo values like the server name, which any server owner sets, into
        // Markdown: without this, "[Verify your account](https://...)" became a real link (or
        // image) in mail sent by plugged.host. Escapes [ < > in echoed values only.
        \Illuminate\Mail\Markdown::withSecuredEncoding();

        Livewire::component('plugged-host-core.panel-tour', PanelTourOverlay::class);

        // --- Welcome tour ---------------------------------------------------------------
        //
        // Drawn over the real pages, so each part is mounted only on the page it walks
        // through: the first on the server list, the second on a server's console. BODY_END
        // rather than PAGE_END so the overlay's position: fixed is relative to the window and
        // not to some transformed ancestor inside the page. Scoped by page class, which
        // base.blade.php passes to BODY_END, so every other page skips the closure entirely.
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn (): Htmlable => $this->renderTour('panel'),
            ListServers::class,
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn (): Htmlable => $this->renderTour('server'),
            Console::class,
        );

        // --- Topbar ---------------------------------------------------------------------
        //
        // Registered *unscoped* and filtered inside the closure, which is not laziness:
        // ViewManager::renderHook() only renders `renderHooks[$name]['']` unless the call site
        // passes scopes, and Filament's topbar.blade.php calls it with no scopes at all.

        // The wordmark beside the logo. APP_LOGO is set, so Filament's logo component takes its
        // <img> branch and brandName is never drawn.
        FilamentView::registerRenderHook(
            PanelsRenderHook::TOPBAR_LOGO_AFTER,
            function (): Htmlable {
                if (!in_array(Filament::getCurrentOrDefaultPanel()?->getId(), ['app', 'server'], true)) {
                    return new HtmlString('');
                }

                return new HtmlString(
                    (string) view('plugged-host-core::filament.components.brand-text')->render(),
                );
            },
        );
    }

    /**
     * The tour part due on this page, or nothing. Rendered inside the try, for the reason
     * theme v2's sidebar hooks give: a render hook that throws takes the page down with it,
     * and a tour is never worth that.
     */
    private function renderTour(string $part): Htmlable
    {
        try {
            $user = auth()->user();
            $due = $part === 'server' ? PanelTour::serverPartActive($user) : PanelTour::panelPartActive($user);

            if (!$due) {
                return new HtmlString('');
            }

            return new HtmlString(Blade::render(
                "@livewire('plugged-host-core.panel-tour', ['part' => \$part])",
                ['part' => $part],
            ));
        } catch (Throwable $exception) {
            report($exception);

            return new HtmlString('');
        }
    }
}
