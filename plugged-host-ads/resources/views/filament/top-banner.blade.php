{{-- The top banner: a sponsored banner placed "top", rendered at CONTENT_START by
     AdsPluginServiceProvider::registerTopBanner(), so it sits inside <main> under the topbar and
     above the page's breadcrumbs and heading, on every app and server page. Only rendered when
     one is live, so there is never an empty box.

     Full width of the content column up to the image's own pixel width (never upscaled), height
     from its aspect ratio (the width/height attributes reserve it before the image loads), so it
     scales down on a phone. Centred when the image is narrower than the column. The page
     header's own top padding is the gap below it; 1.5rem above matches that with the main
     column's 0.5rem.

     Outside every Livewire component (the layout renders it), so a morph never touches it and it
     needs no wire:ignore. Not an iframe, so the unit script neither folds nor refreshes it.
     Linked through the click counter (/ads/b/{id}), which only ever redirects to the banner's
     stored link. --}}
<div data-ph-unit="direct"
     data-ph-direct="{{ (int) $unit['banner'] }}"
     data-ph-top-banner
     style="width:100%; max-width:{{ (int) $unit['width'] }}px; margin:1.5rem auto 0 auto;">
    <span style="display:block; margin-bottom:0.5rem; text-align:start; font-size:0.6875rem; font-weight:600; line-height:1; letter-spacing:0.05em; text-transform:uppercase; color:var(--phv2-text-faint);">{{ trans('plugged-host-ads::strings.banners.sponsored') }}</span>
    <a href="{{ $unit['href'] }}" target="_blank" rel="sponsored noopener" style="display:block;">
        <img src="{{ $unit['image'] }}"
             alt="{{ $unit['alt'] }}"
             width="{{ (int) $unit['width'] }}"
             height="{{ (int) $unit['height'] }}"
             decoding="async"
             style="display:block; width:100%; height:auto; border-radius:0.5rem;">
    </a>
</div>
