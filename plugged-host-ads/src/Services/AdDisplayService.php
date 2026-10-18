<?php

namespace PluggedHost\Ads\Services;

use App\Models\User;
use PluggedHost\Ads\Models\AdBanner;
use PluggedHost\Core\Support\Plugins;

class AdDisplayService
{
    public const ANTIBLOCK_MODES = ['notice', 'modal', 'block'];

    /** plugged-host-economy's Plus/PlusPlus service, asked by class name (optional dependency). */
    private const MEMBERSHIP = 'PluggedHost\\Economy\\Services\\MembershipService';

    /**
     * Other plugins' pages that are themselves an ad interstitial.
     *
     * The start gate is one; plugged-host-queue's waiting page is another, and there
     * is no reason a third could not exist. Such a page renders its own unit stack
     * from `AdsterraService::gateUnits()` and wants the waiting room's page-level
     * scripts, so the generic in-content slot must stay out of that document — the
     * same zone served twice in one page splits the fill and is what a network audits
     * for.
     *
     * A callable rather than a class name, and static rather than config, because the
     * registrant is a service provider in a plugin this one must not depend on: the
     * queue registers itself here at boot and the ads plugin never learns its class.
     * Each callable is wrapped so a page that throws while deciding is treated as
     * "not an interstitial" rather than taking the whole render hook down.
     *
     * @var array<int, callable(): bool>
     */
    protected static array $interstitialRoutes = [];

    /**
     * Other plugins' reasons that this particular user sees no advertising.
     *
     * Same shape and same reasoning as the registry above, for the other question ads
     * gets asked from outside: plugged-host-queue sells a priority tier whose second
     * half is a window with no ads in it, and it registers that window here rather than
     * this plugin learning what a token is.
     *
     * They are consulted from `isExemptUser()`, which means one registration removes
     * banners, the social bar, the popunder, the start gate *and* the ad-block detector
     * for that user — because all five already hang off that one method. Anything less
     * would be selling "fewer ads".
     *
     * @var array<int, callable(mixed): bool>
     */
    protected static array $exemptionChecks = [];

    /**
     * Memoized exemption results per user ID for the current request.
     *
     * @var array<int, bool>
     */
    protected static array $exemptUserMemo = [];

    public function __construct(
        protected AdsterraService $adsterra,
        protected AdBannerService $sponsored,
    ) {}

    /**
     * @param  callable(): bool  $check
     */
    public static function registerInterstitialRoute(callable $check): void
    {
        static::$interstitialRoutes[] = $check;
    }

    /**
     * @param  callable(mixed): bool  $check
     */
    public static function registerExemptionCheck(callable $check): void
    {
        static::$exemptionChecks[] = $check;
    }

    /**
     * Is the request being rendered one of those pages?
     *
     * Called from two render hooks per request, so the checks it runs are expected to
     * be route comparisons and nothing heavier.
     */
    public function onInterstitialRoute(): bool
    {
        foreach (static::$interstitialRoutes as $check) {
            try {
                if ($check()) {
                    return true;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return false;
    }

    public function isEnabled(): bool
    {
        return (bool) config('plugged-host-ads.enabled', true);
    }

    public function getZIndex(): int
    {
        return (int) config('plugged-host-ads.z_index', 0);
    }

    /**
     * @return array<int, string>
     */
    public function getExemptRoles(): array
    {
        $raw = config('plugged-host-ads.exempt_roles', '');
        if (is_array($raw)) {
            return array_values(array_filter($raw));
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $raw))));
    }

    /**
     * Is this user excused from advertising — by role, or by something another plugin
     * registered?
     *
     * Extracted so the start gate can ask the same question the slot does. One answer
     * has to mean one thing: a donator who was promised no ads has not been promised
     * "no banners, but still a waiting room". The registered checks (today: plugged.host
     * PlusPlus, plugged-host-economy) inherit that promise for the same reason.
     *
     * Note the order. Roles are free to evaluate and hold for whole populations, so they
     * are asked first and a match short-circuits before anything reaches a database.
     */
    public function isExemptUser(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $userId = (int) $user->getKey();
        if ($userId > 0 && isset(static::$exemptUserMemo[$userId])) {
            return static::$exemptUserMemo[$userId];
        }

        if ($this->isExemptByRole($user)) {
            return static::$exemptUserMemo[$userId] = true;
        }

        return static::$exemptUserMemo[$userId] = $this->hasRegisteredExemption($user);
    }

    /**
     * The exempt-role half of isExemptUser(), without what other plugins registered (today
     * PlusPlus). The start queue lets exempt roles skip the line; a paid ad exemption only buys
     * priority in it.
     */
    public function isExemptByRole(?User $user): bool
    {
        $exemptRoles = $this->getExemptRoles();

        if ($user === null || empty($exemptRoles)) {
            return false;
        }

        try {
            return $user->roles()->whereIn('name', $exemptRoles)->exists();
        } catch (\Throwable) {
            // Fallback to in-memory collection check if relations not loaded
            return $user->roles && $user->roles->pluck('name')->intersect($exemptRoles)->isNotEmpty();
        }
    }

    /**
     * Ask the other plugins.
     *
     * Each is wrapped: a check that throws means "no exemption" rather than an error
     * page, because this runs inside a render hook on every page in the panel. Erring
     * that way shows an ad to somebody who paid not to see one — which is a refund, not
     * an outage, and is the cheaper of the two mistakes.
     */
    protected function hasRegisteredExemption(?User $user): bool
    {
        foreach (static::$exemptionChecks as $check) {
            try {
                if ($check($user)) {
                    return true;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return false;
    }

    /**
     * Is there anything at all to render in the ad slots? "Did an admin paste in at
     * least one usable Adsterra unit", or is a sponsored banner for the slots live —
     * which takes Adsterra's place there and so needs no Adsterra to show. It is what
     * makes an unconfigured install render nothing rather than an empty bordered box.
     *
     * Adsterra is asked first: it is config, the banners are a cache read.
     */
    public function hasUnit(): bool
    {
        if ($this->adsterra->isConfigured()) {
            return true;
        }

        try {
            return $this->sponsored->hasLive(AdBanner::PLACEMENT_SLOTS);
        } catch (\Throwable) {
            return false;
        }
    }

    public function shouldDisplay(?User $user = null, ?string $panelId = null, bool $isAuthPage = false): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        if (!$this->hasUnit()) {
            return false;
        }

        return $this->showsAdsTo($user, $panelId, $isAuthPage);
    }

    /**
     * The audience rule on its own: does this user, on this panel, see advertising at
     * all? The master switch, signed-out pages, the per-panel switch and the exemptions
     * (roles, and what other plugins registered: PlusPlus). `shouldDisplay()` is this
     * plus "is there a unit to show"; the top banner asks it directly, because it is its
     * own unit and needs no Adsterra.
     */
    public function showsAdsTo(?User $user = null, ?string $panelId = null, bool $isAuthPage = false): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        // Signed-out pages never show ads (see config: Panel Placements).
        if ($isAuthPage) {
            return false;
        }

        if ($panelId !== null) {
            $panelSettingKey = match ($panelId) {
                'app' => 'plugged-host-ads.show_app_panel',
                'server' => 'plugged-host-ads.show_server_panel',
                'admin' => 'plugged-host-ads.show_admin_panel',
                default => null,
            };

            if ($panelSettingKey !== null && !config($panelSettingKey, true)) {
                return false;
            }
        }

        if ($user !== null && $this->isExemptUser($user)) {
            return false;
        }

        return true;
    }

    /**
     * Request-scoped count of ad units already granted this render (WP4). The single guarantee
     * that "more inventory" cannot become a wall of banners.
     */
    protected static int $unitsGranted = 0;

    /** The hard per-document cap. Refuses the (max+1)-th unit whatever asks for it. */
    public function maxUnitsPerPage(): int
    {
        return max(0, (int) config('plugged-host-ads.max_units_per_page', 2));
    }

    /** Zones already served this document, so the same zone is never served twice (WP4 rule). */
    protected static array $zonesGranted = [];

    /**
     * The named-slot API (WP4). Answers whether the slot may render right now — master switch,
     * per-panel switch, exempt user, interstitial route, the slot's own toggle, no-zone-twice,
     * then the per-page cap — and RESERVES a unit when it says yes, so the caller renders exactly
     * what was granted. A new placement is now a config line plus one `<x-…::slot name="…" />`.
     */
    public function slot(string $name, ?User $user = null, ?string $panelId = null, bool $isAuthPage = false): bool
    {
        $placements = (array) config('plugged-host-ads.placements', []);
        $slot = is_array($placements[$name] ?? null) ? $placements[$name] : null;

        if ($slot === null || !($slot['enabled'] ?? false)) {
            return false;
        }

        if (!$this->shouldDisplay($user, $panelId, $isAuthPage)) {
            return false;
        }

        // A slot that is itself on an interstitial route defers to the interstitial's own units.
        if ($this->onInterstitialRoute()) {
            return false;
        }

        $zone = (string) ($slot['zone'] ?? $name);

        if (in_array($zone, self::$zonesGranted, true)) {
            return false; // never the same zone twice in one document
        }

        if (self::$unitsGranted >= $this->maxUnitsPerPage()) {
            return false; // the cap
        }

        self::$unitsGranted++;
        self::$zonesGranted[] = $zone;

        return true;
    }

    /** The resolved config for a named slot (zone, format, max_height), or null. */
    public function slotConfig(string $name): ?array
    {
        $placements = (array) config('plugged-host-ads.placements', []);

        return is_array($placements[$name] ?? null) ? $placements[$name] : null;
    }

    /**
     * Seconds in view before a sandboxed unit reloads for this user, or 0 for no refresh.
     * Plus owners get the slower plus_seconds; PlusPlus never gets here (exempt from ads).
     */
    public function refreshSeconds(?User $user): int
    {
        if (!(bool) config('plugged-host-ads.refresh.enabled', true)) {
            return 0;
        }

        $seconds = max(5, (int) config('plugged-host-ads.refresh.seconds', 5));

        try {
            if ($user !== null && Plugins::available(self::MEMBERSHIP) && app(self::MEMBERSHIP)->hasPlus($user)) {
                return max($seconds, (int) config('plugged-host-ads.refresh.plus_seconds', 10));
            }
        } catch (\Throwable) {
            // No membership answer: the everyone interval.
        }

        return $seconds;
    }

    /**
     * The in-game `say` text: ADS_IN_GAME_MESSAGE, or the default built from the panel's name
     * when that is empty, flattened to one line.
     */
    public function inGameMessage(): string
    {
        $message = trim((string) config('plugged-host-ads.in_game.message', ''));

        if ($message === '') {
            $message = $this->defaultInGameMessage();
        }

        return trim(preg_replace('/[\r\n\0]+/', ' ', $message) ?? '');
    }

    /** "This server is powered by <APP_NAME>". */
    public function defaultInGameMessage(): string
    {
        return 'This server is powered by ' . config('app.name');
    }

    public function antiBlockEnabled(): bool
    {
        return (bool) config('plugged-host-ads.antiblock.enabled', false);
    }

    public function antiBlockMode(): string
    {
        $mode = strtolower(trim((string) config('plugged-host-ads.antiblock.mode', 'notice')));

        return in_array($mode, self::ANTIBLOCK_MODES, true) ? $mode : 'notice';
    }

    /**
     * Detection piggybacks on the ad slot: it only runs where an ad was actually
     * asked for, so it inherits the master switch, the per-panel placements and
     * the role exemptions instead of restating any of them.
     *
     * The console is carved out separately. It is the one page where someone is
     * mid-task on something they are paying for, and an overlay there turns a
     * browser extension into a support ticket.
     */
    public function shouldRunAntiBlock(?User $user = null, ?string $panelId = null, bool $isAuthPage = false): bool
    {
        if (!$this->antiBlockEnabled()) {
            return false;
        }

        if (!$this->shouldDisplay($user, $panelId, $isAuthPage)) {
            return false;
        }

        if ((bool) config('plugged-host-ads.antiblock.skip_console', true)
            && request()->routeIs('filament.server.pages.console')) {
            return false;
        }

        return true;
    }

    /**
     * The payload handed to the browser. Primitives only, so it can be encoded
     * straight into the injected script.
     *
     * @return array<string, mixed>
     */
    public function antiBlockPayload(): array
    {
        $custom = trim((string) config('plugged-host-ads.antiblock.message', ''));

        // The default text names the panel and the host to allow ads on (APP_NAME, APP_URL).
        $url = (string) config('app.url');
        $brand = [
            'app' => (string) config('app.name'),
            'host' => (string) (parse_url($url, PHP_URL_HOST) ?: $url),
        ];

        return [
            'mode' => $this->antiBlockMode(),
            'grace' => max(0, (int) config('plugged-host-ads.antiblock.grace', 4)),
            'dismissHours' => max(0, (int) config('plugged-host-ads.antiblock.dismiss_hours', 12)),
            'probes' => config('plugged-host-ads.antiblock.probe', true) ? $this->probeUrls() : [],
            'strings' => [
                'heading' => (string) trans('plugged-host-ads::strings.antiblock_heading', $brand),
                'body' => $custom !== '' ? $custom : (string) trans('plugged-host-ads::strings.antiblock_body', $brand),
                'reload' => (string) trans('plugged-host-ads::strings.antiblock_reload'),
                'dismiss' => (string) trans('plugged-host-ads::strings.antiblock_dismiss'),
                'continue' => (string) trans('plugged-host-ads::strings.antiblock_continue'),
                'continueIn' => (string) trans('plugged-host-ads::strings.antiblock_continue_in'),
            ],
        ];
    }

    /**
     * URLs the detector fetches to see whether our ad hosts are reachable at
     * all. This is the signal that catches a DNS-level blocker, which blocks the
     * request but leaves the page markup untouched.
     *
     * @return array<int, string>
     */
    public function probeUrls(): array
    {
        return array_values(array_unique($this->adsterra->probeUrls()));
    }
}
