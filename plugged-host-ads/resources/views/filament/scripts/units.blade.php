{{-- The page's ad unit script: picks the size of multi-size units, and refreshes units.
     Injected by AdsPluginServiceProvider::registerUnitScript() on every page that shows ads,
     to a user who is not exempt.

     Picking: a unit over several sizes (AdsterraService::pick()) arrives as one inert
     <template> per size, widest first; the widest that fits the unit's width is inserted,
     or none. Nothing hidden is ever loaded.

     Deferred: a slot's or the foot's unit (filament/deferred-unit.blade.php) is a group like
     that with one template, inserted only once its spot is on screen in a visible tab, so a
     unit at the foot of a long page loads when someone scrolls to it, not on page load.

     No fill: Adsterra sends nothing to some visitors (VPNs among them), which used to leave a
     blank frame. A frame whose document has finished loading and still holds nothing with a
     size after NO_FILL_AFTER is folded to zero height, and a slot, waiting-room cell or stack
     left with no unit showing is hidden. A late fill unfolds it again. Only on a unit's first
     load: a refreshed unit that comes back empty keeps its space, so nothing jumps under the
     reader, and is refreshed again.

     Refresh (AdDisplayService::refreshSeconds(), Plus gets the slower interval, 0 is off):
     only time in view counts. A unit reloads once it has been at least half on screen, in a
     visible tab, for the whole interval. A unit below the fold or in a background tab is
     never reloaded, so every refresh is an impression someone could actually see.

     Sandboxed units only (iframe[srcdoc]): each is a self-contained document, so reloading it
     cannot touch the panel. Inline units write into the top document and the page-level
     formats (Social Bar, Popunder) are not units at all; neither is refreshed.

     Sponsored banners (data-ph-unit="direct", AdBannerService) are our own image and link, not
     a frame: never folded, never refreshed, and they keep their slot showing. --}}
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

    // Any part of the box in the viewport (a group waiting to be filled has no height yet).
    const onScreen = (el) => {
        const box = el.getBoundingClientRect();

        return box.bottom >= 0 && box.top <= window.innerHeight;
    };

    // Deferred groups (filament/deferred-unit.blade.php) wait until they are on screen in a
    // visible tab. A deferred unit can itself be a multi-size group, picked in the same call.
    const pick = () => {
        let inserted = true;

        while (inserted) {
            inserted = false;

            document.querySelectorAll('[data-ph-pick]:not([data-ph-picked])').forEach((group) => {
                const room = group.clientWidth;

                // Not laid out yet (a collapsed parent): asked again on the next tick.
                if (room === 0) {
                    return;
                }

                if ('phDefer' in group.dataset && (document.visibilityState !== 'visible' || !onScreen(group))) {
                    return;
                }

                group.dataset.phPicked = '1';

                const fit = Array.from(group.querySelectorAll(':scope > template'))
                    .find((template) => Number(template.dataset.phWidth) <= room);

                if (fit) {
                    group.appendChild(fit.content.cloneNode(true));
                    inserted = true;
                }
            });
        }
    };

    const NO_FILL_AFTER = 5000;
    const NOT_CONTENT = new Set(['SCRIPT', 'STYLE', 'LINK', 'META', 'BASE', 'NOSCRIPT', 'TEMPLATE']);

    // Frame → when its document was first seen loaded and empty.
    const emptySince = new WeakMap();
    const ownStyle = new WeakMap();

    // The srcdoc document once it has loaded, or null: a lazy frame that has not been asked for
    // yet still holds its initial about:blank. Same-origin (allow-same-origin), so readable.
    const frameDocument = (frame) => {
        try {
            const doc = frame.contentDocument;

            return doc && doc.URL === 'about:srcdoc' && doc.readyState === 'complete' ? doc : null;
        } catch (error) {
            return null;
        }
    };

    // The banner writes an iframe of its size, the native unit fills its container: either way
    // something with a size. A unit Adsterra declined holds only our scripts and an empty div.
    const hasAd = (doc) => Array.from(doc.body ? doc.body.querySelectorAll('*') : []).some((el) => {
        if (NOT_CONTENT.has(el.tagName)) {
            return false;
        }

        const box = el.getBoundingClientRect();

        return box.width >= 10 && box.height >= 10;
    });

    // Folded rather than display: none, so its document keeps a layout and a late fill shows.
    const fold = (frame) => {
        ownStyle.set(frame, frame.style.cssText);
        frame.dataset.phEmpty = '1';
        frame.style.height = '0px';
        frame.style.margin = '0';
        frame.style.visibility = 'hidden';
    };

    const unfold = (frame) => {
        frame.style.cssText = ownStyle.get(frame) ?? frame.style.cssText;
        delete frame.dataset.phEmpty;
    };

    const checkFill = () => {
        document.querySelectorAll('iframe[data-ph-unit][srcdoc]:not([data-ph-filled])').forEach((frame) => {
            const doc = frameDocument(frame);

            if (!doc) {
                return;
            }

            if (hasAd(doc)) {
                frame.dataset.phFilled = '1';

                if (frame.dataset.phEmpty) {
                    unfold(frame);
                }

                return;
            }

            if (frame.dataset.phEmpty || frame.dataset.phRefreshed) {
                return;
            }

            if (!emptySince.has(frame)) {
                emptySince.set(frame, Date.now());
            } else if (Date.now() - emptySince.get(frame) >= NO_FILL_AFTER) {
                fold(frame);
            }
        });
    };

    // A box with no unit left showing gives its space back: the slot, a waiting-room cell, and
    // the waiting room's stack with its "Sponsored" caption. A unit still to be picked or still
    // loading counts as showing.
    const SHOWING = '[data-ph-unit]:not([data-ph-empty]), [data-ph-notice], [data-ph-pick]:not([data-ph-picked])';

    // Taken out of the flow and folded, not display: none: inside a display: none box a frame has
    // no layout, and a late fill could never be measured.
    const FOLDED_BOX = ';position:absolute;height:0;min-height:0;margin:0;padding:0;overflow:hidden;visibility:hidden;pointer-events:none';
    const boxStyle = new WeakMap();

    const tidy = () => {
        document.querySelectorAll('[data-ph-slot], .ph-gate-unit, .ph-gate-stack').forEach((box) => {
            const showing = box.querySelector(SHOWING) !== null;

            if (!showing && !box.dataset.phFolded) {
                boxStyle.set(box, box.style.cssText);
                box.dataset.phFolded = '1';
                box.style.cssText += FOLDED_BOX;
            } else if (showing && box.dataset.phFolded) {
                box.style.cssText = boxStyle.get(box) ?? '';
                delete box.dataset.phFolded;
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

        delete fresh.dataset.phFilled;
        fresh.dataset.phRefreshed = '1';

        forget(frame);
        frame.replaceWith(fresh);

        watched.set(fresh, 0);
        inView.add(fresh);
        observer.observe(fresh);
    };

    const tick = () => {
        pick();
        checkFill();
        tidy();

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

            // A first load is left to the fill check until it shows an ad: refreshing it sooner
            // would replace a unit Adsterra declined before it could be folded away.
            if (!visible || !inView.has(frame) || (!frame.dataset.phFilled && !frame.dataset.phRefreshed)) {
                return;
            }

            if (ms + TICK >= SECONDS * 1000) {
                reload(frame);

                return;
            }

            watched.set(frame, ms + TICK);
        });
    };

    const settle = () => {
        pick();
        tidy();
    };

    settle();
    document.addEventListener('livewire:navigated', settle);
    window.setInterval(tick, TICK);
@endverbatim
})();
</script>
