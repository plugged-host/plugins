<?php

namespace PluggedHost\FiveM\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * What Cfx offers: the Linux FXServer builds, which of them are recommended and latest, until
 * when each is supported, and the one FiveM for GTAV Enhanced build.
 *
 * Two sources, the same two fivem-runtime reads at every start:
 *
 *  - the changelog API (changelogs-live.fivem.net), a stable JSON answer to "which build is
 *    recommended / latest", plus support_policy: build => date it stops being supported;
 *  - the download page on docs.fivem.net, a Next.js page whose __NEXT_DATA__ lists every Linux
 *    build Cfx still serves, each with its download URL, and the Enhanced build. Since late 2026
 *    this is the only list there is: the old runtime.fivem.net directory index redirects to it.
 *
 * Both are cached for cache_ttl, and the last good answer is kept for a day on top, so Cfx having
 * a bad minute shows yesterday's list instead of an empty page.
 */
class CfxArtifacts
{
    private const CACHE = 'plugged-host-fivem:cfx:';

    /**
     * Every legacy Linux build Cfx offers, newest first.
     *
     * @return array<int, array{build: int, url: string}>
     */
    public function builds(): array
    {
        $builds = [];

        foreach ((array) data_get($this->downloads(), 'legacy', []) as $channel) {
            foreach ((array) data_get($channel, 'linux', []) as $entry) {
                $build = self::buildNumber((string) data_get($entry, 'subtitle', ''));
                $url = (string) data_get($entry, 'downloadURL', '');

                if ($build !== null && self::fromCfx($url)) {
                    $builds[$build] = ['build' => $build, 'url' => $url];
                }
            }
        }

        krsort($builds);

        return array_values($builds);
    }

    public function recommended(): ?int
    {
        $build = data_get($this->changelog(), 'recommended');

        return is_numeric($build) ? (int) $build : null;
    }

    public function latest(): ?int
    {
        $build = data_get($this->changelog(), 'latest');

        return is_numeric($build) ? (int) $build : null;
    }

    /**
     * The build a channel or a version string stands for right now.
     */
    public function resolve(string $version): ?int
    {
        return match ($version) {
            'recommended' => $this->recommended(),
            'latest' => $this->latest(),
            default => ctype_digit($version) ? (int) $version : null,
        };
    }

    /**
     * When Cfx stops supporting a build, or null when it does not say.
     */
    public function supportEnds(int $build): ?CarbonImmutable
    {
        $date = data_get($this->changelog(), "support_policy.$build");

        if (!is_string($date) || str_starts_with($date, '9999')) {
            return null;
        }

        try {
            return CarbonImmutable::parse($date);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The FiveM for GTAV Enhanced build Cfx serves (it only ever serves one).
     *
     * @return array{build: int, url: string}|null
     */
    public function enhanced(): ?array
    {
        $entry = data_get($this->downloads(), 'enhanced.linux.0');
        $build = self::buildNumber((string) data_get($entry, 'subtitle', ''));
        $url = (string) data_get($entry, 'downloadURL', '');

        return $build !== null && self::fromCfx($url) ? ['build' => $build, 'url' => $url] : null;
    }

    /**
     * Whether a URL is on one of Cfx's download hosts. fivem-runtime makes the same check before
     * it downloads anything.
     */
    public static function fromCfx(string $url): bool
    {
        return str_starts_with($url, 'https://runtime.fivem.net/')
            || str_starts_with($url, 'https://downloads.cfx-services.net/');
    }

    private static function buildNumber(string $subtitle): ?int
    {
        return preg_match('/(\d+)\s*$/', $subtitle, $match) === 1 ? (int) $match[1] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function changelog(): array
    {
        return $this->remember('changelog', function (): ?array {
            $json = Http::timeout(15)->connectTimeout(5)
                ->get((string) config('plugged-host-fivem.changelog_api'))
                ->throw()
                ->json();

            return is_array($json) && isset($json['recommended']) ? $json : null;
        });
    }

    /**
     * The download page's pageProps: ['legacy' => [channel => ['linux' => [...]]], 'enhanced' => [...]].
     *
     * @return array<string, mixed>
     */
    private function downloads(): array
    {
        return $this->remember('downloads', function (): ?array {
            $html = Http::timeout(15)->connectTimeout(5)
                ->get((string) config('plugged-host-fivem.downloads_page'))
                ->throw()
                ->body();

            if (preg_match('#<script id="__NEXT_DATA__" type="application/json">(.*?)</script>#s', $html, $match) !== 1) {
                return null;
            }

            $props = data_get(json_decode($match[1], true), 'props.pageProps');

            return is_array($props) && isset($props['legacy']) ? ['legacy' => $props['legacy'], 'enhanced' => $props['enhanced'] ?? []] : null;
        });
    }

    /**
     * @param  callable(): ?array<string, mixed>  $fetch
     * @return array<string, mixed>
     */
    private function remember(string $key, callable $fetch): array
    {
        $fresh = Cache::get(self::CACHE . $key);

        if (is_array($fresh)) {
            return $fresh;
        }

        try {
            $value = $fetch();
        } catch (Throwable) {
            $value = null;
        }

        if ($value === null) {
            $stale = Cache::get(self::CACHE . $key . ':last');

            return is_array($stale) ? $stale : [];
        }

        Cache::put(self::CACHE . $key, $value, (int) config('plugged-host-fivem.cache_ttl', 600));
        Cache::put(self::CACHE . $key . ':last', $value, now()->addDay());

        return $value;
    }
}
