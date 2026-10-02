{{--
    The queue's waiting page. See PluggedHost\Queue\Filament\Server\Pages\StartQueuePage
    for why the release is server-side and why nothing here is load-bearing.

    wire:poll is still what ends the wait: every five seconds the component re-renders,
    which recounts the position and is the call that eventually redirects out. What it no
    longer does is draw the clock. It used to, and at a five-second poll that meant a
    "Waiting m:ss" readout that stood still for five seconds and then jumped, and a bar
    easing toward a percentage that had already been stale when the response was generated.
    The clock is now a 1 Hz Alpine loop re-seeded from the server's own elapsed on every
    poll (see the x-effect below), so the two can move at different rates without
    disagreeing: the browser interpolates, the server decides.

    The <style> block is in the body deliberately. wire:navigate swaps the body but never
    removes a <style> from <head>, so the same rules injected at a head render hook would
    keep hiding the sidebar on every page the user visited afterwards. In the body they
    live exactly as long as this document, and both the way in and the way out of this
    page are full loads (navigate: false).

    The ad stack sits inside wire:ignore (in the partial it includes) because the poll
    morphs this component every few seconds, and freshly rendered markup does not contain
    what the ad scripts appended after the last render.
--}}
<x-filament-panels::page>
    <style>
        /* Sidebar hidden for the duration of the wait, same as the ad gate's room: one
           screen with one purpose, and a navigation rail beside it is an invitation to
           leave a page that is holding something for you. Nothing is trapped — the
           topbar, its user menu and the browser's own controls all still work.

           Filament's layout is a flex row (.fi-layout > .fi-sidebar + .fi-main-ctn), so
           display: none is enough; the main container is flex: 1 and reclaims the space.
           The toggle buttons go too, or the user is left with a control that opens
           nothing, and the close overlay would otherwise swallow a click on an ad. */
        .fi-sidebar,
        #fi-main-sidebar,
        .fi-sidebar-close-overlay,
        .fi-layout-sidebar-toggle-btn-ctn,
        .fi-topbar-open-sidebar-btn,
        .fi-sidebar-open-sidebar-btn,
        .fi-breadcrumbs { display: none !important; }

        /* Theme v2 tokens throughout (dark-only), so this reads the same whatever
           Filament's light/dark switch says. */
        .ph-queue-heading { font-size: 1rem; font-weight: 600; line-height: 1.5rem; color: var(--phv2-text); }
        .ph-queue-title { font-size: 0.875rem; font-weight: 600; line-height: 1.25rem; color: var(--phv2-text); }
        .ph-queue-muted { font-size: 0.875rem; line-height: 1.375rem; color: var(--phv2-text-muted); }
        .ph-queue-small { font-size: 0.75rem; line-height: 1.125rem; color: var(--phv2-text-muted); }
        .ph-queue-faint { font-size: 0.75rem; line-height: 1.125rem; color: var(--phv2-text-faint); }
        .ph-queue-caption { font-size: 0.6875rem; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; color: var(--phv2-text-muted); }
        .ph-queue-value { font-size: 1.5rem; font-weight: 600; line-height: 2rem; font-variant-numeric: tabular-nums; color: var(--phv2-accent-bright); }
        .ph-queue-accent { font-size: 0.75rem; font-weight: 500; color: var(--phv2-accent-bright); }
        .ph-queue-num { font-size: 0.75rem; font-weight: 600; font-variant-numeric: tabular-nums; color: var(--phv2-text); }
        .ph-queue-num-over { color: var(--danger-400); }
        .ph-queue-link { font-weight: 500; color: var(--phv2-accent-bright); }
        .ph-queue-link:hover { color: var(--phv2-accent-glow); text-decoration: underline; }
        .ph-queue-spinner { width: 1.25rem; height: 1.25rem; flex-shrink: 0; color: var(--phv2-accent-bright); }

        .ph-queue-track { height: 0.375rem; border-radius: 9999px; overflow: hidden; background-color: var(--phv2-raise); }
        /* 1s, matching the client clock's tick, so the fill advances one visible second at
           a time. It used to be 5.1s to cover the poll interval, which meant the bar spent
           the whole wait easing toward a percentage that had been true five seconds ago —
           it never caught up, and "smooth" read as "lagging". */
        .ph-queue-fill { height: 100%; border-radius: 9999px; background-color: var(--phv2-accent-bright); transition: width 1s linear; }
        @media (prefers-reduced-motion: reduce) {
            .ph-queue-fill { transition: none; }
        }
        .ph-queue-meter { display: flex; flex-direction: column; gap: 0.375rem; }
        .ph-queue-bar { height: 0.25rem; border-radius: 9999px; overflow: hidden; background-color: var(--phv2-raise); }
        .ph-queue-bar > span { display: block; height: 100%; border-radius: 9999px; background-color: var(--phv2-accent-bright); }
        .ph-queue-bar > span.ph-queue-bar-over { background-color: var(--danger-500); }

        /* Full-width rows: text on the left, the action on the right from `sm` up and
           under the text, full width, below it. */
        .ph-queue-rows > * + * { border-top: 1px solid var(--phv2-hairline); }
        .ph-queue-row { display: flex; flex-direction: column; gap: 0.75rem; padding-block: 1rem; }
        .ph-queue-row:first-child { padding-top: 0; }
        .ph-queue-row:last-child { padding-bottom: 0; }
        .ph-queue-action { display: flex; width: 100%; }
        @media (min-width: 640px) {
            .ph-queue-row { flex-direction: row; align-items: center; justify-content: space-between; gap: 1.5rem; }
            .ph-queue-action { width: 15rem; flex-shrink: 0; }
        }
    </style>

    {{--
        One x-data for the whole page: the ad-block probe it always had, plus a 1 Hz clock.

        `base` is the server's elapsed and `ticks` is how many seconds the browser has counted
        since it was last handed one. x-effect is the load-bearing half: x-data is evaluated
        once and a Livewire morph does not re-run it, so reading $wire.elapsedSeconds *inside
        an effect* is what registers the dependency and snaps the clock back to the server on
        every poll. Without it the two clocks drift apart forever, and the server's is the one
        that decides when the wait ends.

        `elapsed` is uncapped on purpose — it is how long this person has been here, and the
        cap firing does not make that untrue. `percent` and `remaining` clamp themselves.

        Bare identifiers rather than `this.` outside the x-data object, including inside the
        arrow functions: Alpine evaluates those expressions against the data scope, so
        `ticks++` writes to it.
    --}}
    <div class="flex flex-col gap-6"
         wire:poll.{{ $poll }}="tick"
         x-data="{
             probes: @js($probes),
             base: @js($elapsed),
             max: @js($maxWait),
             ticks: 0,
             nudged: false,
             get elapsed() { return this.base + this.ticks },
             get remaining() { return Math.max(0, this.max - this.elapsed) },
             get percent() { return this.max > 0 ? Math.min(100, this.elapsed / this.max * 100) : 100 },
             get waited() { return Math.floor(this.elapsed / 60) + ':' + String(this.elapsed % 60).padStart(2, '0') },
         }"
         x-effect="
             if (typeof $wire.maxSeconds === 'number') { max = $wire.maxSeconds }
             if (typeof $wire.elapsedSeconds === 'number') { base = $wire.elapsedSeconds; ticks = 0 }
         "
         x-init="
             setInterval(() => {
                 ticks++;

                 {{-- Reaching zero here means the hard cap has fired, which is the one
                      release this page can predict. Asking the server to look now rather
                      than up to POLL_INTERVAL later makes the promise on screen and the
                      redirect the same moment. tick() is safe to call early: it recounts
                      and releases only if releaseReason() agrees, and the ordinary poll
                      picks it up otherwise. --}}
                 if (remaining === 0 && ! nudged) {
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

        {{-- Status: where you are in the line, how long you have been there, and why.
             The reason is spelled out rather than hinted at — somebody whose start is
             being held deserves to know it is capacity and not a fault. --}}
        <x-filament::section>
            <div class="flex flex-col gap-5">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex min-w-0 flex-col gap-1">
                        <div class="flex items-center gap-2">
                            <x-filament::loading-indicator class="ph-queue-spinner" />
                            <span class="ph-queue-heading truncate">{{ trans('plugged-host-queue::strings.heading') }}</span>
                        </div>
                        <p class="ph-queue-muted">
                            {{ trans('plugged-host-queue::strings.body_' . ($action === 'restart' ? 'restart' : 'start')) }}
                        </p>
                    </div>

                    <div class="flex shrink-0 flex-col gap-0.5 sm:items-end sm:text-right">
                        <span class="ph-queue-caption">{{ trans('plugged-host-queue::strings.position_label') }}</span>
                        <span class="ph-queue-value">
                            @if ($position > 1)
                                {{ trans('plugged-host-queue::strings.position_value', ['position' => $position]) }}
                            @else
                                {{ trans('plugged-host-queue::strings.position_next') }}
                            @endif
                        </span>
                        @if ($priorityActive)
                            <span class="ph-queue-accent">{{ trans('plugged-host-queue::strings.position_priority') }}</span>
                        @endif
                    </div>
                </div>

                @php
                    $percent = $maxWait > 0 ? min(100, (int) round($elapsed / $maxWait * 100)) : 100;
                    $waited = sprintf('%d:%02d', intdiv($elapsed, 60), $elapsed % 60);
                @endphp
                <div class="ph-queue-track">
                    <div class="ph-queue-fill"
                         style="width: {{ $percent }}%"
                         :style="{ width: percent + '%' }"></div>
                </div>

                {{--
                    Both readouts keep their server-rendered text as the pre-Alpine paint and
                    are taken over by x-text on boot, which is what makes them move once a
                    second instead of once a poll. i18n stays server-side: each line is
                    translated with ':n' as the placeholder value and the number substituted
                    in JS, so en and de both work with no new keys and no formatting logic
                    duplicated in the browser.
                --}}
                <div class="ph-queue-small flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                    <span x-text="@js(trans('plugged-host-queue::strings.waited', ['time' => ':n'])).replace(':n', waited)">
                        {{ trans('plugged-host-queue::strings.waited', ['time' => $waited]) }}
                    </span>
                    <span x-text="remaining > 0
                              ? @js(trans('plugged-host-queue::strings.cap_note', ['seconds' => ':n'])).replace(':n', remaining)
                              : @js(trans('plugged-host-queue::strings.cap_reached'))">
                        @if ($remaining > 0)
                            {{ trans('plugged-host-queue::strings.cap_note', ['seconds' => $remaining]) }}
                        @else
                            {{ trans('plugged-host-queue::strings.cap_reached') }}
                        @endif
                    </span>
                </div>
            </div>
        </x-filament::section>
        {{-- Why. Live percentages from the same reading the release decision uses, so
             the number on screen is the number the queue is acting on rather than a
             separate estimate that can disagree with it. --}}
        @if ($usage !== null)
            <x-filament::section>
                <div class="flex flex-col gap-4">
                    <p class="ph-queue-muted">
                        @if ($reason)
                            {{ trans('plugged-host-queue::strings.reason_' . $reason, ['limit' => (int) $limit]) }}
                        @else
                            {{ trans('plugged-host-queue::strings.reason_cleared') }}
                        @endif
                    </p>

                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach ($dimensions as $dimension)
                            @php
                                $value = $usage[$dimension] ?? null;
                                $shown = $value === null ? null : min(100, max(0, (float) $value));
                                $over = $shown !== null && $shown > $limit;
                            @endphp
                            <div class="ph-queue-meter">
                                <div class="flex items-baseline justify-between gap-2">
                                    <span class="ph-queue-small">
                                        {{ trans('plugged-host-queue::strings.usage_' . $dimension) }}
                                    </span>
                                    <span @class(['ph-queue-num', 'ph-queue-num-over' => $over])>
                                        {{ $shown === null ? '—' : number_format($shown, 1) . '%' }}
                                    </span>
                                </div>
                                <div class="ph-queue-bar">
                                    <span @class(['ph-queue-bar-over' => $over])
                                          style="width: {{ $shown === null ? 0 : (int) round($shown) }}%"></span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </x-filament::section>
        @endif
        {{-- The ad stack, split around the two offers. The first units sit directly under
             the status card: this page is where waiting is exchanged for capacity that
             somebody has to pay for, so what pays for it belongs above the fold. The rest
             render below the offers, where scrolling is a reason to stay on the page. --}}
        @php
            $unitsAbove = array_slice($units, 0, 2);
            $unitsBelow = array_slice($units, 2);
            // One filled button per view: the ad-free tier (or the only tier) while the
            // priority offer is on screen, otherwise the sponsored link.
            $offerShown = !$priorityActive && $priorityAvailable && filled($priorityTiers);
        @endphp

        @if ($hasAdPartial && $unitsAbove !== [])
            @include('plugged-host-ads::filament.interstitial-units', ['units' => $unitsAbove, 'sandbox' => $sandbox])
        @endif

        {{-- What tokens buy. Two states: a live window, which is a receipt, and the offer
             itself, which has to say the price, the length and the balance in one place —
             a button that spends somebody's tokens without showing them the number is a
             complaint waiting to happen. The store sells the same two tiers; this is the
             copy for somebody who is already waiting and wants out now. --}}
        @if ($priorityActive)
            {{-- The receipt: the same success callout the Economy page shows for it. --}}
            <x-filament::callout
                color="success"
                :heading="trans('plugged-host-queue::strings.priority_active_heading')"
                :description="trans('plugged-host-queue::strings.priority_active_body', ['remaining' => $priorityHuman])
                    . ($priorityAdFree ? ' ' . trans('plugged-host-queue::strings.priority_active_ad_free', ['remaining' => $priorityAdFreeHuman]) : '')"
            />
        @elseif ($offerShown)
            <x-filament::section>
                <div class="flex flex-col gap-5">
                    <div class="flex min-w-0 flex-col gap-1">
                        <span class="ph-queue-heading">{{ trans('plugged-host-queue::strings.priority_heading') }}</span>
                        <p class="ph-queue-muted">{{ trans('plugged-host-queue::strings.priority_body') }}</p>
                        <p class="ph-queue-small">
                            {{ trans('plugged-host-queue::strings.priority_balance', ['balance' => $balance]) }}
                            @if ($earnUrl)
                                <a href="{{ $earnUrl }}" class="ph-queue-link">
                                    {{ trans('plugged-host-queue::strings.priority_earn_link') }}
                                </a>
                            @endif
                        </p>
                    </div>

                    {{-- One full-width row per tier, cheapest first. The ad-free tier's
                         button is the filled one, because it is the one being upsold. --}}
                    <div class="ph-queue-rows flex flex-col">
                        @foreach ($priorityTiers as $tier)
                            <div class="ph-queue-row">
                                <div class="flex min-w-0 flex-col gap-0.5">
                                    <div class="flex flex-wrap items-baseline gap-x-2">
                                        <span class="ph-queue-title">
                                            {{ trans($tier['ad_free']
                                                ? 'plugged-host-queue::strings.priority_tier_ad_free'
                                                : 'plugged-host-queue::strings.priority_tier_standard') }}
                                        </span>
                                        <span class="ph-queue-small tabular-nums">
                                            {{ trans('plugged-host-queue::strings.priority_tier_days', ['days' => $tier['days']]) }}
                                        </span>
                                    </div>
                                    <p class="ph-queue-small">
                                        {{ trans($tier['ad_free']
                                            ? 'plugged-host-queue::strings.priority_tier_no_ads_note'
                                            : 'plugged-host-queue::strings.priority_tier_ads_note') }}
                                    </p>
                                </div>

                                <div class="ph-queue-action">
                                    <x-filament::button wire:click="buyPriority('{{ $tier['key'] }}')"
                                                        wire:loading.attr="disabled"
                                                        :disabled="$tier['unaffordable']"
                                                        :color="($tier['ad_free'] || count($priorityTiers) === 1) ? 'primary' : 'gray'"
                                                        class="w-full justify-center">
                                        {{ trans('plugged-host-queue::strings.priority_cta', ['cost' => $tier['cost']]) }}
                                    </x-filament::button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </x-filament::section>
        @endif
        {{-- The other way out. Confirmed rather than one-click, because it changes the
             server's IP and port and takes it offline while the files copy — the sort of
             thing a user must be told before they agree to it, not after. The node name is
             a label only; the click re-runs the whole search server-side. --}}
        @if ($transferNode)
            <x-filament::section>
                <div class="ph-queue-row">
                    <div class="flex min-w-0 flex-col gap-1">
                        <span class="ph-queue-heading">{{ trans('plugged-host-queue::strings.transfer_heading') }}</span>
                        <p class="ph-queue-muted">
                            {{ trans('plugged-host-queue::strings.transfer_body', ['node' => $transferNode]) }}
                        </p>
                        <p class="ph-queue-small">{{ trans('plugged-host-queue::strings.transfer_note') }}</p>
                    </div>

                    <div class="ph-queue-action">
                        <x-filament::button wire:click="takeTransfer"
                                            wire:confirm="{{ trans('plugged-host-queue::strings.transfer_confirm', ['node' => $transferNode]) }}"
                                            wire:loading.attr="disabled"
                                            color="gray"
                                            class="w-full justify-center">
                            {{ trans('plugged-host-queue::strings.transfer_cta') }}
                        </x-filament::button>
                    </div>
                </div>
            </x-filament::section>
        @elseif ($transferTooBig)
            <p class="ph-queue-small">{{ trans('plugged-host-queue::strings.transfer_too_big') }}</p>
        @endif

        {{-- A Direct Link is an ordinary URL that pays per landing, so it cannot be a unit
             and cannot be sandboxed. It is a link the user chooses to click: target=_blank
             so the wait keeps running in this tab, rel=noopener so the advertiser's page
             cannot reach window.opener, nofollow/sponsored because that is what a paid link
             is. Off unless plugged-host-ads has one configured. --}}
        @if ($directLink)
            <x-filament::section>
                <div class="ph-queue-row">
                    <div class="flex min-w-0 flex-col gap-1">
                        <span class="ph-queue-heading">{{ trans('plugged-host-queue::strings.direct_heading') }}</span>
                        <p class="ph-queue-muted">{{ trans('plugged-host-queue::strings.direct_body') }}</p>
                    </div>

                    <div class="ph-queue-action">
                        <x-filament::button tag="a"
                                            :href="$directLink"
                                            target="_blank"
                                            rel="noopener noreferrer nofollow sponsored"
                                            :color="$offerShown ? 'gray' : 'primary'"
                                            class="w-full justify-center">
                            {{ trans('plugged-host-queue::strings.direct_cta') }}
                        </x-filament::button>
                    </div>
                </div>
            </x-filament::section>
        @endif
        @if ($hasAdPartial && $unitsBelow !== [])
            @include('plugged-host-ads::filament.interstitial-units', ['units' => $unitsBelow, 'sandbox' => $sandbox])
        @endif

        {{-- Leaving properly. Quiet, at the bottom, and a link rather than a button: it is
             the one control here that gives something up. The place goes to whoever is
             behind them instead of sitting in the line until the sweep notices the
             silence. --}}
        <div class="flex flex-col items-center gap-1 pb-2 text-center">
            @if ($this->canLeave())
                <x-filament::link tag="button"
                                  color="gray"
                                  wire:click="leave"
                                  wire:loading.attr="disabled">
                    {{ trans('plugged-host-queue::strings.leave_cta') }}
                </x-filament::link>
            @endif
            @if ($passMinutes > 0)
                <span class="ph-queue-faint">
                    {{ trans('plugged-host-queue::strings.pass_note', ['minutes' => $passMinutes]) }}
                </span>
            @endif
        </div>
    </div>
</x-filament-panels::page>
