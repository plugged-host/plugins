<?php

namespace PluggedHost\Ads\Providers;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ServiceProvider;
use App\Filament\App\Resources\Servers\Pages\ListServers;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use PluggedHost\Ads\Filament\Server\Pages\StartGatePage;
use PluggedHost\Ads\Http\Controllers\AdBannerClickController;
use PluggedHost\Ads\Models\AdBanner;
use PluggedHost\Ads\Services\AdBannerService;
use PluggedHost\Ads\Services\AdDisplayService;
use PluggedHost\Ads\Services\AdsterraService;
use Throwable;

class AdsPluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AdBannerService::class);
        $this->app->singleton(AdsterraService::class);
        $this->app->singleton(AdDisplayService::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(plugin_path('plugged-host-ads', 'resources/views'), 'plugged-host-ads');
        $this->loadTranslationsFrom(plugin_path('plugged-host-ads', 'lang'), 'plugged-host-ads');

        // WP4: <x-plugged-host-ads::slot name="…" /> → resources/views/components/*.blade.php.
        // Path-based registration (unambiguous for a plugin, no dependence on view-namespace
        // component resolution order).
        Blade::anonymousComponentPath(
            plugin_path('plugged-host-ads', 'resources/views/components'),
            'plugged-host-ads',
        );

        $this->registerSlot();
        $this->registerTopBanner();
        $this->registerPageLevelUnits();
        $this->registerNamedSlotHooks();
        $this->registerAdBlockResponse();
        $this->registerUnitScript();
        $this->registerBannerClicks();
    }

    /**
     * The top banner: a sponsored banner placed "top", full width above the page heading on
     * every app and server page. CONTENT_START is the layout's, inside <main> and before the
     * page component, so it sits under the topbar and over the breadcrumbs and heading, and it
     * is rendered once per page load (wire:navigate included) and never on a Livewire update:
     * a poll cannot swap it, and it rotates per page load, not per tick.
     *
     * With no top banner live, the `top` named slot (an Adsterra leaderboard) takes the same
     * place, so the most visible spot on the page is never empty — except on the waiting rooms,
     * which carry their own units, and the suspended page, which is a dead end. Who sees either
     * is the same rule as every other ad (AdDisplayService::showsAdsTo()).
     */
    protected function registerTopBanner(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::CONTENT_START,
            fn (array $scopes = []): ?Htmlable => $this->topOfPage($scopes),
        );
    }

    /** The top of the page, decided once per request: see topOfPage(). */
    private ?Htmlable $topOfPage = null;

    private bool $topDecided = false;

    /**
     * What goes at the top of the page, decided once per request and remembered.
     *
     * The page component renders before the layout, so without this the server-tools strip
     * (inside the page) would take the leaderboard zones before CONTENT_START asks for them,
     * and the most visible spot would get what is left. The strip asks for the top first
     * instead, on full page loads and Livewire updates alike (the strip renders on both), so
     * the two always agree on which zones are on the page.
     *
     * @param  array<int, string>  $scopes
     */
    protected function topOfPage(array $scopes): ?Htmlable
    {
        if ($this->topDecided) {
            return $this->topOfPage;
        }

        $this->topDecided = true;

        try {
            [$user, $panelId, $isAuthPage] = $this->context();

            if (!in_array($panelId, ['app', 'server'], true)) {
                return null;
            }

            $banners = app(AdBannerService::class);

            // The cache read first: most pages have no top banner, and the audience
            // rule can reach the database (roles, memberships).
            if (!$banners->hasLive(AdBanner::PLACEMENT_TOP)) {
                return $this->topOfPage = $this->renderTopSlot($scopes);
            }

            if (!app(AdDisplayService::class)->showsAdsTo($user, $panelId, $isAuthPage)) {
                return null;
            }

            $unit = $banners->top();

            return $this->topOfPage = $unit === null ? $this->renderTopSlot($scopes) : new HtmlString(view('plugged-host-ads::filament.top-banner', [
                'unit' => $unit,
            ])->render());
        } catch (Throwable) {
            return null;
        }
    }

    /** Pages the `top` leaderboard stays off: the waiting rooms (own units) and dead ends. */
    private const NOT_TOP = [
        StartGatePage::class,
        'PluggedHost\\Queue\\Filament\\Server\\Pages\\StartQueuePage',
        'PluggedHost\\Limbo\\Filament\\Server\\Pages\\RestoreFromLimboPage',
        'PluggedHost\\Core\\Filament\\Server\\Pages\\SuspendedServerPage',
    ];

    /**
     * The `top` named slot, or null on the pages it stays off. The slot's own grant
     * (AdDisplayService::slot()) applies the audience rule, the interstitial routes and the cap.
     *
     * @param  array<int, string>  $scopes
     */
    protected function renderTopSlot(array $scopes): ?Htmlable
    {
        if (array_intersect($scopes, self::NOT_TOP) !== [] || StartGatePage::isCurrentRoute()) {
            return null;
        }

        return $this->renderNamedSlot('top');
    }

    /**
     * GET /ads/b/{banner}: counts a click on a sponsored banner and sends the browser to that
     * banner's stored link. Stateless (no session, no cookies), see AdBannerClickController.
     */
    protected function registerBannerClicks(): void
    {
        Route::get('/ads/b/{banner}', AdBannerClickController::class)
            ->whereNumber('banner')
            ->name('plugged-host-ads.banner');
    }

    /**
     * The ad unit script: inserts the size that fits for multi-size units, and reloads the
     * sandboxed units after they have been in view for the user's interval
     * (AdDisplayService::refreshSeconds, 0 = no refresh). Once per page load; the script
     * follows wire:navigate on its own, so units rendered after a navigation are picked up too.
     */
    protected function registerUnitScript(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::SCRIPTS_AFTER,
            function (): ?Htmlable {
                try {
                    $ads = app(AdDisplayService::class);

                    [$user, $panelId, $isAuthPage] = $this->context();

                    if (!$ads->shouldDisplay($user, $panelId, $isAuthPage)) {
                        return null;
                    }

                    return new HtmlString(view('plugged-host-ads::filament.scripts.units', [
                        'seconds' => $ads->refreshSeconds($user),
                    ])->render());
                } catch (Throwable) {
                    return null;
                }
            },
        );
    }

    /**
     * The two WP4 named slots that live on surfaces no plugin owns a blade for — the app-panel
     * sidebar and the server list (upstream's ListServers). Everywhere else the placement is a
     * literal `<x-plugged-host-ads::slot name="…" />` tag in the owning view; here a render hook
     * emits the same component, so the grant logic (master/panel/exempt/interstitial/cap/
     * no-zone-twice) stays in exactly one place — AdDisplayService::slot() via the component.
     *
     * The tag is rendered through Blade so nothing is duplicated. `sidebar-footer` sits under the
     * balance at the foot of the sidebar; `server-list-footer` is gated to the ListServers route,
     * because CONTENT_END fires on every app-panel page. Both no-op instantly when the slot is
     * disabled or the user is exempt.
     */
    protected function registerNamedSlotHooks(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::SIDEBAR_FOOTER,
            fn (): ?Htmlable => $this->renderNamedSlot('sidebar-footer'),
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::CONTENT_END,
            function (): ?Htmlable {
                try {
                    if (Filament::getCurrentPanel()?->getId() !== 'app'
                        || !class_exists(ListServers::class)
                        || !request()->routeIs(ListServers::getRouteName())) {
                        return null;
                    }
                } catch (Throwable) {
                    return null;
                }

                return $this->renderNamedSlot('server-list-footer');
            },
        );

        // `server-tools`: a strip under the page header on server-panel pages. Decided by the
        // page class (the hook's scopes) rather than the route, because this hook also renders
        // on Livewire update requests, where the route is livewire.update and a route check
        // would let the strip into the console and the waiting rooms on their next poll.
        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_HEADER_WIDGETS_AFTER,
            function (array $scopes = []): ?Htmlable {
                try {
                    if (Filament::getCurrentPanel()?->getId() !== 'server'
                        || array_intersect($scopes, self::NOT_SERVER_TOOLS) !== []) {
                        return null;
                    }
                } catch (Throwable) {
                    return null;
                }

                // The top of the page picks its zones first (see topOfPage()).
                $this->topOfPage($scopes);

                return $this->renderNamedSlot('server-tools');
            },
        );
    }

    /**
     * Server-panel pages that are not tools: the console (the one page someone is mid-task
     * on), and the pages that are a wait or a dead end with their own ad handling.
     */
    private const NOT_SERVER_TOOLS = [
        'App\\Filament\\Server\\Pages\\Console',
        StartGatePage::class,
        'PluggedHost\\Queue\\Filament\\Server\\Pages\\StartQueuePage',
        'PluggedHost\\Limbo\\Filament\\Server\\Pages\\RestoreFromLimboPage',
        'PluggedHost\\Core\\Filament\\Server\\Pages\\SuspendedServerPage',
        'PluggedHost\\Minecraft\\Filament\\Server\\Pages\\ServerInstallingPage',
        'PluggedHost\\Minecraft\\Filament\\Server\\Pages\\ServerOnboardingPage',
    ];

    /**
     * Render one named slot as the same anonymous component a view would, or null on any failure —
     * a throwing render hook takes the panel down.
     */
    protected function renderNamedSlot(string $name): ?Htmlable
    {
        try {
            $html = trim(Blade::render(
                '<x-plugged-host-ads::slot :name="$name" />',
                ['name' => $name],
            ));

            return $html === '' ? null : new HtmlString($html);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The in-content slot: the Adsterra banner and native units, plus the anchor
     * the ad-block notice renders into.
     */
    protected function registerSlot(): void
    {
        $render = function (): ?Htmlable {
            try {
                $ads = app(AdDisplayService::class);
                $adsterra = app(AdsterraService::class);

                [$user, $panelId, $isAuthPage] = $this->context();

                if (!$ads->shouldDisplay($user, $panelId, $isAuthPage)) {
                    return null;
                }

                // The start gate renders its own unit stack, laid out for a page
                // whose only purpose is to hold attention. Letting the generic slot
                // render as well would serve the same Adsterra zone twice in one
                // document, which splits the fill and is the sort of thing a network
                // audits for. Other plugins' waiting pages — the start queue's — say
                // so through AdDisplayService::registerInterstitialRoute().
                if (StartGatePage::isCurrentRoute() || $ads->onInterstitialRoute()) {
                    return null;
                }

                $units = $adsterra->inContentUnits();
                $notice = $ads->antiBlockMode() === 'notice'
                    && $ads->shouldRunAntiBlock($user, $panelId, $isAuthPage);

                // Nothing to show is not the same as nothing configured. Since
                // `hasUnit()` also counts the waiting room's own surfaces — a
                // starting-page-only popunder, the room's zone, a Direct Link —
                // shouldDisplay() can legitimately be true here with no in-content
                // unit to render, and the wrapper's own margins would then leave a
                // gap on every page in the panel.
                if ($units === [] && !$notice) {
                    return null;
                }

                return new HtmlString(view('plugged-host-ads::filament.slot', [
                    'zIndex' => $ads->getZIndex(),
                    'adsterraUnits' => $units,
                    'sandbox' => $adsterra->frameSandbox(),
                    'notice' => $notice,
                ])->render());
            } catch (Throwable) {
                return null;
            }
        };

        FilamentView::registerRenderHook(PanelsRenderHook::CONTENT_END, $render);
        FilamentView::registerRenderHook(PanelsRenderHook::SIMPLE_LAYOUT_END, $render);
    }

    /**
     * Social Bar and Popunder. These draw outside the slot and are injected at
     * the end of the body, where a blocking third-party script costs the least.
     *
     * Also the single home for the waiting room's top-document scripts. The gate
     * page could emit its own, but it polls itself every two seconds, and a script
     * tag inside a Livewire component is re-evaluated on a morph — one Social Bar
     * per tick. Out here, above the component root, it is written once per page load.
     *
     * Which set applies is decided in exactly one place, so no zone can end up in
     * the document twice. The waiting room's set is not a superset of the panel-wide
     * one: a format whose `*_panel_wide` switch is off appears only here, which is
     * how "popunder on the starting page and nowhere else" is configured. Any page
     * registered through `AdDisplayService::registerInterstitialRoute()` counts as a
     * waiting room for this decision, which is how the start queue's page gets the
     * same set without this plugin knowing the class exists.
     */
    protected function registerPageLevelUnits(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            function (): ?Htmlable {
                try {
                    $ads = app(AdDisplayService::class);

                    [$user, $panelId, $isAuthPage] = $this->context();

                    if (!$ads->shouldDisplay($user, $panelId, $isAuthPage)) {
                        return null;
                    }

                    $adsterra = app(AdsterraService::class);

                    $scripts = StartGatePage::isCurrentRoute() || $ads->onInterstitialRoute()
                        ? $adsterra->gateScripts()
                        : $adsterra->pageScripts();

                    if ($scripts === []) {
                        return null;
                    }

                    return new HtmlString(view('plugged-host-ads::filament.adsterra-page-scripts', [
                        'scripts' => $scripts,
                    ])->render());
                } catch (Throwable) {
                    return null;
                }
            },
        );
    }

    protected function registerAdBlockResponse(): void
    {
        // Unconditional while the feature is on — see the view for why it cannot
        // be gated per page the way the detector below is.
        FilamentView::registerRenderHook(
            PanelsRenderHook::STYLES_AFTER,
            function (): ?Htmlable {
                try {
                    if (!app(AdDisplayService::class)->antiBlockEnabled()) {
                        return null;
                    }

                    return new HtmlString(view('plugged-host-ads::filament.styles.adblock')->render());
                } catch (Throwable) {
                    return null;
                }
            },
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::SCRIPTS_AFTER,
            function (): ?Htmlable {
                try {
                    $ads = app(AdDisplayService::class);

                    [$user, $panelId, $isAuthPage] = $this->context();

                    if (!$ads->shouldRunAntiBlock($user, $panelId, $isAuthPage)) {
                        return null;
                    }

                    // JSON_HEX_TAG is load-bearing: the payload carries an
                    // admin-authored message and lands inside a <script>.
                    $payload = json_encode(
                        $ads->antiBlockPayload(),
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
                    );

                    return new HtmlString(view('plugged-host-ads::filament.scripts.adblock', [
                        'payload' => $payload,
                    ])->render());
                } catch (Throwable) {
                    return null;
                }
            },
        );
    }

    /**
     * @return array{0: ?User, 1: ?string, 2: bool}
     */
    protected function context(): array
    {
        $panelId = Filament::getCurrentPanel()?->getId();

        /** @var ?User $user */
        $user = Filament::auth()->user();

        return [$user, $panelId, !Filament::auth()->check()];
    }
}
