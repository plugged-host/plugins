<?php

namespace PluggedHost\Economy\Providers;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Economy\Filament\App\Pages\EarnTokensPage;
use PluggedHost\Economy\Services\Rewards\LinkvertiseProvider;
use PluggedHost\Economy\Services\Rewards\RewardRegistry;
use PluggedHost\Economy\Services\TokenEarnService;
use PluggedHost\Economy\Support\EarnOffers;
use Throwable;

/**
 * Token earning: the off-site reward providers behind the Tokens page.
 *
 * This half used to live in plugged-host-ads (it grew up beside the start gate, which is
 * still there). It moved here in the 1.2.0 re-division because the user asked for one home
 * for money — the balance, the ledger, the store and now the way you top the balance up all
 * sit in economy, and ads is left with the impressions. The start gate itself stays in ads:
 * it is an ad surface that happens to mention tokens, not a token feature.
 *
 * `src/Providers/` is auto-registered by App\Services\Helpers\PluginService, so dropping this
 * file in is the whole registration — no list anywhere learns about it. Singletons because the
 * registry takes its one provider by constructor injection and the earn service reads the same
 * config and cache keys on every hit. TokenEarnService takes economy's own TokenService as its
 * second argument, resolved out of the container.
 *
 * The two render hooks below draw the earn surfaces that sit outside the earn page: the
 * Linkvertise Full Script (earn page only) and the earn block on economy's own store page. They
 * were ad render hooks by history, not by nature — earning is opt-in and neither is gated on
 * AdDisplayService, so an ad-exempt user who opens the earn page still gets a working link.
 */
class EarnPluginServiceProvider extends ServiceProvider
{
    /**
     * The hook the Economy (resource store) page renders under its token wallet.
     *
     * A literal on both sides, and on purpose: matching it by string is what lets a render
     * hook put content on the store page without the page importing the hook. Economy's own
     * copy is `ResourceStorePage::HOOK_AFTER_TOKENS` — if you rename one, grep for the string,
     * not for the constant. (Both live in this plugin now, but the string contract is kept so
     * the page stays decoupled from who fills the slot.)
     */
    protected const STORE_HOOK = 'plugged-host-economy::store.after-tokens';

    public function register(): void
    {
        $this->app->singleton(LinkvertiseProvider::class);
        $this->app->singleton(RewardRegistry::class);
        $this->app->singleton(TokenEarnService::class);
    }

    public function boot(): void
    {
        $this->registerLinkvertiseFullScript();
        $this->registerStoreEarnBlock();
    }

    /**
     * The Linkvertise Full Script API, on the earn page only.
     *
     * Deliberately its own hook: this script is not an ad unit but a link rewriter, and it
     * rewrites *every* anchor the whitelist matches in whatever document it loads into. So the
     * route check is the primary guard and the server-built whitelist is the second.
     *
     * BODY_END so it sits above the Livewire component root — a morph cannot re-evaluate the
     * tag — and because the script needs to be in the document before DOMContentLoaded.
     *
     * Not gated on AdDisplayService: earning is opt-in and this is the mechanism rather than an
     * impression, so an ad-exempt user who chooses to open the earn page still gets a working link.
     */
    protected function registerLinkvertiseFullScript(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            function (): ?Htmlable {
                try {
                    if (!EarnTokensPage::isCurrentRoute()) {
                        return null;
                    }

                    $linkvertise = app(LinkvertiseProvider::class);

                    if (!$linkvertise->isConfigured() || !$linkvertise->usesFullScript()) {
                        return null;
                    }

                    $whitelist = $linkvertise->whitelist();

                    // Belt and braces: whitelist() already refuses to return an empty
                    // list, and an empty one here would tell the script to rewrite
                    // every link in the document.
                    if ($whitelist === []) {
                        return null;
                    }

                    return new HtmlString(view('plugged-host-economy::filament.linkvertise-fullscript', [
                        'scriptUrl' => LinkvertiseProvider::SCRIPT_URL,
                        'userId' => (int) $linkvertise->userId(),
                        // Lands inside a <script>, so the same hex flags the ad-block
                        // payload uses apply, even though whitelist() has already
                        // restricted the alphabet.
                        'whitelist' => json_encode(
                            array_values($whitelist),
                            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
                                | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
                        ),
                    ])->render());
                } catch (Throwable) {
                    return null;
                }
            },
        );
    }

    /**
     * The earning offers, on the Economy (resource store) page, under the token wallet.
     *
     * The user asked for one place for money, so the offers appear next to the balance they add
     * to. What does *not* move is the trip: the block renders links to EarnTokensPage, never the
     * sponsored anchor. In Full Script mode that anchor only pays if linkvertise.js rewrote it,
     * and that script is loaded on the earn page alone — see registerLinkvertiseFullScript(),
     * which must not be widened to this route. An anchor here would open, look correct, and earn
     * nothing.
     *
     * Everything is inside a try/catch that answers null, because this closure runs while
     * rendering the store page: with tokens switched off `canAccess()` closes it.
     */
    protected function registerStoreEarnBlock(): void
    {
        FilamentView::registerRenderHook(
            self::STORE_HOOK,
            function (): ?Htmlable {
                try {
                    if (!EarnTokensPage::canAccess()) {
                        return null;
                    }

                    [$user] = $this->context();

                    if (!$user instanceof User) {
                        return null;
                    }

                    return new HtmlString(view('plugged-host-economy::filament.store-earn', [
                        // Presentation only. EarnOffers deliberately opens no claim, so
                        // rendering the store page cannot spend an attempt against the
                        // per-hour throttle that the real trip needs.
                        'providers' => app(EarnOffers::class)->cards($user),
                        'earnUrl' => $this->earnUrl(),
                    ])->render());
                } catch (Throwable) {
                    return null;
                }
            },
        );
    }

    protected function earnUrl(): ?string
    {
        try {
            return EarnTokensPage::getUrl(panel: 'app');
        } catch (Throwable) {
            return null;
        }
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
