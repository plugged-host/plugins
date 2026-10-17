<?php

namespace PluggedHost\Ads\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PluggedHost\Ads\Models\AdBanner;
use Throwable;

/**
 * Sponsored banners (AdBanner) as renderable units.
 *
 * Every page asks, so the banners that can still show (active, not ended) are read once a minute
 * into the cache and the rest is decided here per request: whether one is live now (its start
 * and end are compared on every read, so a window opens and closes to the second), which one
 * renders, and that a banner is on a page at most once. Saving or deleting a banner empties the
 * cache, so an edit shows on the next page load.
 *
 * Two ways in:
 * - `take()`: AdsterraService asks for each banner unit it hands out (page foot, named slots,
 *   server-tools strip, waiting rooms). A live slot banner of one of that unit's sizes that is
 *   not on the page yet takes its place; with several, a random one, so they take turns.
 * - `top()`: the top banner render hook, one random live top banner per page load.
 *
 * Who sees either is not decided here: that is AdDisplayService, as for every other ad.
 *
 * @phpstan-type Banner array{id: int, placement: string, src: string, width: int, height: int, family: ?string, advertiser: string, starts: ?int, ends: ?int}
 */
class AdBannerService
{
    public const CACHE_KEY = 'plugged-host-ads:banners:v1';

    public const CACHE_SECONDS = 60;

    /** @var array<int, Banner>|null */
    protected ?array $banners = null;

    /** @var array<int, true> Banner ids already on this page. */
    protected array $shown = [];

    /**
     * The Adsterra size an image of this many pixels fills, or null: exactly one of
     * AdsterraService::BANNER_SIZES, or twice it in both directions (1456x180 is a sharp 728x90).
     */
    public static function family(int $width, int $height): ?string
    {
        foreach (AdsterraService::BANNER_SIZES as $size) {
            [$w, $h] = array_map('intval', explode('x', $size));

            if (($width === $w && $height === $h) || ($width === 2 * $w && $height === 2 * $h)) {
                return $size;
            }
        }

        return null;
    }

    /**
     * Live banners of a placement, right now.
     *
     * @return array<int, Banner>
     */
    public function live(string $placement): array
    {
        $now = time();

        return array_values(array_filter(
            $this->banners(),
            fn (array $banner): bool => $banner['placement'] === $placement
                && ($banner['starts'] === null || $banner['starts'] <= $now)
                && ($banner['ends'] === null || $banner['ends'] > $now)
                && ($placement !== AdBanner::PLACEMENT_SLOTS || $banner['family'] !== null),
        ));
    }

    public function hasLive(string $placement): bool
    {
        return $this->live($placement) !== [];
    }

    /**
     * A live slot banner of one of these sizes (`728x90`, …) that is not on this page yet, as a
     * unit, or null. A random one of those that fit, so several banners of a size take turns.
     *
     * @param  array<int, string>  $sizes
     * @return array<string, mixed>|null
     */
    public function take(array $sizes): ?array
    {
        $sizes = array_map('trim', $sizes);

        $fits = array_values(array_filter(
            $this->live(AdBanner::PLACEMENT_SLOTS),
            fn (array $banner): bool => in_array($banner['family'], $sizes, true) && !isset($this->shown[$banner['id']]),
        ));

        if ($fits === []) {
            return null;
        }

        $banner = $fits[random_int(0, count($fits) - 1)];
        $this->shown[$banner['id']] = true;

        // Laid out at its size, not its pixels: a 2x image renders at half its pixel size.
        [$width, $height] = array_map('intval', explode('x', (string) $banner['family']));

        return $this->unit($banner, $width, $height);
    }

    /**
     * One live top banner, at random, or null when none is live.
     *
     * @return array<string, mixed>|null
     */
    public function top(): ?array
    {
        $live = array_values(array_filter(
            $this->live(AdBanner::PLACEMENT_TOP),
            fn (array $banner): bool => !isset($this->shown[$banner['id']]),
        ));

        if ($live === []) {
            return null;
        }

        $banner = $live[random_int(0, count($live) - 1)];
        $this->shown[$banner['id']] = true;

        return $this->unit($banner, $banner['width'], $banner['height']);
    }

    /** After a save or delete: the next read goes to the database. */
    public function forget(): void
    {
        $this->banners = null;

        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // Unreachable cache: the entry runs out on its own within CACHE_SECONDS.
        }
    }

    /**
     * Banners that can still show: switched on and not ended, whether or not they have started.
     *
     * @return array<int, Banner>
     */
    public function banners(): array
    {
        if ($this->banners !== null) {
            return $this->banners;
        }

        try {
            $rows = Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => $this->load());
        } catch (Throwable) {
            $rows = [];
        }

        return $this->banners = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * @return array<int, Banner>
     */
    protected function load(): array
    {
        try {
            $banners = AdBanner::query()
                ->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
                ->orderBy('id')
                ->get();
        } catch (Throwable) {
            // No table yet (deployed before the migration ran): nothing to show, cached like an
            // empty list rather than asked again on every page.
            return [];
        }

        $disk = Storage::disk(AdBanner::DISK);
        $rows = [];

        foreach ($banners as $banner) {
            $width = (int) $banner->width;
            $height = (int) $banner->height;

            if ($width < 1 || $height < 1 || blank($banner->image_path)
                || !in_array($banner->placement, AdBanner::PLACEMENTS, true)) {
                continue;
            }

            $rows[] = [
                'id' => (int) $banner->getKey(),
                'placement' => (string) $banner->placement,
                'src' => $disk->url($banner->image_path),
                'width' => $width,
                'height' => $height,
                'family' => self::family($width, $height),
                'advertiser' => (string) $banner->advertiser,
                'starts' => $banner->starts_at?->getTimestamp(),
                'ends' => $banner->ends_at?->getTimestamp(),
            ];
        }

        return $rows;
    }

    /**
     * Shaped like an AdsterraService unit, so every view that renders units can render it
     * (format `direct`, see filament/direct-banner.blade.php). Not isolated: it is our own image
     * and our own link, with no third-party code to keep away from the panel.
     *
     * @param  Banner  $banner
     * @return array<string, mixed>
     */
    protected function unit(array $banner, int $width, int $height): array
    {
        return [
            'id' => 'direct-' . $banner['id'],
            'format' => 'direct',
            'width' => $width,
            'height' => $height,
            'isolated' => false,
            'document' => null,
            'markup' => null,
            'options' => null,
            'src' => null,
            'banner' => $banner['id'],
            'image' => $banner['src'],
            'href' => route('plugged-host-ads.banner', ['banner' => $banner['id']]),
            'alt' => trans('plugged-host-ads::strings.banners.alt', ['advertiser' => $banner['advertiser']]),
            // Off in the waiting rooms, whose stack has one "Sponsored" caption for every unit.
            'label' => true,
        ];
    }
}
