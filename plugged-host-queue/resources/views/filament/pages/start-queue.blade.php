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

        .ph-queue-track { height: 0.375rem; border-radius: 999px; overflow: hidden; background: rgb(0 0 0 / 0.08); }
        .dark .ph-queue-track { background: rgb(255 255 255 / 0.1); }
        /* 1s, matching the client clock's tick, so the fill advances one visible second at
           a time. It used to be 5.1s to cover the poll interval, which meant the bar spent
           the whole wait easing toward a percentage that had been true five seconds ago —
           it never caught up, and "smooth" read as "lagging". */
        .ph-queue-fill { height: 100%; border-radius: 999px; transition: width 1s linear; }
        @media (prefers-reduced-motion: reduce) {
            .ph-queue-fill { transition: none; }
        }
        .ph-queue-meter { display: flex; flex-direction: column; gap: 0.375rem; }
        .ph-queue-bar { height: 0.25rem; border-radius: 999px; overflow: hidden; background: rgb(0 0 0 / 0.08); }
        .dark .ph-queue-bar { background: rgb(255 255 255 / 0.1); }
        .ph-queue-bar > span { display: block; height: 100%; border-radius: 999px; }
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
        <x-filament::section class="rounded-2xl">
            <div class="flex flex-col gap-5">
                <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-5">
                        <div class="flex size-14 shrink-0 items-center justify-center rounded-full bg-gray-100 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                            <x-filament::loading-indicator class="size-6 text-primary-600 dark:text-primary-400" />
                        </div>

                        <div class="flex min-w-0 flex-col gap-1.5">
                            <span class="truncate text-lg font-semibold tracking-tight text-gray-950 dark:text-white">
                                {{ trans('plugged-host-queue::strings.heading') }}
                            </span>
                            <p class="text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                                {{ trans('plugged-host-queue::strings.body_' . ($action === 'restart' ? 'restart' : 'start')) }}
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-col gap-1 sm:items-end sm:text-right">
                        <span class="text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-500">
                            {{ trans('plugged-host-queue::strings.position_label') }}
                        </span>
                        <span class="text-2xl font-semibold leading-none tabular-nums text-gray-950 dark:text-white">
                            @if ($position > 1)
                                {{ trans('plugged-host-queue::strings.position_value', ['position' => $position]) }}
                            @else
                                {{ trans('plugged-host-queue::strings.position_next') }}
                            @endif
                        </span>
                        @if ($priorityActive)
                            <span class="text-xs font-medium text-primary-600 dark:text-primary-400">
                                {{ trans('plugged-host-queue::strings.position_priority') }}
                            </span>
                        @endif
                    </div>
                </div>

                @php
                    $percent = $maxWait > 0 ? min(100, (int) round($elapsed / $maxWait * 100)) : 100;
                    $waited = sprintf('%d:%02d', intdiv($elapsed, 60), $elapsed % 60);
                @endphp
                <div class="ph-queue-track">
                    <div class="ph-queue-fill bg-primary-600 dark:bg-primary-500"
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
                <div class="flex flex-col gap-1 text-xs leading-relaxed text-gray-500 dark:text-gray-400 sm:flex-row sm:items-center sm:justify-between">
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
            <x-filament::section class="rounded-2xl">
                <div class="flex flex-col gap-4">
                    <p class="text-sm leading-relaxed text-gray-500 dark:text-gray-400">
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
                                <div class="flex items-baseline justify-between gap-2 text-xs">
                                    <span class="font-medium text-gray-500 dark:text-gray-400">
                                        {{ trans('plugged-host-queue::strings.usage_' . $dimension) }}
                                    </span>
                                    <span class="tabular-nums font-semibold {{ $over ? 'text-danger-600 dark:text-danger-400' : 'text-gray-950 dark:text-white' }}">
                                        {{ $shown === null ? '—' : number_format($shown, 1) . '%' }}
                                    </span>
                                </div>
                                <div class="ph-queue-bar">
                                    <span class="{{ $over ? 'bg-danger-500' : 'bg-primary-500' }}"
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
            <x-filament::section class="rounded-2xl">
                <div class="flex flex-col gap-2">
                    <span class="text-base font-semibold tracking-tight text-gray-950 dark:text-white">
                        {{ trans('plugged-host-queue::strings.priority_active_heading') }}
                    </span>
                    <p class="text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                        {{ trans('plugged-host-queue::strings.priority_active_body', ['remaining' => $priorityHuman]) }}
                    </p>
                    @if ($priorityAdFree)
                        <p class="text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                            {{ trans('plugged-host-queue::strings.priority_active_ad_free', ['remaining' => $priorityAdFreeHuman]) }}
                        </p>
                    @endif
                </div>
            </x-filament::section>
        @elseif ($priorityAvailable && filled($priorityTiers))
            <x-filament::section class="rounded-2xl">
                <div class="flex flex-col gap-4">
                    <div class="flex min-w-0 flex-col gap-1.5">
                        <span class="text-base font-semibold tracking-tight text-gray-950 dark:text-white">
                            {{ trans('plugged-host-queue::strings.priority_heading') }}
                        </span>
                        <p class="text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                            {{ trans('plugged-host-queue::strings.priority_body') }}
                        </p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ trans('plugged-host-queue::strings.priority_balance', ['balance' => $balance]) }}
                            @if ($earnUrl)
                                <a href="{{ $earnUrl }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                                    {{ trans('plugged-host-queue::strings.priority_earn_link') }}
                                </a>
                            @endif
                        </p>
                    </div>

                    {{-- One tile per tier, cheapest first. Two columns on anything wider
                         than a phone; the ad-free tile is ringed rather than merely
                         labelled, because it is the one being upsold. --}}
                    <div @class(['grid grid-cols-1 gap-3', 'sm:grid-cols-2' => count($priorityTiers) > 1])>
                        @foreach ($priorityTiers as $tier)
                            <div @class([
                                'flex h-full flex-col gap-3 rounded-xl p-4',
                                'ring-2 ring-info-500/60 dark:ring-info-400/50' => $tier['ad_free'],
                                'ring-1 ring-gray-950/5 dark:ring-white/10' => !$tier['ad_free'],
                            ])>
                                <div class="flex items-start justify-between gap-2">
                                    <div class="flex min-w-0 flex-col gap-0.5">
                                        <span class="truncate text-sm font-semibold text-gray-950 dark:text-white">
                                            {{ trans($tier['ad_free']
                                                ? 'plugged-host-queue::strings.priority_tier_ad_free'
                                                : 'plugged-host-queue::strings.priority_tier_standard') }}
                                        </span>
                                        <span class="text-lg font-bold tabular-nums tracking-tight text-gray-950 dark:text-white">
                                            {{ trans('plugged-host-queue::strings.priority_tier_days', ['days' => $tier['days']]) }}
                                        </span>
                                    </div>

                                    <x-filament::badge :color="$tier['ad_free'] ? 'info' : 'gray'" size="sm" class="shrink-0 rounded-full">
                                        {{ trans($tier['ad_free']
                                            ? 'plugged-host-queue::strings.priority_badge_no_ads'
                                            : 'plugged-host-queue::strings.priority_badge_ads') }}
                                    </x-filament::badge>
                                </div>

                                {{-- Grows so both tiles' buttons land on one baseline --}}
                                <p class="flex-1 text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                                    {{ trans($tier['ad_free']
                                        ? 'plugged-host-queue::strings.priority_tier_no_ads_note'
                                        : 'plugged-host-queue::strings.priority_tier_ads_note') }}
                                </p>

                                <x-filament::button wire:click="buyPriority('{{ $tier['key'] }}')"
                                                    wire:loading.attr="disabled"
                                                    :disabled="$tier['unaffordable']"
                                                    :color="$tier['ad_free'] ? 'info' : 'gray'"
                                                    class="w-full justify-center">
                                    {{ trans('plugged-host-queue::strings.priority_cta', ['cost' => $tier['cost']]) }}
                                </x-filament::button>
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
            <x-filament::section class="rounded-2xl">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex min-w-0 flex-col gap-1.5">
                        <span class="text-base font-semibold tracking-tight text-gray-950 dark:text-white">
                            {{ trans('plugged-host-queue::strings.transfer_heading') }}
                        </span>
                        <p class="text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                            {{ trans('plugged-host-queue::strings.transfer_body', ['node' => $transferNode]) }}
                        </p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ trans('plugged-host-queue::strings.transfer_note') }}
                        </p>
                    </div>

                    <x-filament::button wire:click="takeTransfer"
                                        wire:confirm="{{ trans('plugged-host-queue::strings.transfer_confirm', ['node' => $transferNode]) }}"
                                        wire:loading.attr="disabled"
                                        color="gray"
                                        class="shrink-0">
                        {{ trans('plugged-host-queue::strings.transfer_cta') }}
                    </x-filament::button>
                </div>
            </x-filament::section>
        @elseif ($transferTooBig)
            <p class="text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                {{ trans('plugged-host-queue::strings.transfer_too_big') }}
            </p>
        @endif

        {{-- A Direct Link is an ordinary URL that pays per landing, so it cannot be a unit
             and cannot be sandboxed. It is a link the user chooses to click: target=_blank
             so the wait keeps running in this tab, rel=noopener so the advertiser's page
             cannot reach window.opener, nofollow/sponsored because that is what a paid link
             is. Off unless plugged-host-ads has one configured. --}}
        @if ($directLink)
            <x-filament::section class="rounded-2xl">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex min-w-0 flex-col gap-1.5">
                        <span class="text-base font-semibold tracking-tight text-gray-950 dark:text-white">
                            {{ trans('plugged-host-queue::strings.direct_heading') }}
                        </span>
                        <p class="text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                            {{ trans('plugged-host-queue::strings.direct_body') }}
                        </p>
                    </div>

                    <a href="{{ $directLink }}"
                       target="_blank"
                       rel="noopener noreferrer nofollow sponsored"
                       class="fi-btn fi-btn-size-md inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 dark:bg-primary-500 dark:hover:bg-primary-400">
                        {{ trans('plugged-host-queue::strings.direct_cta') }}
                    </a>
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
            <button type="button"
                    wire:click="leave"
                    wire:loading.attr="disabled"
                    class="text-sm font-medium text-gray-500 underline-offset-2 hover:underline dark:text-gray-400">
                {{ trans('plugged-host-queue::strings.leave_cta') }}
            </button>
            @if ($passMinutes > 0)
                <span class="text-xs text-gray-400 dark:text-gray-500">
                    {{ trans('plugged-host-queue::strings.pass_note', ['minutes' => $passMinutes]) }}
                </span>
            @endif
        </div>
    </div>
</x-filament-panels::page>
