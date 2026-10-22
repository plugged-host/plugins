<?php

namespace PluggedHost\Ads\Services;

use JsonException;
use stdClass;
use Throwable;

/**
 * Turns the Adsterra settings into renderable units.
 *
 * Two things shape this class. First, every value it reads is free text pasted
 * out of the Adsterra dashboard, and the banner ends up inside a `srcdoc`
 * document assembled here by hand — so a stray quote in a key would become
 * markup rather than data. Anything that does not look like what Adsterra
 * issues is therefore dropped, degrading to "no ad" instead of to broken HTML.
 *
 * Second, Adsterra rotates the host it serves from and issues a different one
 * per unit, which is why no host is a constant: a unit that suddenly stops
 * filling is usually a stale host in `.env`, not a bug in here.
 *
 * Third, a zone is served at most once per page: every banner and the native unit
 * are handed out from a request-scoped pool (`take*()`), so two placements on one
 * page can never ask for the same zone, which splits its fill.
 *
 * Fourth, a sponsored banner the owner sold himself (AdBannerService, format `direct`)
 * takes the place of the banner unit of its size wherever one is handed out here. With
 * none live for that size, the unit is Adsterra's exactly as it was.
 *
 * @phpstan-type AdsterraUnit array{id: string, format: string, width: int|null, height: int, isolated: bool, document: string|null, markup: string|null, options: string|null, src: string|null, units?: array<int, array<string, mixed>>}
 * @phpstan-type BannerZone array{key: string, size: string, host: string}
 */
class AdsterraService
{
    /** Sizes Adsterra fills for display banners. */
    public const BANNER_SIZES = ['728x90', '468x60', '320x50', '300x250', '160x600', '160x300'];

    /**
     * How many pool banners the waiting room renders beside its leaderboard.
     *
     * A cap, not a target. Each one is a separate network request that has to fill
     * before the page settles, and past a handful the marginal impression is worth
     * less than the layout it costs.
     */
    public const MAX_GATE_BANNERS = 6;

    /** Upper bound on configured banner zones (ADS_ADSTERRA_BANNERS). */
    public const MAX_POOL = 24;

    /** The horizontal banners, widest first: one slot that takes whichever fits. */
    public const LEADERBOARD = '728x90|468x60|320x50';

    /**
     * Unit spec of the in-content slot at the foot of every page. The leaderboard is usually
     * on the page already (the `top` slot renders first), so the foot is mostly the rectangle
     * and the native unit.
     */
    public const IN_CONTENT = [self::LEADERBOARD, '300x250', 'native'];

    /** @var array<string, true> Banner keys already handed out in this request. */
    protected array $taken = [];

    protected bool $nativeTaken = false;

    /** @var array<int, AdsterraUnit>|null */
    protected ?array $gateUnits = null;

    public function __construct(protected AdBannerService $sponsored) {}

    /**
     * Units for the in-content slot at the foot of every page, in render order.
     *
     * @return array<int, AdsterraUnit>
     */
    public function inContentUnits(): array
    {
        return $this->unitsFor(self::IN_CONTENT);
    }

    /**
     * Units for a list of specs: `native`, a size (`300x250`), or sizes joined by `|`,
     * widest first (`728x90|468x60|320x50`), which the browser narrows to the widest
     * that fits its container (see `pick()`). Each one comes out of this request's
     * pool, so a spec whose zones are already on the page renders nothing.
     *
     * @param  array<int, string>  $specs
     * @return array<int, AdsterraUnit>
     */
    public function unitsFor(array $specs): array
    {
        $units = [];

        foreach ($specs as $spec) {
            $spec = trim((string) $spec);

            $units[] = $spec === 'native' ? $this->takeNative() : $this->takeSpec($spec);
        }

        return array_values(array_filter($units));
    }

    /**
     * One banner spec: a size, or sizes joined by `|`.
     *
     * Adsterra's unit is taken from the pool exactly as before, and then a live sponsored
     * banner of one of the spec's sizes that is not on the page yet takes its place. The
     * zones are taken all the same, on purpose: every other placement on the page (and the
     * waiting room's extras) then gets the zones it always got, so a sponsored banner changes
     * the one unit it replaces and nothing else. A spec Adsterra has no zone for still takes
     * a sponsored banner of its size.
     *
     * @return AdsterraUnit|array<string, mixed>|null
     */
    protected function takeSpec(string $spec): ?array
    {
        $unit = str_contains($spec, '|') ? $this->pick(explode('|', $spec)) : $this->takeBanner($spec);

        return $this->sponsoredFor(explode('|', $spec)) ?? $unit;
    }

    /**
     * A sponsored banner for these sizes, or null. Never throws: no banner is the fallback,
     * and the fallback is the Adsterra unit.
     *
     * @param  array<int, string>  $sizes
     * @return array<string, mixed>|null
     */
    protected function sponsoredFor(array $sizes): ?array
    {
        try {
            return $this->sponsored->take($sizes);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Everything the waiting room renders inside the page, in order.
     *
     * The gate is the one surface where more units is the point — the user is
     * waiting on a timer either way, so the page may as well be worth something —
     * so after the leaderboard it takes every zone left in the pool, up to the cap,
     * then the native unit. Turning the switches off leaves the countdown, which is a
     * legitimate configuration: `isEnabled()` on the gate already refuses to run with
     * no Adsterra at all.
     *
     * Remembered for the request: the gate and the start queue both ask from their
     * view data, and a second call would find the pool already handed out.
     *
     * @return array<int, AdsterraUnit>
     */
    public function gateUnits(): array
    {
        if ($this->gateUnits !== null) {
            return $this->gateUnits;
        }

        $units = [];

        if ($this->gateAdEnabled('banner', true)) {
            $units[] = $this->takeSpec(self::LEADERBOARD);
        }

        $extra = 0;

        foreach ($this->bannerPool() as $zone) {
            if ($extra >= self::MAX_GATE_BANNERS) {
                break;
            }

            if (isset($this->taken[$zone['key']])) {
                continue;
            }

            $unit = $this->takeZone($zone);

            // Each extra zone is a slot of its size: a sponsored banner of that size not shown
            // yet takes it, the way it takes the leaderboard above.
            if ($unit !== null) {
                $units[] = $this->sponsoredFor([$zone['size']]) ?? $unit;
                $extra++;
            }
        }

        if ($this->gateAdEnabled('native', true)) {
            $units[] = $this->takeNative();
        }

        // The room's stack carries one "Sponsored" caption for all of its units, so a sponsored
        // banner does not add its own here.
        return $this->gateUnits = array_map(
            fn (array $unit): array => ($unit['format'] ?? '') === 'direct' ? array_merge($unit, ['label' => false]) : $unit,
            array_values(array_filter($units)),
        );
    }

    /**
     * Every configured banner zone: the main banner (banner_key, banner_size,
     * banner_host) first, then ADS_ADSTERRA_BANNERS in its order.
     *
     * The list is `key:WIDTHxHEIGHT:host` entries, comma separated, because `.env`
     * holds scalars and Adsterra issues a different host per zone. Without a host an
     * entry uses banner_host. A malformed entry is dropped, not repaired: a wrong
     * key or size renders an empty box that looks like a bug.
     *
     * @return array<int, BannerZone>
     */
    public function bannerPool(): array
    {
        $pool = [];
        $defaultHost = $this->cleanHost((string) config('plugged-host-ads.adsterra.banner_host', ''));

        $main = $this->cleanKey((string) config('plugged-host-ads.adsterra.banner_key', ''));

        if ($main !== null && $defaultHost !== null) {
            [$width, $height] = $this->bannerSize();
            $pool[$main] = ['key' => $main, 'size' => "{$width}x{$height}", 'host' => $defaultHost];
        }

        $raw = (string) config('plugged-host-ads.adsterra.banners', '');

        foreach (preg_split('/[,\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            if (count($pool) >= self::MAX_POOL) {
                break;
            }

            [$key, $size, $host] = array_pad(explode(':', $entry, 3), 3, null);

            $key = $this->cleanKey((string) $key);
            $host = $host === null || $host === '' ? $defaultHost : $this->cleanHost($host);

            if ($key === null || $host === null || isset($pool[$key])
                || !in_array($size, self::BANNER_SIZES, true)) {
                continue;
            }

            $pool[$key] = ['key' => $key, 'size' => $size, 'host' => $host];
        }

        return array_values($pool);
    }

    /**
     * The next zone of this size that is not on the page yet, as a unit.
     *
     * @return AdsterraUnit|null
     */
    public function takeBanner(string $size): ?array
    {
        foreach ($this->bannerPool() as $zone) {
            if ($zone['size'] !== $size || isset($this->taken[$zone['key']])) {
                continue;
            }

            if (($unit = $this->takeZone($zone)) !== null) {
                return $unit;
            }
        }

        return null;
    }

    /**
     * One slot over several banner sizes, widest first.
     *
     * Rendered as one `<template>` per size, and the page's unit script inserts the
     * widest one that fits the slot's width, or none. Hiding the others with CSS is
     * not an option: a hidden iframe still loads, and an impression nobody can see is
     * what a network audits for. A single size still goes through here, so a banner
     * that is wider than a phone is left out instead of cropped.
     *
     * Inline (non-isolated) banners write into the top document as they load and
     * cannot wait in a template, so with banner_isolate off only the widest renders.
     *
     * @param  array<int, string>  $sizes
     * @return AdsterraUnit|null
     */
    public function pick(array $sizes): ?array
    {
        if (!(bool) config('plugged-host-ads.adsterra.banner_isolate', true)) {
            foreach ($sizes as $size) {
                if (($unit = $this->takeBanner(trim($size))) !== null) {
                    return $unit;
                }
            }

            return null;
        }

        $units = array_values(array_filter(array_map(fn (string $size): ?array => $this->takeBanner(trim($size)), $sizes)));

        if ($units === []) {
            return null;
        }

        usort($units, fn (array $a, array $b): int => ($b['width'] ?? 0) <=> ($a['width'] ?? 0));

        return [
            'id' => 'pick-' . implode('-', array_column($units, 'id')),
            'format' => 'pick',
            'width' => null,
            'height' => max(array_column($units, 'height')),
            'isolated' => true,
            'document' => null,
            'markup' => null,
            'options' => null,
            'src' => null,
            'units' => $units,
        ];
    }

    /**
     * The native unit, once per page.
     *
     * @return AdsterraUnit|null
     */
    public function takeNative(): ?array
    {
        if ($this->nativeTaken) {
            return null;
        }

        $unit = $this->nativeUnit();
        $this->nativeTaken = $unit !== null;

        return $unit;
    }

    /**
     * @param  BannerZone  $zone
     * @return AdsterraUnit|null
     */
    protected function takeZone(array $zone): ?array
    {
        $unit = $this->bannerUnit($zone['key'], $zone['size'], $zone['host']);

        if ($unit !== null) {
            $this->taken[$zone['key']] = true;
        }

        return $unit;
    }

    /** The page-level formats, in the order they are written into the document. */
    public const PAGE_FORMATS = ['social_bar', 'popunder'];

    /**
     * Every page-level zone that is configured, keyed by format, regardless of
     * where it is allowed to run.
     *
     * The distinction matters because a zone and a placement are two different
     * decisions. `pageScripts()` below is "what runs on an ordinary panel page",
     * `gateScripts()` is "what runs in the waiting room", and both are drawn from
     * this — so a popunder can be configured for the waiting room only without the
     * rest of the panel ever seeing it, and `isConfigured()` can still tell that
     * something exists to fill a room with.
     *
     * @return array<string, string>
     */
    public function configuredScripts(): array
    {
        $scripts = [];

        foreach (self::PAGE_FORMATS as $format) {
            $url = $this->cleanScriptUrl((string) config("plugged-host-ads.adsterra.{$format}_script", ''));

            if ($url !== null) {
                $scripts[$format] = $url;
            }
        }

        return $scripts;
    }

    /**
     * Page-level formats for an ordinary panel page, keyed by format. These get a
     * real script tag in the top document; both of them exist to draw outside the
     * ad slot.
     *
     * Each one is here only if its `*_panel_wide` switch is on. Off means the zone
     * still exists and the waiting room can still carry it — see `gateScripts()` —
     * it just does not follow the customer around the panel. That is the whole
     * point of the switch: a Popunder that opens a tab on any click anywhere is a
     * different product from one that opens a tab on the page where the user is
     * already being told the ads are paying for their server.
     *
     * @return array<string, string>
     */
    public function pageScripts(): array
    {
        return array_filter(
            $this->configuredScripts(),
            fn (string $url, string $format): bool => $this->panelWide($format),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * The complete set of top-document scripts for the waiting room.
     *
     * Drawn from `configuredScripts()` and not from `pageScripts()`, so a format
     * whose panel-wide switch is off can still run here: "Popunder on the starting
     * page only" is one zone, one gate switch, and the panel-wide switch turned off.
     *
     * Returned as one set, and rendered by the same BODY_END hook that renders
     * `pageScripts()` everywhere else, so the gate can carry more of them without
     * ever carrying one twice — a Social Bar zone loaded twice in a document fights
     * itself for the slot and reports as one impression.
     *
     * @return array<string, string>
     */
    public function gateScripts(): array
    {
        $configured = $this->configuredScripts();
        $scripts = [];

        foreach (self::PAGE_FORMATS as $format) {
            if (isset($configured[$format]) && $this->gateAdEnabled($format, true)) {
                $scripts[$format] = $configured[$format];
            }
        }

        // The waiting room's own zone: an ordinary Social Bar or Vignette zone that
        // exists separately from the panel-wide one, so what the room earns is
        // attributable and the two never compete for the same corner of the screen.
        $ownZone = $this->gateScript();

        if ($ownZone !== null && $this->gateAdEnabled('interstitial', true)) {
            $scripts['interstitial'] = $ownZone;
        }

        return $scripts;
    }

    /** The waiting room's own zone URL, if one is configured. */
    public function gateScript(): ?string
    {
        return $this->cleanScriptUrl((string) config('plugged-host-ads.adsterra.gate_script', ''));
    }

    /**
     * The "click to open our sponsor" destination, if one is configured and on.
     *
     * An Adsterra Direct Link is an ordinary URL that pays per landing, so unlike
     * every other format here it is not a script and cannot be sandboxed. It is
     * rendered as a plain link the user chooses to click, opening in a new tab, and
     * it is off by default: a panel that opens tabs on its own is adware.
     */
    public function directLink(): ?string
    {
        if (!$this->gateAdEnabled('direct_link', false)) {
            return null;
        }

        return $this->cleanLinkUrl((string) config('plugged-host-ads.adsterra.direct_link', ''));
    }

    /** One waiting-room format switch. */
    protected function gateAdEnabled(string $format, bool $default): bool
    {
        return (bool) config("plugged-host-ads.start_credits.gate_ads.{$format}", $default);
    }

    /**
     * May this page-level format run outside the waiting room?
     *
     * Social Bar defaults to yes — it is a docked widget and panel-wide is what it
     * was built for. Popunder defaults to no: it opens a tab on the next click
     * wherever it is loaded, and the one page where that is defensible is the one
     * that says out loud that the ads are paying for the server.
     */
    protected function panelWide(string $format): bool
    {
        return (bool) config(
            "plugged-host-ads.adsterra.{$format}_panel_wide",
            $format === 'social_bar',
        );
    }

    /**
     * Is there a single usable Adsterra surface configured, anywhere?
     *
     * Asked by `AdDisplayService::hasUnit()` before anything renders, and by
     * `StartGateService::isEnabled()` before anyone is made to wait — which is why
     * it counts the waiting room's own surfaces too. A popunder set to the starting
     * page only, a lone extra banner zone, the room's own zone or a Direct Link are
     * each a complete configuration on their own, and none of them would show up in
     * `inContentUnits()` or `pageScripts()`. Missing them here would mean a gate
     * that refuses to open over a room that would have filled.
     */
    public function isConfigured(): bool
    {
        return $this->configuredScripts() !== []
            || $this->bannerPool() !== []
            || $this->nativeUnit() !== null
            || $this->gateScript() !== null
            || $this->directLink() !== null;
    }

    /**
     * Hosts the ad code will be fetched from, for the ad-block network probe.
     *
     * Every configured zone, not just the ones the current page renders. The probe
     * answers "can this browser reach Adsterra at all", so a zone reserved for the
     * waiting room belongs in the list: it is the one the room is about to need.
     *
     * @return array<int, string>
     */
    public function probeUrls(): array
    {
        $urls = [];

        // One zone per banner host: the question is whether the host answers.
        $hosts = [];

        foreach ($this->bannerPool() as $zone) {
            if (!isset($hosts[$zone['host']])) {
                $hosts[$zone['host']] = true;
                $urls[] = "https://{$zone['host']}/{$zone['key']}/invoke.js";
            }
        }

        foreach ($this->configuredScripts() as $url) {
            $urls[] = 'https:' . $url;
        }

        foreach ([$this->gateScript(), $this->cleanScriptUrl((string) config('plugged-host-ads.adsterra.native_script', ''))] as $url) {
            if ($url !== null) {
                $urls[] = 'https:' . $url;
            }
        }

        return array_values(array_unique($urls));
    }

    public function frameSandbox(): string
    {
        $default = 'allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-forms';
        $configured = trim((string) config('plugged-host-ads.adsterra.frame_sandbox', ''));

        if ($configured === '') {
            return $default;
        }

        $tokens = preg_split('/\s+/', $configured, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $allowed = array_filter($tokens, fn (string $token): bool => (bool) preg_match('/^allow-[a-z-]{1,48}$/', $token));

        // Adsterra requires a verified domain via Origin/Referer to attribute impressions
        // and fill ads; without allow-same-origin the iframe has an opaque origin, resulting
        // in empty/white ad units. allow-forms ensures interactive ads and clickouts function.
        if (!in_array('allow-same-origin', $allowed, true)) {
            $allowed[] = 'allow-same-origin';
        }

        if (!in_array('allow-forms', $allowed, true)) {
            $allowed[] = 'allow-forms';
        }

        return implode(' ', array_unique($allowed)) ?: $default;
    }

    /**
     * @return AdsterraUnit|null
     */
    protected function bannerUnit(?string $key = null, ?string $size = null, ?string $host = null): ?array
    {
        $key = $this->cleanKey($key ?? (string) config('plugged-host-ads.adsterra.banner_key', ''));
        $host = $this->cleanHost($host ?? (string) config('plugged-host-ads.adsterra.banner_host', ''));

        if ($key === null || $host === null) {
            return null;
        }

        [$width, $height] = $this->bannerSize($size);

        try {
            // JSON_HEX_* matters: this lands inside a <script> in a document that
            // is itself an HTML attribute, so "</script>" must not survive as text.
            $options = json_encode([
                'key' => $key,
                'format' => 'iframe',
                'height' => $height,
                'width' => $width,
                'params' => new stdClass(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        } catch (JsonException) {
            return null;
        }

        $src = '//' . $host . '/' . $key . '/invoke.js';
        $isolatedSrc = 'https://' . $host . '/' . $key . '/invoke.js';

        // Isolation wraps the banner inside a sandboxed iframe. With allow-same-origin
        // present, the ad script receives the panel's origin and referer so Adsterra
        // attributes the domain and fills the ad. Top navigation and modal prompts
        // remain blocked by the sandbox, preventing random redirects and phishing dialogs.
        $isolated = (bool) config('plugged-host-ads.adsterra.banner_isolate', true);

        return [
            'id' => 'banner-' . substr($key, 0, 12) . "-{$width}x{$height}",
            'format' => 'banner',
            'width' => $width,
            'height' => $height,
            'isolated' => $isolated,
            'document' => $isolated
                ? $this->document("<script>window.atOptions = {$options};</script><script src=\"{$isolatedSrc}\"></script>")
                : null,
            'markup' => null,
            'options' => $options,
            'src' => $src,
        ];
    }

    /**
     * @return AdsterraUnit|null
     */
    protected function nativeUnit(): ?array
    {
        $src = $this->cleanScriptUrl((string) config('plugged-host-ads.adsterra.native_script', ''));
        $container = $this->nativeContainerId($src);

        if ($src === null || $container === null) {
            return null;
        }

        $scriptSrc = str_starts_with($src, '//') ? 'https:' . $src : $src;

        // Async with no document.write, so this one is safe in the top document —
        // where it can also size itself, which a fixed-height frame cannot.
        $body = '<script async="async" data-cfasync="false" src="' . $scriptSrc . '"></script>'
            . '<div id="' . $container . '"></div>';

        $isolated = (bool) config('plugged-host-ads.adsterra.isolate', true);

        return [
            'id' => 'native-' . $container,
            'format' => 'native',
            'width' => null,
            'height' => max(80, (int) config('plugged-host-ads.adsterra.native_height', 320)),
            'isolated' => $isolated,
            'document' => $isolated ? $this->document($body) : null,
            'markup' => $isolated ? null : $body,
            'options' => null,
            'src' => $src,
        ];
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function bannerSize(?string $size = null): array
    {
        $size ??= (string) config('plugged-host-ads.adsterra.banner_size', '728x90');

        if (!in_array($size, self::BANNER_SIZES, true)) {
            $size = '728x90';
        }

        [$width, $height] = explode('x', $size);

        return [(int) $width, (int) $height];
    }

    /**
     * `//pl12345678.example.com/<hash>/invoke.js` writes into `container-<hash>`.
     */
    protected function nativeContainerId(?string $src): ?string
    {
        $explicit = trim((string) config('plugged-host-ads.adsterra.native_container', ''));

        if ($explicit !== '') {
            return preg_match('/^[A-Za-z0-9_-]{1,80}$/', $explicit) ? $explicit : null;
        }

        if ($src === null) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', parse_url($src, PHP_URL_PATH) ?: '')));
        $hash = count($segments) >= 2 ? $segments[count($segments) - 2] : null;

        return $hash !== null && preg_match('/^[A-Za-z0-9]{6,64}$/', $hash) ? "container-{$hash}" : null;
    }

    /**
     * A complete standalone document for the sandboxed frame. Inline scripts here
     * are parser-inserted, which is the whole point: document.write still works.
     */
    protected function document(string $body): string
    {
        $base = url('/');

        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<base href="' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '/">'
            . '<meta name="referrer" content="no-referrer-when-downgrade">'
            . '<style>html,body{margin:0;padding:0;overflow:hidden;background:transparent}'
            . 'body>*{margin:0 auto}</style></head><body>' . $body . '</body></html>';
    }

    protected function cleanKey(string $key): ?string
    {
        $key = trim($key);

        return preg_match('/^[A-Za-z0-9]{8,64}$/', $key) ? $key : null;
    }

    protected function cleanHost(string $host): ?string
    {
        return self::normalizeHost($host);
    }

    /**
     * A bare hostname, or null if it cannot be one.
     *
     * Public and static because the settings form normalises with it on save: the
     * dashboard hands you a whole `invoke.js` URL, and pasting that into a field
     * labelled "host" is the obvious mistake to make — one that otherwise shows up
     * only as a unit that silently never fills. So the scheme and everything from
     * the first `/`, `?` or `#` onwards are dropped rather than rejected.
     */
    public static function normalizeHost(string $host): ?string
    {
        $host = trim(strtolower($host));
        $host = (string) preg_replace('#^(https?:)?//#', '', $host);
        $host = rtrim((string) (preg_split('~[/?#]~', $host, 2)[0] ?? ''), '.');

        return preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,24}$/', $host) ? $host : null;
    }

    /**
     * Normalises to a protocol-relative URL: the panel is HTTPS-only, and the
     * snippets Adsterra issues are protocol-relative to begin with.
     */
    protected function cleanScriptUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        if (!preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $host = $this->cleanHost((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($host === null || !preg_match('#^(/[A-Za-z0-9._-]+)+$#', $path)) {
            return null;
        }

        // A traversal segment would be rewritten by the browser, and a query or
        // fragment would be dropped by the rebuild below — either way the URL
        // requested stops matching the one pasted in, so refuse both rather than
        // serve a unit that looks configured and never fills.
        if (in_array('..', explode('/', $path), true)
            || parse_url($url, PHP_URL_QUERY) !== null
            || parse_url($url, PHP_URL_FRAGMENT) !== null) {
            return null;
        }

        return '//' . $host . $path;
    }

    /**
     * A destination a user may be sent to by clicking, or null.
     *
     * Looser than `cleanScriptUrl()` on purpose — a Direct Link is a landing URL and
     * often carries a query string, which a script URL must not. Still strictly
     * https and still a real hostname, because this one ends up in an `href` the
     * customer clicks: no `javascript:`, no `data:`, no protocol-relative guessing.
     */
    protected function cleanLinkUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        if (!preg_match('#^https://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        if ($this->cleanHost((string) parse_url($url, PHP_URL_HOST)) === null) {
            return null;
        }

        return $url;
    }
}
