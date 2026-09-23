{{--
    The ad-funded waiting room. See PluggedHost\Ads\Filament\Server\Pages\StartGatePage
    for why the release is server-side and why the countdown here is decoration.

    wire:poll is still what ends the wait: it calls tick() on its own interval and that call is
    what eventually redirects out. What it no longer does is draw the clock. It used to, and the
    result was a countdown that jumped two seconds at a time and a progress bar easing toward a
    percentage that was already stale when it arrived. The clock is now a 1 Hz Alpine loop
    re-seeded from the server's own elapsed on every poll (see the x-effect below), so the two
    can move at different rates without disagreeing: the browser interpolates, the server decides.

    The <style> block is in the body on purpose, and now carries more than cosmetics:
    it hides the sidebar for the duration of the wait. wire:navigate swaps the body but
    never removes a <style> from <head>, so the same rules injected at STYLES_BEFORE
    would keep hiding the sidebar on every page the user visited afterwards. In the
    body they exist exactly as long as this document does, and both the way in and the
    way out of this page are full loads (navigate: false), so there is nothing to leak.

    The ad stack sits inside wire:ignore (in the partial) because the poll morphs this
    component every couple of seconds and freshly rendered markup does not contain what
    the ad scripts appended after the last render.
--}}
<x-filament-panels::page>
    <style>
        /* Sidebar hidden while the wait runs. The room is one screen with one purpose;
           a navigation rail beside it is both a distraction and an invitation to leave
           a page that has not been paid for yet. Nothing is trapped — the topbar, its
           user menu and the browser's own controls all still work.

           Filament's layout is a flex row (.fi-layout > .fi-sidebar + .fi-main-ctn,
           the sidebar sticky with width: var(--sidebar-width)), so display: none is
           enough on its own: the main container is flex: 1 and reclaims the space.
           There is no inset to zero in this version. The toggle buttons go too, or the
           user is left with a control that opens nothing, and the close overlay would
           otherwise be able to swallow a click on an ad. */
        .fi-sidebar,
        #fi-main-sidebar,
        .fi-sidebar-close-overlay,
        .fi-layout-sidebar-toggle-btn-ctn,
        .fi-topbar-open-sidebar-btn,
        .fi-sidebar-open-sidebar-btn,
        .fi-breadcrumbs { display: none !important; }

        .ph-gate-track { height: 0.375rem; border-radius: 999px; overflow: hidden; background: rgb(0 0 0 / 0.08); }
        .dark .ph-gate-track { background: rgb(255 255 255 / 0.1); }
        /* 1s, matching the client clock's tick, so the fill advances one visible second at a
           time. It used to be 2.1s to cover the poll interval, which meant the bar was always
           easing toward a percentage that had been true when the response was generated — it
           never caught up, and "smooth" read as "lagging". */
        .ph-gate-fill { height: 100%; border-radius: 999px; transition: width 1s linear; }
        @media (prefers-reduced-motion: reduce) {
            .ph-gate-fill { transition: none; }
        }
        /* Heights are reserved so the page does not jump as units fill, and so an
           empty unit leaves a stable gap instead of collapsing the layout. */
        .ph-gate-unit { display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .ph-gate-sponsored {
            display: block; text-align: center; font-size: 0.6875rem; line-height: 1;
            letter-spacing: 0.08em; text-transform: uppercase; color: rgb(107 114 128);
        }
        .dark .ph-gate-sponsored { color: rgb(107 114 128); }
    </style>

    {{--
        One x-data for the whole room: the ad-block probe it always had, plus a 1 Hz clock.

        `base` is the server's elapsed and `ticks` is how many seconds the browser has counted
        since it was last handed one. x-effect is the load-bearing half: x-data is evaluated once
        and a Livewire morph does not re-run it, so reading $wire.elapsedSeconds *inside an
        effect* is what registers the dependency and snaps the clock back to the server on every
        poll. Without it the two clocks drift apart forever, and the server's is the one that
        decides when the wait ends.

        Bare identifiers rather than `this.` throughout, including inside the arrow functions:
        Alpine evaluates these expressions against the data scope, so `ticks++` writes to it.
    --}}
    <div class="flex flex-col gap-6"
         wire:poll.keep-alive.{{ $poll }}="tick"
         x-data="{
             probes: @js($probes),
             base: @js($elapsed),
             max: @js($maxDwell),
             released: @js($released),
             ticks: 0,
             nudged: false,
             get elapsed() { return this.released ? this.max : Math.min(this.max, this.base + this.ticks) },
             get remaining() { return this.released ? 0 : Math.max(0, this.max - this.elapsed) },
             get percent() { return this.released ? 100 : (this.max > 0 ? Math.min(100, this.elapsed / this.max * 100) : 100) },
             refreshAds() {
                 document.querySelectorAll('.ph-gate-unit').forEach((container) => {
                     const iframe = container.querySelector('iframe');
                     if (iframe) {
                         const srcdoc = iframe.getAttribute('srcdoc') || iframe.srcdoc;
                         if (srcdoc) {
                             const cacheBuster = '_t=' + Date.now();
                             const refreshedSrcdoc = srcdoc.replace(/(\.js(?:\?[^\s\x27\x22>]*)?)/g, (match) => {
                                 const clean = match.split('?')[0];
                                 return clean + '?' + cacheBuster;
                             });
                             const nextIframe = iframe.cloneNode(false);
                             nextIframe.srcdoc = refreshedSrcdoc;
                             iframe.replaceWith(nextIframe);
                         }
                     }
                 });
             },
         }"
         x-effect="
             if ($wire.released) { released = true }
             if (typeof $wire.maxSeconds === 'number') { max = $wire.maxSeconds }
             if (typeof $wire.elapsedSeconds === 'number') { base = $wire.elapsedSeconds; ticks = 0 }
         "
         x-init="
             if (window.Livewire) {
                 if (typeof Livewire.interceptRequest === 'function') {
                     Livewire.interceptRequest(({ onRedirect }) => {
                         onRedirect(() => { released = true; });
                     });
                 }
                 if (typeof Livewire.hook === 'function') {
                     Livewire.hook('request', ({ succeed }) => {
                         succeed(({ json }) => {
                             if (json?.effects?.redirect || json?.components?.some(c => c?.effects?.redirect)) {
                                 released = true;
                             }
                         });
                     });
                 }
             }

             // Refresh ad units every 3 seconds while waiting to display new ads
             setInterval(() => {
                 if (! released && remaining > 0 && document.visibilityState === 'visible') {
                     refreshAds();
                 }
             }, 3000);

             // Trap back button while wait is active
             history.pushState(null, '', window.location.href);
             window.addEventListener('popstate', () => {
                 if (! released && remaining > 0) {
                     history.pushState(null, '', window.location.href);
                 }
             });

             // Prevent leaving via SPA navigation while waiting
             document.addEventListener('livewire:navigate', (e) => {
                 if (! released && remaining > 0) {
                     e.preventDefault();
                 }
             });

             // Intercept link clicks within the server panel while waiting
             document.addEventListener('click', (e) => {
                 let link = e.target.closest('a');
                 if (link && ! released && remaining > 0 && link.href && ! link.target && ! link.hasAttribute('download')) {
                     let url = new URL(link.href, window.location.origin);
                     if (url.origin === window.location.origin && url.pathname.includes('/server/')) {
                         e.preventDefault();
                         e.stopPropagation();
                     }
                 }
             }, true);

             // Warn before accidental page reload / close while wait is active
             window.addEventListener('beforeunload', (e) => {
                 if (released || $wire.released || $wire?.__instance?.effects?.redirect) {
                     return;
                 }
                 if (remaining > 0) {
                     e.preventDefault();
                     e.returnValue = '';
                 }
             });

             document.addEventListener('visibilitychange', () => {
                 if (! released && document.visibilityState === 'visible' && remaining === 0) {
                     $wire.tick();
                 }
             });
             window.addEventListener('focus', () => {
                 if (! released && remaining === 0) {
                     $wire.tick();
                 }
             });

             setInterval(() => {
                 ticks++;

                 {{-- The release is still the server's call; this only asks it to look now
                      rather than up to POLL_INTERVAL later, so reaching zero and leaving are
                      the same moment. attemptRelease() is idempotent, and if the server does
                      not agree yet the ordinary poll picks it up. --}}
                 if (! released && remaining === 0 && ! nudged) {
                     nudged = true;
                     $wire.tick();
                 }
             }, 1000);

             (async () => {
                 for (const url of probes) {
                     try {
                         await fetch(url, { mode: 'no-cors', cache: 'no-store' });
                     } catch (e) {
                         $wire.reportBlocked();
                         return;
                     }
                 }
             })()
         ">

        <x-filament::section class="rounded-2xl">
            <div class="flex flex-col gap-5">
                <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-5">
                        <div class="flex size-14 shrink-0 items-center justify-center rounded-full bg-gray-100 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                            <x-filament::loading-indicator class="size-6 text-primary-600 dark:text-primary-400" />
                        </div>

                        <div class="flex min-w-0 flex-col gap-1.5">
                            <span class="truncate text-lg font-semibold tracking-tight text-gray-950 dark:text-white">
                                {{ trans('plugged-host-ads::strings.start_gate_heading') }}
                            </span>
                            <p class="text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                                {{ trans('plugged-host-ads::strings.start_gate_body', ['seconds' => $maxDwell]) }}
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-col gap-1 sm:items-end sm:text-right">
                        <span class="text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-500">
                            {{ trans('plugged-host-ads::strings.start_gate_wait_label') }}
                        </span>
                        {{--
                            The inner text is the server's answer and stays as the pre-Alpine
                            paint; x-text takes over on boot and is what makes the number move
                            once a second instead of once a poll. i18n stays server-side: the
                            line is translated with ':n' as the placeholder value and the count
                            substituted in JS, so en and de both work with no new keys and no
                            plural logic in the browser.
                        --}}
                        <span class="text-2xl font-semibold leading-none tabular-nums text-gray-950 dark:text-white"
                              x-text="remaining > 0
                                  ? @js(trans('plugged-host-ads::strings.start_gate_seconds', ['seconds' => ':n'])).replace(':n', remaining)
                                  : @js(trans('plugged-host-ads::strings.start_gate_ready'))">
                            @if ($remaining > 0)
                                {{ trans('plugged-host-ads::strings.start_gate_seconds', ['seconds' => $remaining]) }}
                            @else
                                {{ trans('plugged-host-ads::strings.start_gate_ready') }}
                            @endif
                        </span>
                    </div>
                </div>

                @php
                    $percent = $maxDwell > 0 ? min(100, (int) round($elapsed / $maxDwell * 100)) : 100;
                @endphp
                <div class="ph-gate-track">
                    <div class="ph-gate-fill bg-primary-600 dark:bg-primary-500"
                         style="width: {{ $percent }}%"
                         :style="{ width: percent + '%' }"></div>
                </div>

            </div>
        </x-filament::section>

        {{-- The ad stack sits directly under the status card, above everything else
             on the page. This surface is not a progress indicator with ads attached;
             it is the moment the server is paid for, and the thing being paid to is
             what should be in front of the user.

             Split in two, because a publisher with several extra banner zones
             configured would otherwise stack all of them above the fold and push the
             countdown off the screen. The first two keep the position they have always
             had; the rest render again below, where scrolling is a reason to stay on
             the page rather than a reason to lose the progress bar. --}}
        @php
            $unitsAbove = array_slice($units, 0, 2);
            $unitsBelow = array_slice($units, 2);
        @endphp

        @include('plugged-host-ads::filament.interstitial-units', ['units' => $unitsAbove, 'sandbox' => $sandbox])

        {{-- A Direct Link is an ordinary URL that pays per landing, so it cannot be a
             unit and cannot be sandboxed. It is a link the user chooses to click:
             target=_blank so the wait keeps running in this tab, rel=noopener so the
             advertiser's page cannot reach window.opener, and nofollow/sponsored
             because that is what a paid link is. Off unless
             start_credits.gate_ads.direct_link is on — a panel that opens tabs by
             itself is adware. --}}
        @if ($directLink)
            <x-filament::section class="rounded-2xl">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex min-w-0 flex-col gap-1.5">
                        <span class="text-base font-semibold tracking-tight text-gray-950 dark:text-white">
                            {{ trans('plugged-host-ads::strings.start_gate_direct_heading') }}
                        </span>
                        <p class="text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                            {{ trans('plugged-host-ads::strings.start_gate_direct_body') }}
                        </p>
                    </div>

                    <a href="{{ $directLink }}"
                       target="_blank"
                       rel="noopener noreferrer nofollow sponsored"
                       class="fi-btn fi-btn-size-md inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 dark:bg-primary-500 dark:hover:bg-primary-400">
                        {{ trans('plugged-host-ads::strings.start_gate_direct_cta') }}
                    </a>
                </div>
            </x-filament::section>
        @endif

        @include('plugged-host-ads::filament.interstitial-units', ['units' => $unitsBelow, 'sandbox' => $sandbox])
    </div>
</x-filament-panels::page>
