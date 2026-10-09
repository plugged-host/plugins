<?php

namespace PluggedHost\Economy\Providers;

use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Economy\Filament\App\Pages\EarnTokensPage;
use PluggedHost\Economy\Services\Rewards\LinkvertiseProvider;
use PluggedHost\Economy\Services\Rewards\RewardRegistry;
use PluggedHost\Economy\Services\TokenEarnService;
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
 * The render hook below loads the Linkvertise Full Script on the earn page only. It was an ad
 * render hook by history, not by nature — earning is opt-in and is not gated on
 * AdDisplayService, so an ad-exempt user who opens the earn page still gets a working link.
 */
class EarnPluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LinkvertiseProvider::class);
        $this->app->singleton(RewardRegistry::class);
        $this->app->singleton(TokenEarnService::class);
    }

    public function boot(): void
    {
        $this->registerLinkvertiseFullScript();
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
}
