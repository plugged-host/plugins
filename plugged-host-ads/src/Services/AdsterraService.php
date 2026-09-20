<?php

namespace PluggedHost\Ads\Services;

use JsonException;
use stdClass;

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
 * @phpstan-type AdsterraUnit array{id: string, format: string, width: int|null, height: int, isolated: bool, document: string|null, markup: string|null, options: string|null, src: string|null}
 */
class AdsterraService
{
    /** Sizes Adsterra fills for display banners. */
    public const BANNER_SIZES = ['728x90', '468x60', '320x50', '300x250', '160x600', '160x300'];

    /**
     * How many extra banner zones the waiting room will render.
     *
     * A cap, not a target. Each one is a separate network request that has to fill
     * before the page settles, and past a handful the marginal impression is worth
     * less than the layout it costs.
     */
    public const MAX_GATE_BANNERS = 6;

    /**
     * Units that belong in the in-content ad slot, in render order.
     *
     * @return array<int, AdsterraUnit>
     */
    public function inContentUnits(): array
    {
        return array_values(array_filter([
            $this->bannerUnit(),
            $this->nativeUnit(),
        ]));
    }

    /**
     * Everything the waiting room renders inside the page, in order.
     *
     * The gate is the one surface where more units is the point — the user is
     * waiting on a timer either way, so the page may as well be worth something —
     * but each format is still a separate switch. Turning them all off leaves the
     * countdown, which is a legitimate configuration: `isEnabled()` on the gate
     * already refuses to run with no Adsterra at all.
     *
     * @return array<int, AdsterraUnit>
     */
    public function gateUnits(): array
    {
        $units = [];

        if ($this->gateAdEnabled('banner', true)) {
            $units[] = $this->bannerUnit();
        }

        foreach ($this->extraBannerUnits() as $unit) {
            $units[] = $unit;
        }

        if ($this->gateAdEnabled('native', true)) {
            $units[] = $this->nativeUnit();
        }

        return array_values(array_filter($units));
    }

    /**
     * The additional banner zones configured for the waiting room.
     *
     * Configured as `key:size` pairs in one field rather than as N pairs of fields,
     * because the number of zones a publisher has is not something we can guess and
     * `.env` holds scalars: `ADS_START_GATE_BANNERS="abc123:300x250,def456:160x600"`.
     * A malformed entry is dropped, not repaired — a wrong key renders an empty box
     * that looks like a bug, so it is better to render nothing.
     *
     * @return array<int, AdsterraUnit>
     */
    public function extraBannerUnits(): array
    {
        $raw = trim((string) config('plugged-host-ads.start_credits.gate_ads.banners', ''));

        if ($raw === '') {
            return [];
        }

        $units = [];
        $seen = [];

        foreach (preg_split('/[,\n]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            if (count($units) >= self::MAX_GATE_BANNERS) {
                break;
            }

            $parts = explode(':', trim($entry), 2);
            $key = $this->cleanKey($parts[0] ?? '');
            $size = trim($parts[1] ?? '');

            if ($key === null || isset($seen[$key . $size])) {
                continue;
            }

            $unit = $this->bannerUnit($key, $size !== '' ? $size : null);

            if ($unit === null) {
                continue;
            }

            $seen[$key . $size] = true;
            $units[] = $unit;
        }

        return $units;
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
            || $this->inContentUnits() !== []
            || $this->extraBannerUnits() !== []
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

        $key = $this->cleanKey((string) config('plugged-host-ads.adsterra.banner_key', ''));
        $host = $this->cleanHost((string) config('plugged-host-ads.adsterra.banner_host', ''));

        if ($key !== null && $host !== null) {
            $urls[] = "https://{$host}/{$key}/invoke.js";
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
    protected function bannerUnit(?string $key = null, ?string $size = null): ?array
    {
        $key = $this->cleanKey($key ?? (string) config('plugged-host-ads.adsterra.banner_key', ''));
        $host = $this->cleanHost((string) config('plugged-host-ads.adsterra.banner_host', ''));

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
