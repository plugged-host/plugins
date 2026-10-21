<?php

namespace PluggedHost\Ads\Services;

use App\Models\User;
use PluggedHost\Core\Support\Plugins;
use Throwable;

/**
 * Monetag's two page-level formats: In-Page Push (notification-style cards inside the page, no
 * permission prompt) and Vignette (a full-screen ad on some page changes). Monetag's other
 * formats are not here on purpose: Push Notifications and MultiTag need its service worker at the
 * site root, which would sit between every user and the whole panel; Onclick is a popunder.
 *
 * A tag is Monetag's own loader snippet, a zone id plus a script URL, rebuilt here from validated
 * parts so a pasted value can never become markup. Where they load is decided by the caller
 * (AdsPluginServiceProvider: ordinary panel pages, not the console or the waiting rooms); this
 * class adds the per-format rules: Vignette at most once per `vignette_minutes` per session, and
 * not for Plus unless `vignette_plus` is on.
 */
class MonetagService
{
    public const FORMATS = ['in_page_push', 'vignette'];

    private const MEMBERSHIP = 'PluggedHost\\Economy\\Services\\MembershipService';

    private const VIGNETTE_SESSION_KEY = 'plugged-host-ads.monetag.vignette_at';

    /**
     * The configured tag of a format, or null.
     *
     * @return array{zone: string, src: string}|null
     */
    public function tag(string $format): ?array
    {
        $zone = (string) config("plugged-host-ads.monetag.{$format}_zone", '');
        $src = self::cleanSrc((string) config("plugged-host-ads.monetag.{$format}_src", ''));

        return preg_match('/^\d{1,12}$/', $zone) === 1 && $src !== null ? ['zone' => $zone, 'src' => $src] : null;
    }

    /**
     * The tags to load on this page for this user, keyed by format. Reserves the Vignette's slot
     * in the session when it is handed out, so call it once per page.
     *
     * @return array<string, array{zone: string, src: string}>
     */
    public function pageTags(?User $user): array
    {
        $tags = [];

        if (($tag = $this->tag('in_page_push')) !== null) {
            $tags['in_page_push'] = $tag;
        }

        if (($tag = $this->tag('vignette')) !== null && $this->vignetteDue($user)) {
            $tags['vignette'] = $tag;
        }

        return $tags;
    }

    protected function vignetteDue(?User $user): bool
    {
        if (!(bool) config('plugged-host-ads.monetag.vignette_plus', false) && $this->hasPlus($user)) {
            return false;
        }

        $minutes = max(0, (int) config('plugged-host-ads.monetag.vignette_minutes', 10));

        try {
            $session = request()->session();
            $last = (int) $session->get(self::VIGNETTE_SESSION_KEY, 0);

            if ($minutes > 0 && $last > 0 && time() - $last < $minutes * 60) {
                return false;
            }

            $session->put(self::VIGNETTE_SESSION_KEY, time());

            return true;
        } catch (Throwable) {
            // No session to remember it in: no Vignette rather than one on every page.
            return false;
        }
    }

    protected function hasPlus(?User $user): bool
    {
        try {
            return $user !== null && Plugins::available(self::MEMBERSHIP) && app(self::MEMBERSHIP)->hasPlus($user);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A pasted Monetag snippet (or "zone url") as its zone and script URL, or null when it is not
     * one. Monetag's loader sets `s.dataset.zone='…'` and `s.src='…'`; a plain script tag carries
     * `data-zone` and `src`.
     *
     * @return array{zone: string, src: string}|null
     */
    public static function parse(string $raw): ?array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        preg_match('/(?:dataset\.zone\s*=\s*|data-zone\s*=\s*)["\']?(\d{1,12})/i', $raw, $zone)
            || preg_match('/^(\d{1,12})\s/', $raw, $zone);
        preg_match('#((?:https?:)?//[A-Za-z0-9.-]+(?:/[A-Za-z0-9._-]+)+)#', $raw, $src);

        $url = self::cleanSrc($src[1] ?? '');

        return isset($zone[1]) && $url !== null ? ['zone' => $zone[1], 'src' => $url] : null;
    }

    /** The loader snippet for a tag, as Monetag issues it (for the settings form). */
    public static function snippet(?array $tag): string
    {
        return $tag === null ? '' : "<script>(function(s){s.dataset.zone='{$tag['zone']}',s.src='{$tag['src']}'})"
            . "([document.documentElement, document.body].filter(Boolean).pop().appendChild(document.createElement('script')))</script>";
    }

    /** An https script URL of host + path only, or null. */
    public static function cleanSrc(string $url): ?string
    {
        $url = trim($url);

        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        return preg_match('#^https://[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)+(?:/[A-Za-z0-9_-][A-Za-z0-9._-]*)+$#', $url) === 1
            ? $url
            : null;
    }
}
