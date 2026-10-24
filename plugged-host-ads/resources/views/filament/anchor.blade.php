{{-- The anchor: one banner in a bar fixed to the bottom of the screen
     (AdsPluginServiceProvider::registerAnchor()). Full width on phones; from the desktop layout
     on, right-aligned and only as wide as the banner, so it never covers the sidebar.

     data-ph-slot: the unit script folds it like any slot when Adsterra sends nothing (a VPN),
     and the page then gets its bottom space back. The close button hides it for ten minutes in
     this browser; the inline script below removes it before the unit script picks a size, so a
     closed anchor loads nothing. Not data-navigate-once: wire:navigate swaps the body, and the
     new page's anchor needs the same check. --}}
<div class="ph-ad-anchor" data-ph-anchor data-ph-slot wire:ignore>
    <button type="button" class="ph-ad-anchor-close" data-ph-anchor-close
            aria-label="{{ trans('plugged-host-ads::strings.anchor_close') }}"
            title="{{ trans('plugged-host-ads::strings.anchor_close') }}">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
    </button>

    @foreach ($units as $unit)
        @include('plugged-host-ads::filament.adsterra-unit', ['unit' => $unit, 'sandbox' => $sandbox])
    @endforeach
</div>
<style>
    .ph-ad-anchor {
        position: fixed;
        inset-inline: 0;
        bottom: 0;
        z-index: 15;
        padding: 0.25rem 0.5rem;
        background: var(--phv2-bg-bottom, #0b0d10);
        border-top: 1px solid var(--phv2-hairline, rgb(255 255 255 / 0.05));
        text-align: center;
    }

    .ph-ad-anchor iframe[data-ph-unit],
    .ph-ad-anchor [data-ph-unit] {
        margin: 0 auto !important;
    }

    .ph-ad-anchor-close {
        position: absolute;
        bottom: 100%;
        inset-inline-end: 0.5rem;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 1.75rem;
        height: 1.5rem;
        color: var(--phv2-text-muted, #9ca3af);
        background: var(--phv2-bg-bottom, #0b0d10);
        border: 1px solid var(--phv2-hairline, rgb(255 255 255 / 0.05));
        border-bottom: 0;
        border-radius: 0.5rem 0.5rem 0 0;
        cursor: pointer;
    }

    .ph-ad-anchor-close:hover {
        color: var(--phv2-text, #fff);
    }

    .ph-ad-anchor[data-ph-folded] .ph-ad-anchor-close {
        display: none;
    }

    @media (min-width: 64rem) {
        .ph-ad-anchor {
            inset-inline-start: auto;
            width: calc(468px + 1rem);
            border-inline-start: 1px solid var(--phv2-hairline, rgb(255 255 255 / 0.05));
            border-start-start-radius: 0.5rem;
        }
    }

    /* The page's last lines stay reachable above the bar. */
    body:has(.ph-ad-anchor:not([data-ph-folded])) .fi-main {
        padding-bottom: 5rem;
    }
</style>
<script>
(() => {
    const KEY = 'plugged-host-ads.anchor-closed';
    const HIDE_MS = 10 * 60 * 1000;
    const anchor = document.currentScript?.previousElementSibling?.previousElementSibling;

    if (!anchor || !anchor.matches('[data-ph-anchor]')) {
        return;
    }

    let closedAt = 0;

    try {
        closedAt = Number(window.localStorage.getItem(KEY)) || 0;
    } catch (error) {
        closedAt = 0;
    }

    if (Date.now() - closedAt < HIDE_MS) {
        anchor.remove();

        return;
    }

    anchor.querySelector('[data-ph-anchor-close]')?.addEventListener('click', () => {
        try {
            window.localStorage.setItem(KEY, String(Date.now()));
        } catch (error) {
            // Not remembered: closed for this page only.
        }

        anchor.remove();
    });
})();
</script>
