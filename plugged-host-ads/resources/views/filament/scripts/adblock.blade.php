{{-- Ad-block detection, and the response to it. See
     PluggedHost\Ads\Services\AdDisplayService::shouldRunAntiBlock() for where
     this is allowed to run at all — it is only ever injected on a page that
     asked for an ad, for a visitor who is not exempt from ads. --}}
<script data-plugged-host="adblock-detect" data-navigate-once>
(() => {
    'use strict';

    const CONFIG = {!! $payload !!};
@verbatim

    // data-navigate-once means Livewire re-runs this script only when the
    // outgoing page had no copy of it, so the detector has to survive on its
    // own: one instance, re-checked on every navigation.
    if (window.pluggedHostAdBlockGuard) {
        window.pluggedHostAdBlockGuard.check();

        return;
    }

    const DISMISS_KEY = 'plugged-host-adblock-dismissed';

    // Lifted from the generic cosmetic filters every major list ships. Nothing
    // but an ad blocker hides an element called `adsbox`, which is what makes
    // this the one signal strong enough to act on by itself.
    const BAIT_CLASSES = 'adsbox ad-banner ads ad-placement pub_300x250 pub_728x90 text-ad textAd sponsored-ad banner_ad';
    const BAIT_ID = 'AdContainer';

    // Two points act, so hiding the bait is decisive on its own while the two
    // circumstantial signals have to agree with each other first. Both of those
    // have innocent explanations — an unfilled unit, a dead host, a paste error
    // — and none of them should be enough to accuse a paying customer.
    const WEIGHT_BAIT = 2;
    const WEIGHT_PROBE = 1;
    const WEIGHT_SLOT = 1;
    const THRESHOLD = 2;

    // A frame that is still loading looks exactly like a blocked one, so nothing
    // is judged until this has passed.
    const SETTLE = 1200;

    // And nothing is acted on until a second look, taken this much later in the
    // same document, agrees with the first. Everything innocent that can reach
    // the threshold is transient — a unit that had not filled yet, a probe the ad
    // network answered with a reset because the page had just been reloaded half a
    // dozen times in a row — and has resolved by the time this has passed. A
    // blocker is still there. The cost is a notice that arrives a few seconds
    // later than it used to, which is by far the cheaper of the two mistakes.
    const CONFIRM_AFTER = 2500;
    const CONFIRM_SETTLE = 600;

    // A reading thrown away because the page moved under it is not an answer, so
    // it gets asked again once things have stopped moving.
    const RETRY_AFTER = 3000;

    // A probe that has not answered by now is a slow network as often as it is a
    // blocked request, so the probe signal is abandoned rather than counted.
    const PROBE_TIMEOUT = 4000;

    // The exact class lists a Filament Action renders for a primary solid button
    // and a gray (secondary) one — Filament gives a gray button no colour classes
    // at all. Copied rather than approximated: they are what makes these the
    // panel's own buttons, palette and dark mode included, with no colour named
    // anywhere in this plugin.
    const BTN_PRIMARY = 'fi-btn fi-size-md fi-color fi-color-primary fi-bg-color-600'
        + ' hover:fi-bg-color-500 dark:fi-bg-color-600 dark:hover:fi-bg-color-500'
        + ' fi-text-color-0 hover:fi-text-color-0 dark:fi-text-color-0 dark:hover:fi-text-color-0';
    const BTN_SECONDARY = 'fi-btn fi-size-md';

    // There is never more than one overlay, so fixed ids are safe here and let
    // the dialog point at its own heading and description.
    const HEADING_ID = 'ph-support-heading';
    const BODY_ID = 'ph-support-body';

    let baitEl = null;
    let blocked = false;
    let running = false;
    let countdown = null;
    let retryTimer = null;
    let probesReachable = false;

    // Bumped by anything that happened to the page rather than to the ads: a
    // wire:navigate body swap, a real navigation, the tab going into the
    // background. Every one of those detaches the bait, cancels probes that are
    // in flight and empties the units that were about to fill, so a measurement
    // spanning one of them is measuring the panel and not a blocker. Only "same
    // number before and after" is ever asked of it, never the value.
    let epoch = 0;

    const disturb = () => {
        epoch += 1;
    };

    const dismissedUntil = () => {
        try {
            return Number.parseInt(window.localStorage.getItem(DISMISS_KEY) || '0', 10) || 0;
        } catch (error) {
            return 0; // Storage disabled, e.g. a hardened private window.
        }
    };

    const dismissFor = () => {
        try {
            window.localStorage.setItem(DISMISS_KEY, String(Date.now() + (CONFIG.dismissHours * 3600000)));
        } catch (error) {
            // Nothing to do; the notice simply comes back on the next page.
        }
    };

    // Block mode has nothing to dismiss, so it never consults a dismissal.
    const isDismissed = () => CONFIG.mode !== 'block' && Date.now() < dismissedUntil();

    const plantBait = () => {
        if (baitEl && baitEl.isConnected) {
            return;
        }

        baitEl = document.createElement('div');
        baitEl.id = BAIT_ID;
        baitEl.className = BAIT_CLASSES;
        baitEl.setAttribute('aria-hidden', 'true');

        // No !important in here, on purpose. A declaration in a style attribute
        // outranks an !important one in an author stylesheet, so hardening these
        // would make the blocker's own display:none lose the cascade and the
        // bait would never trip.
        baitEl.style.cssText = 'position:absolute;left:-9999px;top:-9999px;width:12px;height:12px';
        baitEl.textContent = ' ';

        (document.body || document.documentElement).appendChild(baitEl);
    };

    // Hidden, and still in the document. A cosmetic filter sets display:none on
    // the bait and leaves it where it was; what takes it out of the document
    // altogether is this panel — Livewire replaces the whole <body> on a
    // wire:navigate, which detaches anything a script appended to it. Reading a
    // missing bait as proof is what made a burst of navigations look like an ad
    // blocker, so it is no evidence at all now: plant another one and let the next
    // look answer.
    const baitHidden = () => {
        if (!baitEl || !baitEl.isConnected) {
            plantBait();

            return false;
        }

        const style = window.getComputedStyle(baitEl);

        return baitEl.offsetParent === null
            || baitEl.offsetHeight === 0
            || baitEl.clientHeight === 0
            || style.display === 'none'
            || style.visibility === 'hidden'
            || style.opacity === '0';
    };

    // Every configured host has to be unreachable before this counts. One host
    // failing is a dead unit or a typo; all of them failing is a blocker — and
    // this is the only signal that catches a DNS-level one, which blocks the
    // request and leaves the page markup untouched.
    //
    // Reachable once is reachable, so a host that answered is never asked again in
    // this document. That costs a blocker switched on mid-session, which the next
    // full load catches anyway, and it stops the detector from manufacturing its
    // own evidence: one uncached probe per navigation is a burst the ad network
    // eventually answers with a reset — which from here is indistinguishable from
    // a blocked request.
    const probesFailed = async () => {
        if (!CONFIG.probes.length || navigator.onLine === false || probesReachable) {
            return false;
        }

        const limit = new AbortController();
        const timer = window.setTimeout(() => limit.abort(), PROBE_TIMEOUT);

        try {
            const results = await Promise.all(CONFIG.probes.map((url) => window.fetch(url, {
                method: 'GET',
                mode: 'no-cors',
                // Deliberately not no-store: letting the HTTP cache answer is the
                // other half of not hammering the network on a fast refresh.
                cache: 'default',
                credentials: 'omit',
                redirect: 'follow',
                signal: limit.signal,
            }).then(() => false, () => true)));

            if (!results.every(Boolean)) {
                probesReachable = true;

                return false;
            }

            // Everything failed — but if that is because the clock ran out, what
            // was measured is the network's speed and not its reachability.
            return !limit.signal.aborted;
        } finally {
            window.clearTimeout(timer);
        }
    };

    // A unit the unit script folded because Adsterra sent nothing (a VPN, say) is not evidence
    // of a blocker, so it is left out rather than counted as collapsed.
    const slotsCollapsed = () => {
        const units = Array.from(document.querySelectorAll('[data-ph-unit]:not([data-ph-empty])'));

        if (!units.length) {
            return false;
        }

        let considered = 0;
        let collapsed = 0;

        units.forEach((unit) => {
            // Past the lazy-loading horizon this unit is legitimately empty
            // because the browser has not asked for it yet.
            if (unit.getBoundingClientRect().top > window.innerHeight * 2) {
                return;
            }

            considered += 1;

            const style = window.getComputedStyle(unit);

            if (!unit.isConnected
                || unit.offsetParent === null
                || unit.offsetHeight < 10
                || style.display === 'none'
                || style.visibility === 'hidden') {
                collapsed += 1;
            }
        });

        return considered > 0 && collapsed === considered;
    };

    // textContent throughout: the body copy is admin-supplied free text out of
    // .env, and it gets displayed, never parsed.
    //
    // The shape built here is Filament's own section anatomy — a header, then a
    // bordered content container holding an action row — because the host element
    // is a real `fi-section`. Nothing about the result is this plugin's own look.
    const buildMessage = (dismissible, modal) => {
        const fragment = document.createDocumentFragment();

        const header = document.createElement('header');
        header.className = 'fi-section-header';

        const text = document.createElement('div');
        text.className = 'fi-section-header-text-ctn';

        const heading = document.createElement('h2');
        heading.className = 'fi-section-header-heading';
        heading.textContent = CONFIG.strings.heading;

        const body = document.createElement('p');
        body.className = 'fi-section-header-description';
        body.textContent = CONFIG.strings.body;

        if (modal) {
            heading.id = HEADING_ID;
            body.id = BODY_ID;
        }

        text.append(heading, body);
        header.appendChild(text);

        const actions = document.createElement('div');
        actions.className = 'fi-ac fi-align-start';

        const reload = document.createElement('button');
        reload.type = 'button';
        reload.className = BTN_PRIMARY;
        reload.textContent = CONFIG.strings.reload;
        reload.addEventListener('click', () => window.location.reload());
        actions.appendChild(reload);

        if (dismissible) {
            actions.appendChild(buildDismissButton(modal));
        }

        const contentCtn = document.createElement('div');
        contentCtn.className = 'fi-section-content-ctn';

        const content = document.createElement('div');
        content.className = 'fi-section-content';
        content.appendChild(actions);
        contentCtn.appendChild(content);

        fragment.append(header, contentCtn);

        return fragment;
    };

    const buildDismissButton = (modal) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = BTN_SECONDARY;
        button.textContent = modal ? CONFIG.strings.continue : CONFIG.strings.dismiss;
        button.addEventListener('click', () => {
            dismissFor();
            teardown();
        });

        if (!modal || CONFIG.grace <= 0) {
            return button;
        }

        // An overlay that can be clicked away on reflex is an overlay nobody
        // reads, so the way out unlocks on a count rather than immediately.
        let left = CONFIG.grace;

        const label = () => CONFIG.strings.continueIn.replace(':seconds', String(left));

        // The attribute, the ARIA state and fi-disabled together: that is what a
        // disabled Filament button carries, and the class is what greys it out.
        button.disabled = true;
        button.setAttribute('aria-disabled', 'true');
        button.classList.add('fi-disabled');
        button.textContent = label();

        window.clearInterval(countdown);
        countdown = window.setInterval(() => {
            left -= 1;

            if (left > 0) {
                button.textContent = label();

                return;
            }

            window.clearInterval(countdown);
            countdown = null;
            button.disabled = false;
            button.removeAttribute('aria-disabled');
            button.classList.remove('fi-disabled');
            button.textContent = CONFIG.strings.continue;
        }, 1000);

        return button;
    };

    const teardown = () => {
        window.clearInterval(countdown);
        countdown = null;

        document.querySelectorAll('.ph-support-overlay').forEach((node) => node.remove());
        document.documentElement.classList.remove('ph-support-locked');

        document.querySelectorAll('[data-ph-notice]').forEach((host) => {
            host.replaceChildren();
            host.hidden = true;
            delete host.dataset.phRendered;
        });
    };

    const renderNotice = () => {
        document.querySelectorAll('[data-ph-notice]').forEach((host) => {
            if (host.dataset.phRendered === '1') {
                return;
            }

            host.dataset.phRendered = '1';
            host.hidden = false;
            host.appendChild(buildMessage(true, false));
        });
    };

    const renderOverlay = () => {
        if (document.querySelector('.ph-support-overlay')) {
            return;
        }

        const overlay = document.createElement('div');
        overlay.className = 'ph-support-overlay';

        // The dialog carries the role, not the backdrop: what the visitor is being
        // asked about is the box, and it is also what takes focus. It is a real
        // fi-section rather than an fi-modal because a modal needs Alpine to drive
        // it, and this has to work on a page Livewire has already navigated.
        const dialog = document.createElement('div');
        dialog.className = 'ph-support-dialog fi-section fi-section-has-header';
        dialog.setAttribute('role', 'alertdialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-labelledby', HEADING_ID);
        dialog.setAttribute('aria-describedby', BODY_ID);
        dialog.tabIndex = -1;
        dialog.appendChild(buildMessage(CONFIG.mode === 'modal', true));

        overlay.appendChild(dialog);
        document.body.appendChild(overlay);
        document.documentElement.classList.add('ph-support-locked');
        dialog.focus();
    };

    const render = () => {
        if (!blocked || isDismissed()) {
            return;
        }

        if (CONFIG.mode === 'notice') {
            renderNotice();

            return;
        }

        renderOverlay();
    };

    const wait = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));

    // Bounded to one pending retry: a panel somebody is clicking through quickly
    // would otherwise queue one of these per click.
    const retry = () => {
        window.clearTimeout(retryTimer);
        retryTimer = window.setTimeout(check, RETRY_AFTER);
    };

    // One complete reading, or null when the page moved under it and the numbers
    // stopped meaning anything.
    const measure = async (settleMs) => {
        const mark = epoch;

        plantBait();

        await wait(settleMs);

        if (mark !== epoch || document.visibilityState !== 'visible') {
            return null;
        }

        let score = 0;

        if (baitHidden()) {
            score += WEIGHT_BAIT;
        }

        if (slotsCollapsed()) {
            score += WEIGHT_SLOT;
        }

        // Skipped once the bait has already settled it, so the common case
        // costs no extra requests.
        if (score < THRESHOLD && await probesFailed()) {
            score += WEIGHT_PROBE;
        }

        return mark === epoch ? score : null;
    };

    const check = async () => {
        if (running || isDismissed()) {
            return;
        }

        running = true;

        try {
            // The verdict survives navigation; the markup it produced does not.
            // So a visitor already known to be blocking just gets the message
            // re-attached to the new page instead of being re-tested.
            if (blocked) {
                render();

                return;
            }

            // A background tab loads no lazy frame and has its requests
            // deprioritised, so there is nothing to be proved either way in one.
            if (document.visibilityState !== 'visible') {
                return;
            }

            const first = await measure(SETTLE);

            if (first === null) {
                retry();

                return;
            }

            if (first < THRESHOLD) {
                return;
            }

            await wait(CONFIRM_AFTER);

            const second = await measure(CONFIRM_SETTLE);

            if (second === null) {
                retry();

                return;
            }

            if (second < THRESHOLD) {
                return;
            }

            blocked = true;
            render();
        } finally {
            running = false;
        }
    };

    window.pluggedHostAdBlockGuard = { check };

    // Registered ahead of the check listeners on purpose: a navigation has to be
    // recorded as a disturbance before anything tries to measure across it.
    document.addEventListener('livewire:navigate', disturb);
    document.addEventListener('livewire:navigating', disturb);
    window.addEventListener('pagehide', disturb);

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            check();

            return;
        }

        disturb();
    });

    document.addEventListener('livewire:navigated', check);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', check, { once: true });
    } else {
        check();
    }
@endverbatim
})();
</script>
