<?php

namespace PluggedHost\Economy\Filament\App\Pages;

use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use PluggedHost\Economy\Services\Rewards\LinkvertiseProvider;
use PluggedHost\Economy\Services\Rewards\RewardProvider;
use PluggedHost\Economy\Services\TokenEarnService;
use PluggedHost\Economy\Support\EarnOffers;
use Throwable;

/**
 * Where tokens are earned.
 *
 * The balance is shown here too, but this page is not the ledger's home — that is
 * plugged-host-economy, and the queue page shows the same number. What is unique here
 * is the trip: this is the only surface in the panel that hands a user to a sponsored
 * link and pays them for coming back.
 *
 * Not in the sidebar since 2026-09-01. The Token Store lists the same offers and links
 * here, so this became somewhere you arrive with a trip in mind rather than a second nav
 * entry about money — but it is still routed, and it has to be: see
 * `shouldRegisterNavigation()` for the four call sites that resolve it by string, and
 * `redirectClean()` for where a completed trip now ends.
 *
 * One trip, in two shapes. Either way the user goes out through Linkvertise and
 * lands back on this URL with a hash appended, and `settleReturn()` verifies it
 * inline — the anti-bypass token is valid for a handful of seconds, so a queued job
 * would report every genuine completion as a forgery.
 *
 * What differs is who builds the outbound URL. The **Full Script API** builds it in
 * the browser: `linkvertise.js` rewrites matching anchors once, at
 * `DOMContentLoaded`. The anchor therefore has to be in the HTML this page ships,
 * which means the claim has to exist at render time rather than on a click — that
 * is what `claimUrl()` is for, and why it reuses an open claim instead of opening
 * one per render. A `wire:click` button cannot work in this mode at all: whatever it
 * navigated to would be built long after the script had finished.
 *
 * The **static link** mode is the older one and still supported for a publisher link
 * pinned in the dashboard: nothing is rewritten, `earn()` opens the claim on the
 * click and redirects. `getViewData()` marks which shape each provider is in so the
 * view can render an anchor or a button to match.
 *
 * Nothing here trusts the client with anything but a nonce. Amounts, caps,
 * freshness and replay all live in TokenEarnService.
 *
 * The slug changed from `start-credits` to `tokens` with the rename. That is safe
 * only because the return URL is built at render time in Full Script mode — nothing
 * is pinned in the Linkvertise dashboard, so no bookmarked destination breaks. Do not
 * change it again without checking `ADS_START_LINKVERTISE_USER_ID` is still set.
 */
class EarnTokensPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'tabler-gift';

    protected static ?string $slug = 'tokens';

    protected static ?int $navigationSort = 9;

    protected string $view = 'plugged-host-economy::filament.pages.earn-tokens';

    /**
     * Outbound URLs already built during this request, keyed by provider.
     *
     * One claim per render, however many times the URL is asked for: `startClaim()`
     * opens a row and spends an attempt against the per-hour throttle, so a second
     * call for the same provider in one request would pay twice for one trip.
     *
     * @var array<string, ?string>
     */
    protected array $claimUrls = [];

    /**
     * Never in the sidebar, always routed.
     *
     * Earning is presented on the Token Store now — one place for the balance, what it
     * buys and how to get more — so a second sidebar entry saying "Earn tokens" split
     * the same subject across two nav links, which is the confusion this whole pass is
     * about. What did *not* move is the trip: the Full Script anchor has to be in HTML
     * this page ships, so this page is still where a user goes to start one and still
     * the only URL that can settle the return. The Economy block links here.
     *
     * So: no navigation entry, but `canAccess()` deliberately keeps answering, because
     * four places resolve this route by string — the Economy block, the store page's
     * `earnTokensUrl()`, StartQueuePage, StartGatePage — and a Filament page whose
     * `canAccess()` is false has no route to resolve at all.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /**
     * Reachable only while the currency is switched off, rather than shown as an empty
     * page.
     *
     * This asked `ADS_START_ENABLED` until 2026-08-31, back when a token's only use
     * was buying a way past the start gate. Tokens are the queue's currency now, so
     * the gate's switch says nothing about whether earning them is worthwhile — the
     * economy's does. Read straight from config rather than through TokenEarnService:
     * Filament calls this on every request, including before the container could
     * resolve a service whose dependency lives in another plugin.
     */
    public static function canAccess(): bool
    {
        return static::currencyEnabled() && parent::canAccess();
    }

    protected static function currencyEnabled(): bool
    {
        return (bool) config('plugged-host-economy.tokens.enabled', true);
    }

    public static function getNavigationLabel(): string
    {
        return trans('plugged-host-economy::strings.tokens_nav');
    }

    /**
     * Whether the request being rendered is this page.
     *
     * AdsPluginServiceProvider asks before emitting the Linkvertise Full Script
     * snippet, because that script rewrites every anchor it is allowed to match:
     * loading it panel-wide would be a monetised sidebar. This is the narrowest
     * condition available server-side, and the whitelist is the second guard.
     */
    public static function isCurrentRoute(): bool
    {
        try {
            if (Filament::getCurrentPanel()?->getId() !== 'app') {
                return false;
            }

            return request()->routeIs(static::getRouteName());
        } catch (Throwable) {
            return false;
        }
    }

    public function getTitle(): string
    {
        return trans('plugged-host-economy::strings.tokens_title');
    }

    public function getSubheading(): ?string
    {
        return trans('plugged-host-economy::strings.tokens_subheading');
    }

    public function mount(): void
    {
        $this->tokens()->syncDailyGrant($this->getUser());
        $this->settleReturn();
    }

    /**
     * Settle a return trip, if this request looks like one.
     *
     * The bar for "looks like one" is a provider token actually present in the query
     * string. Without that check, someone opening the bare `?provider=linkvertise`
     * URL would have their own pending claim rejected for arriving without a token —
     * which is why a tokenless return is reported to the user but never charged
     * against the claim.
     */
    protected function settleReturn(): void
    {
        try {
            $user = $this->getUser();

            if (!$user instanceof User) {
                return;
            }

            $query = request()->query();

            if (!is_array($query) || $query === []) {
                return;
            }

            $provider = $this->resolveProvider($query);

            if (!$provider instanceof RewardProvider) {
                return;
            }

            if ($provider->extractToken($query) === null) {
                // A return trip with no token is almost always one setting:
                // Anti-Bypass is off on the publisher account, so nothing verifiable
                // is appended and there is nothing we can check. Say so instead of
                // rendering an unchanged balance and letting them wonder — and leave
                // the claim pending, because a genuine completion may still arrive.
                // redeem() records the parameter names it *did* see on the claim row,
                // which is how this gets diagnosed from the outside.
                if ($this->tokens()->latestPendingNonce($user, $provider) !== null) {
                    $this->notifyResult([
                        'status' => TokenEarnService::RESULT_NO_TOKEN,
                        'amount' => 0,
                        'provider' => $provider->key(),
                    ]);

                    $this->redirectClean();
                }

                return;
            }

            $nonce = $this->extractNonce($query) ?? $this->tokens()->latestPendingNonce($user, $provider);

            if ($nonce === null) {
                $this->notifyResult(['status' => TokenEarnService::RESULT_NO_CLAIM, 'amount' => 0, 'provider' => $provider->key()]);
                $this->redirectClean();

                return;
            }

            $this->notifyResult($this->tokens()->redeem($user, $nonce, $query));

            // Drop the token out of the address bar. It is single-use and already
            // spent, and leaving it there means a refresh reports a replay at the
            // user as though they had done something wrong.
            $this->redirectClean();
        } catch (Throwable) {
            // A failed settlement must still render the page — the balance and the
            // history are what the user came for.
        }
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function resolveProvider(array $query): ?RewardProvider
    {
        $key = $query['provider'] ?? null;
        $provider = $this->tokens()->registry()->find(is_string($key) ? $key : null);

        if ($provider instanceof RewardProvider) {
            return $provider;
        }

        // The `provider` hint rides on the return URL, which a pinned destination
        // may not preserve. Fall back to whichever configured provider recognises
        // a token in this query string.
        foreach ($this->tokens()->registry()->available() as $candidate) {
            if ($candidate->extractToken($query) !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The nonce, under either of the names a return URL can carry it.
     *
     * `lvr` is the Full Script name and doubles as the whitelist discriminator (see
     * `nonceParam()`); `nonce` is what the static-link mode has always used. Both are
     * accepted here so a trip opened before a config change can still settle.
     *
     * @param  array<string, mixed>  $query
     */
    protected function extractNonce(array $query): ?string
    {
        foreach ([LinkvertiseProvider::RETURN_PARAM, 'nonce'] as $param) {
            $nonce = $query[$param] ?? null;

            if (is_string($nonce) && preg_match('/^[A-Za-z0-9]{32,64}$/', $nonce)) {
                return $nonce;
            }
        }

        return null;
    }

    /**
     * Where a settled return trip ends: this page, freshly loaded, with the new balance and the
     * new row in the history.
     *
     * `navigate: false` is load-bearing. The token is single-use and already spent, and leaving
     * it in the address bar means a refresh reports a replay at the user as though they had
     * done something wrong — so the address bar has to actually change, which a Livewire
     * redirect within the same page would not guarantee.
     */
    protected function redirectClean(): void
    {
        $this->redirect(static::getUrl(panel: 'app'), navigate: false);
    }

    /**
     * A fresh copy of *this* page, and therefore a freshly rendered anchor.
     *
     * Deliberately not redirectClean(): the Token Store carries no outbound anchor, so
     * it is no answer at all to a click on markup that predates a config change.
     */
    protected function redirectFresh(): void
    {
        $this->redirect(static::getUrl(panel: 'app'), navigate: false);
    }

    /**
     * Open a trip for a static-link provider.
     *
     * Public because the header action calls it. Full Script never comes through
     * here — its link is an anchor in the rendered page, so there is no server-side
     * URL to redirect to; a click that lands here came from markup that predates a
     * config change, and the honest answer is a fresh page with a fresh anchor.
     */
    public function earn(string $key): void
    {
        $user = $this->getUser();
        $provider = $this->tokens()->registry()->find($key);

        if (!$user instanceof User || !$provider instanceof RewardProvider) {
            $this->notifyResult(['status' => TokenEarnService::RESULT_INVALID, 'amount' => 0, 'provider' => $key]);

            return;
        }

        if ($this->isFullScript($provider)) {
            $this->redirectFresh();

            return;
        }

        $result = $this->tokens()->startClaim($user, $provider, $this->returnUrl($provider));

        if ($result['status'] !== TokenEarnService::RESULT_OK || $result['url'] === null) {
            $this->notifyResult([
                'status' => $result['status'] === TokenEarnService::RESULT_OK
                    ? TokenEarnService::RESULT_INVALID
                    : $result['status'],
                'amount' => 0,
                'provider' => $provider->key(),
            ]);

            return;
        }

        $this->redirect($result['url'], navigate: false);
    }

    /**
     * The outbound URL to put in the markup for a Full Script provider.
     *
     * Reuses this user's open claim before opening another, so reloading the page
     * does not leave a trail of pending rows or spend the per-hour attempt throttle
     * on nothing. Null means there is nothing to offer — no user, cap reached, or the
     * claim could not be opened — and the card renders without a link rather than
     * with one that cannot pay out.
     */
    protected function claimUrl(RewardProvider $provider): ?string
    {
        $key = $provider->key();

        if (array_key_exists($key, $this->claimUrls)) {
            return $this->claimUrls[$key];
        }

        return $this->claimUrls[$key] = $this->buildClaimUrl($provider);
    }

    protected function buildClaimUrl(RewardProvider $provider): ?string
    {
        try {
            $user = $this->getUser();
            $service = $this->tokens();

            if (!$user instanceof User || $service->capReached($user, $provider)) {
                return null;
            }

            $claim = $service->latestPendingClaim($user, $provider);

            if ($claim !== null) {
                return $provider->buildUrl(
                    $claim,
                    str_replace('{nonce}', rawurlencode($claim->nonce), $this->returnUrl($provider)),
                );
            }

            $result = $service->startClaim($user, $provider, $this->returnUrl($provider));

            return $result['status'] === TokenEarnService::RESULT_OK ? $result['url'] : null;
        } catch (Throwable) {
            // A card with no link is a poor render; a 500 on the page that shows the
            // balance and the history is worse.
            return null;
        }
    }

    /**
     * Where the provider should drop the user afterwards.
     *
     * `{nonce}` is substituted once the claim row exists — the nonce does not exist
     * before it.
     */
    protected function returnUrl(RewardProvider $provider): string
    {
        return static::getUrl(panel: 'app')
            . '?provider=' . rawurlencode($provider->key())
            . '&' . $this->nonceParam($provider) . '={nonce}';
    }

    /**
     * Query key the nonce rides back on.
     *
     * In Full Script mode this doubles as the discriminator the client-side whitelist
     * matches, which is why it is a bare literal: linkvertise.js turns its patterns
     * into a regex, so a marker like `tokens?lv` would be read as a quantifier and
     * match nothing. `extractNonce()` accepts either name on the way back.
     */
    protected function nonceParam(RewardProvider $provider): string
    {
        return $this->isFullScript($provider) ? LinkvertiseProvider::RETURN_PARAM : 'nonce';
    }

    protected function isFullScript(RewardProvider $provider): bool
    {
        return $provider instanceof LinkvertiseProvider && $provider->usesFullScript();
    }

    /**
     * @param  array{status: string, amount: int, provider: ?string}  $result
     */
    protected function notifyResult(array $result): void
    {
        $status = $result['status'];

        $notification = Notification::make()->title(match ($status) {
            TokenEarnService::RESULT_GRANTED => trans('plugged-host-economy::strings.tokens_result_granted', ['amount' => $result['amount']]),
            TokenEarnService::RESULT_SETTLED => trans('plugged-host-economy::strings.tokens_result_settled'),
            TokenEarnService::RESULT_EXPIRED => trans('plugged-host-economy::strings.tokens_result_expired'),
            TokenEarnService::RESULT_NO_TOKEN => trans('plugged-host-economy::strings.tokens_result_no_token'),
            TokenEarnService::RESULT_NO_CLAIM => trans('plugged-host-economy::strings.tokens_result_no_claim'),
            TokenEarnService::RESULT_REPLAY => trans('plugged-host-economy::strings.tokens_result_replay'),
            TokenEarnService::RESULT_CAPPED => trans('plugged-host-economy::strings.tokens_result_capped'),
            TokenEarnService::RESULT_THROTTLED => trans('plugged-host-economy::strings.tokens_result_throttled'),
            TokenEarnService::RESULT_INVALID => trans('plugged-host-economy::strings.tokens_result_invalid'),
            default => trans('plugged-host-economy::strings.tokens_result_error'),
        });

        if ($status === TokenEarnService::RESULT_GRANTED) {
            $notification->icon('tabler-gift')->success()->send();

            return;
        }

        $notification->warning()->send();
    }

    /**
     * Header buttons — for the static-link mode only.
     *
     * A Full Script provider gets none, and that is a correctness requirement rather
     * than a layout choice. `linkvertise.js` rewrites *every* anchor matching the
     * whitelist, and the whitelist is the `lvr=` our return URL carries, so a header
     * button rendered as `->url($claimUrl)` is a second rewritten anchor for the same
     * claim: the same trip offered twice, two dynamic links built from one nonce, and
     * the page no longer has a single answer to "where does the user go to earn".
     * The card in the view is that anchor, and it is where the reward, the cap and the
     * explanation already live.
     *
     * Static-link mode is unaffected: its button is a `wire:click` into `earn()`, not
     * an anchor, so there is nothing for the script to rewrite even if it were loaded.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        $actions = [];

        foreach ($this->tokens()->registry()->available() as $provider) {
            if ($this->isFullScript($provider)) {
                continue;
            }

            $key = $provider->key();

            $actions[] = Action::make("earn_{$key}")
                ->label(trans('plugged-host-economy::strings.tokens_earn_with', ['provider' => $provider->label()]))
                ->color('gray')
                ->action(fn () => $this->earn($key));
        }

        return $actions;
    }

    protected function getUser(): ?User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : null;
    }

    protected function tokens(): TokenEarnService
    {
        return app(TokenEarnService::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = $this->getUser();
        $service = $this->tokens();
        $offers = app(EarnOffers::class);

        $providers = [];

        foreach ($service->registry()->available() as $provider) {
            $providers[] = $offers->card($user, $provider) + [
                // Only Full Script needs its URL in the markup, which is also why the
                // shared card does not carry one: building it opens a claim row, and the
                // Economy block renders the same cards without wanting that. Building one
                // for the static-link mode would open a row on every page view for a trip
                // the user has not started.
                'url' => $offers->isFullScript($provider) ? $this->claimUrl($provider) : null,
            ];
        }

        return [
            'balance' => $service->balance($user),
            'providers' => $providers,
            'transactions' => $service->recentTransactions($user, 10),
        ];
    }
}
