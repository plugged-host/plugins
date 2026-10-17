{{-- A sponsored banner in an ad slot (format `direct`, PluggedHost\Ads\Services\AdBannerService):
     the image the owner uploaded, linked through the click counter (/ads/b/{id}), which only
     ever redirects to the banner's stored link.

     No iframe and no third-party code, so the unit script leaves it alone: the no-fill fold
     only looks inside iframe[srcdoc] units and the in-view refresh only reloads those. It
     carries data-ph-unit like every unit, so the slot it sits in counts as showing and is never
     folded, and the ad-block detector counts it like any other unit.

     Laid out at its slot size (a 2x image at half its pixels, so it stays sharp), never wider
     than that and never wider than the slot: on a phone it scales down instead of being cut or
     left out. The width/height attributes reserve its height before the image loads.

     The caption is Theme v2's tiny uppercase caption, as on the waiting rooms' "Sponsored"; off
     inside a waiting room, whose stack already carries one for all of its units. --}}
<div data-ph-unit="direct"
     data-ph-direct="{{ (int) $unit['banner'] }}"
     style="margin:0.75rem auto 0 auto; width:100%; max-width:{{ (int) $unit['width'] }}px;">
    @if ($unit['label'] ?? true)
        <span style="display:block; margin-bottom:0.5rem; text-align:start; font-size:0.6875rem; font-weight:600; line-height:1; letter-spacing:0.05em; text-transform:uppercase; color:var(--phv2-text-faint);">{{ trans('plugged-host-ads::strings.banners.sponsored') }}</span>
    @endif
    <a href="{{ $unit['href'] }}" target="_blank" rel="sponsored noopener" style="display:block;">
        <img src="{{ $unit['image'] }}"
             alt="{{ $unit['alt'] }}"
             width="{{ (int) $unit['width'] }}"
             height="{{ (int) $unit['height'] }}"
             loading="lazy"
             decoding="async"
             style="display:block; width:100%; height:auto; border-radius:0.5rem;">
    </a>
</div>
