{{-- The page's ad unit script: picks the size of multi-size units, and refreshes units.
     Injected by AdsPluginServiceProvider::registerUnitScript() on every page that shows ads,
     to a user who is not exempt.

     Picking: a unit over several sizes (AdsterraService::pick()) arrives as one inert
     <template> per size, widest first; the widest that fits the unit's width is inserted,
     or none. Nothing hidden is ever loaded. A slot left with nothing to show is hidden.

     Refresh (AdDisplayService::refreshSeconds(), Plus gets the slower interval, 0 is off):
     only time in view counts. A unit reloads once it has been at least half on screen, in a
     visible tab, for the whole interval. A unit below the fold or in a background tab is
     never reloaded, so every refresh is an impression someone could actually see.

     Sandboxed units only (iframe[srcdoc]): each is a self-contained document, so reloading it
     cannot touch the panel. Inline units write into the top document and the page-level
     formats (Social Bar, Popunder) are not units at all; neither is refreshed. --}}
<script data-plugged-host="ad-units" data-navigate-once>
(() => {
    'use strict';

    const SECONDS = @json($seconds);
@verbatim

    // data-navigate-once keeps this to one instance per page load; it rescans for
    // units every tick, so the ones a wire:navigate swaps in are picked up too.
    if (window.pluggedHostAdUnits) {
        return;
    }

    window.pluggedHostAdUnits = true;

    const pick = () => {
        document.querySelectorAll('[data-ph-pick]:not([data-ph-picked])').forEach((group) => {
            const room = group.clientWidth;

            // Not laid out yet (a collapsed parent): asked again on the next tick.
            if (room === 0) {
                return;
            }

            group.dataset.phPicked = '1';

            const fit = Array.from(group.querySelectorAll(':scope > template'))
                .find((template) => Number(template.dataset.phWidth) <= room);

            if (fit) {
                group.appendChild(fit.content.cloneNode(true));
            }
        });

        // A slot whose units all came out too wide gives its space back.
        document.querySelectorAll('[data-ph-slot]:not([hidden])').forEach((slot) => {
            if (!slot.querySelector('[data-ph-unit], [data-ph-notice], [data-ph-pick]:not([data-ph-picked])')) {
                slot.hidden = true;
            }
        });
    };

    // The IAB/MRC viewability line for display: half the unit on screen.
    const VIEWABLE = 0.5;
    const TICK = 1000;

    // Frame → milliseconds it has been in view since it last loaded.
    const watched = new Map();
    const inView = new WeakSet();

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting && entry.intersectionRatio >= VIEWABLE) {
                inView.add(entry.target);
            } else {
                inView.delete(entry.target);
            }
        });
    }, { threshold: [0, VIEWABLE] });

    const forget = (frame) => {
        observer.unobserve(frame);
        watched.delete(frame);
        inView.delete(frame);
    };

    // A copy of the frame, attributes and all, is a fresh document for the ad script, and
    // the same markup Livewire last rendered, so a morph finds nothing to put back. It takes
    // the old one's place on screen, so it starts counting now rather than a tick later,
    // when the observer would first have reported it.
    const reload = (frame) => {
        const fresh = frame.cloneNode(false);

        forget(frame);
        frame.replaceWith(fresh);

        watched.set(fresh, 0);
        inView.add(fresh);
        observer.observe(fresh);
    };

    const tick = () => {
        pick();

        if (SECONDS <= 0) {
            return;
        }

        document.querySelectorAll('iframe[data-ph-unit][srcdoc]').forEach((frame) => {
            if (!watched.has(frame)) {
                watched.set(frame, 0);
                observer.observe(frame);
            }
        });

        const visible = document.visibilityState === 'visible';

        // A copy: reload() adds the fresh frame, which must not be counted in this tick.
        Array.from(watched).forEach(([frame, ms]) => {
            if (!frame.isConnected) {
                forget(frame);

                return;
            }

            if (!visible || !inView.has(frame)) {
                return;
            }

            if (ms + TICK >= SECONDS * 1000) {
                reload(frame);

                return;
            }

            watched.set(frame, ms + TICK);
        });
    };

    pick();
    document.addEventListener('livewire:navigated', pick);
    window.setInterval(tick, TICK);
@endverbatim
})();
</script>
