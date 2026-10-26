{{--
    Restore interstitial for a hibernated server. What happened, what it costs, what the owner
    has — and the one action, "Restore server", which is the page's header action. While the
    move back runs, the card says so instead and the page polls until it has settled.

    One card, the two figures side by side separated by whitespace (no inner box, no divider).
    Styles: `.ph-card`, `.ph-caption`, `.ph-figure` in plugged-host-core's plugged-host.css.
--}}
<x-filament-panels::page>
    @if ($restoring)
        <div class="ph-card flex flex-col gap-1" wire:poll.3s="checkRestore">
            <p class="ph-row-body">
                {{ trans('plugged-host-limbo::strings.restoring') }}
            </p>
        </div>
    @else
        <div class="ph-card flex flex-col gap-6">
            <div class="flex flex-col gap-1">
                <p class="ph-row-body">
                    {{ trans('plugged-host-limbo::strings.body') }}
                </p>
                @if ($enteredAt)
                    <p class="ph-row-body">
                        {{ trans('plugged-host-limbo::strings.entered', ['time' => $enteredAt]) }}
                    </p>
                @endif
                @if ($deletesAt)
                    <p class="ph-row-body">
                        {{ trans('plugged-host-limbo::strings.deletes_at', ['time' => $deletesAt]) }}
                    </p>
                @endif
            </div>

            <dl class="flex flex-wrap gap-8">
                <div class="flex flex-col gap-1">
                    <dt class="ph-caption">{{ trans('plugged-host-limbo::strings.cost_label') }}</dt>
                    <dd class="ph-figure font-mono">{{ trans('plugged-host-limbo::strings.tokens', ['count' => $cost]) }}</dd>
                </div>
                <div class="flex flex-col gap-1">
                    <dt class="ph-caption">{{ trans('plugged-host-limbo::strings.balance_label') }}</dt>
                    <dd class="ph-figure font-mono">{{ trans('plugged-host-limbo::strings.tokens', ['count' => $this->balance()]) }}</dd>
                </div>
            </dl>
        </div>

        @if ($needsLocation)
            {{-- The row styles are the Transfer page's (plugged-host-servers server-transfer view), verbatim. --}}
            <style>
                .pht-h { font-size: 1rem; font-weight: 600; line-height: 1.5rem; color: var(--phv2-text); }
                .pht-flag { width: 1.75rem; height: 1.3125rem; flex: none; border-radius: .25rem; overflow: hidden; display: grid; place-items: center; background: var(--phv2-raise-2); outline: 1px solid var(--phv2-hairline); outline-offset: -1px; }
                .pht-flag svg { width: 100%; height: 100%; display: block; }
                .pht-list { display: flex; flex-direction: column; gap: .5rem; }
                .pht-row { display: flex; align-items: center; gap: .75rem; width: 100%; padding: .75rem 1rem; border-radius: .75rem; border: 1px solid transparent; background: var(--phv2-raise); color: var(--phv2-text); text-align: left; transition: border-color 150ms ease, background-color 150ms ease; }
                .pht-row:not(:disabled):hover { background: var(--phv2-raise-2); }
                .pht-row.is-selected, .pht-row.is-selected:not(:disabled):hover { border-color: var(--phv2-accent-bright); background: var(--phv2-accent-soft); }
                .pht-row-main { flex: 1; min-width: 0; display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
                .pht-row-main strong { font-size: .875rem; font-weight: 600; }
                .pht-ms { flex: none; font-size: .75rem; color: var(--phv2-text-muted); font-variant-numeric: tabular-nums; }
                .pht-radio { width: 1.125rem; height: 1.125rem; flex: none; border-radius: 9999px; border: 1px solid var(--phv2-raise-3); }
                .pht-row.is-selected .pht-radio { border: .3125rem solid var(--phv2-accent-bright); }
            </style>

            @php($locations = $this->locations())

            <section class="flex flex-col gap-3">
                <div class="flex flex-col gap-1">
                    <h3 class="pht-h">{{ trans('plugged-host-limbo::strings.pick_title') }}</h3>
                    <p class="ph-row-body">
                        {{ trans($locations === [] ? 'plugged-host-limbo::strings.pick_none' : 'plugged-host-limbo::strings.pick_body') }}
                    </p>
                </div>

                @if ($locations !== [])
                    <div class="pht-list">
                        @foreach ($locations as $loc)
                            <button type="button"
                                    class="pht-row {{ $location === $loc['code'] ? 'is-selected' : '' }}"
                                    wire:key="phl-loc-{{ $loc['code'] }}"
                                    wire:click="pick(@js($loc['code']))">
                                <span class="pht-flag">{!! $loc['svg'] !!}</span>
                                <span class="pht-row-main">
                                    <strong>{{ $loc['name'] }}</strong>
                                    @if (($loc['tier'] ?? 'free') !== 'free')
                                        <x-filament::badge color="gray" size="sm">{{ ucfirst($loc['tier']) }}</x-filament::badge>
                                    @endif
                                </span>
                                @if (($loc['probes'] ?? []) !== [])
                                    {{-- The visitor's own ping, timed by the browser (plugged-host-servers' window.phLocationPings). --}}
                                    <span
                                        wire:ignore
                                        class="pht-ms font-mono"
                                        style="display: none;"
                                        x-data="{ ms: null }"
                                        x-init="window.phLocationPings?.measure(@js($loc['probes'])).then((value) => ms = value)"
                                        x-show="ms !== null"
                                        x-text="@js(trans('plugged-host-limbo::strings.ms', ['ms' => ':ms'])).replace(':ms', ms)"
                                    ></span>
                                @endif
                                <span class="pht-radio" aria-hidden="true"></span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </section>
        @endif
    @endif
</x-filament-panels::page>
