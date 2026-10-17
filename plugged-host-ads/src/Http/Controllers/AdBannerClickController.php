<?php

namespace PluggedHost\Ads\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use PluggedHost\Ads\Models\AdBanner;
use Throwable;

/**
 * GET /ads/b/{banner}: a click on a sponsored banner.
 *
 * Counts it with one atomic UPDATE (clicks = clicks + 1, nothing else touched: no model events,
 * no updated_at) and answers 302 to the banner's stored https link. The id is the only input;
 * there is no URL in the request to rewrite, so this cannot be used as an open redirect. An
 * unknown id, or a stored link that is no longer an https URL, is a 404.
 *
 * A browser prefetch is redirected but not counted: nobody clicked.
 */
class AdBannerClickController
{
    public function __invoke(Request $request, string $banner): RedirectResponse
    {
        $record = ctype_digit($banner) ? AdBanner::query()->find((int) $banner) : null;
        $url = $record?->safeUrl();

        abort_if($record === null || $url === null, 404);

        if (!$this->isPrefetch($request)) {
            try {
                AdBanner::query()->whereKey($record->getKey())->toBase()->increment('clicks');
            } catch (Throwable) {
                // A lost count is cheaper than a click that goes nowhere.
            }
        }

        return redirect()->away($url, 302)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    private function isPrefetch(Request $request): bool
    {
        $purpose = strtolower((string) ($request->headers->get('Sec-Purpose') ?? $request->headers->get('Purpose') ?? ''));

        return str_contains($purpose, 'prefetch') || str_contains($purpose, 'prerender');
    }
}
