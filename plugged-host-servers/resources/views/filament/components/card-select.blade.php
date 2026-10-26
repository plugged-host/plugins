@php
    $statePath = $getStatePath();
    $isDisabled = $isDisabled();
    $options = $getCardOptions();
    $state = $getState();
    $isCentered = $isCentered();
    // Cards the browser times (locations: their nodes' addresses), value => addresses.
    $probes = collect($options)
        ->filter(fn (array $option) => $option['probes'] !== [])
        ->mapWithKeys(fn (array $option) => [$option['value'] => $option['probes']])
        ->all();

    // Literal class strings so Tailwind's scanner picks them up — a computed
    // "grid-cols-{$n}" would never be generated.
    $gridClass = match ($getCardColumns()) {
        1 => 'grid-cols-1',
        2 => 'grid-cols-1 sm:grid-cols-2',
        4 => 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-4',
        default => 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3',
    };
@endphp

<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    {{-- Flat radio cards on the theme tokens: a sunken well, a hairline-free idle state, the
         accent border + soft fill when selected. No ring, no shadow, no hover lift. Scoped
         plain CSS so it needs no asset build. --}}
    <style>
        .phcs-card { position: relative; display: flex; gap: .75rem; padding: 1rem; border-radius: .75rem; border: 1px solid transparent; background: var(--phv2-sunken); color: var(--phv2-text); user-select: none; transition: border-color 150ms ease, background-color 150ms ease; }
        .phcs-card.is-clickable { cursor: pointer; }
        .phcs-card.is-hoverable:hover { border-color: var(--phv2-accent-edge); background: var(--phv2-raise); }
        .phcs-card.is-selected { border-color: var(--phv2-accent-bright); background: var(--phv2-accent-soft); }
        .phcs-card.is-disabled { cursor: not-allowed; opacity: .5; }
        .phcs-centered { height: 100%; flex-direction: column; align-items: center; text-align: center; gap: .625rem; }
        .phcs-row { align-items: flex-start; }
        .phcs-logo { display: flex; width: 3rem; height: 3rem; flex: none; align-items: center; justify-content: center; color: var(--phv2-text-muted); }
        .phcs-logo img { width: 2.5rem; height: 2.5rem; border-radius: .5rem; object-fit: contain; }
        .phcs-logo svg { width: 2rem; height: 2rem; }
        .phcs-body { display: flex; min-width: 0; flex: 1; flex-direction: column; gap: .125rem; }
        .phcs-label { font-size: .875rem; font-weight: 600; line-height: 1.25rem; color: var(--phv2-text); }
        .phcs-card.has-signal .phcs-label { padding-right: 4.5rem; }
        .phcs-desc { font-size: .75rem; line-height: 1.125rem; color: var(--phv2-text-muted); }
        .phcs-centered .phcs-desc { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .phcs-meta { margin-top: .125rem; font-size: .75rem; font-weight: 500; color: var(--phv2-text-nav); font-variant-numeric: tabular-nums; }
        .phcs-flag { display: flex; width: 1.5rem; height: 1rem; flex: none; margin-top: .125rem; overflow: hidden; border-radius: .125rem; outline: 1px solid var(--phv2-hairline); outline-offset: -1px; }
        .phcs-flag > svg, .phcs-flag > img { width: 100%; height: 100%; object-fit: cover; }
        .phcs-icon { width: 1.25rem; height: 1.25rem; flex: none; margin-top: .125rem; color: var(--phv2-text-muted); }
        .phcs-signal { pointer-events: none; position: absolute; top: .75rem; right: .75rem; display: inline-flex; align-items: flex-end; gap: 3px; }
        .phcs-signal-ms { margin-right: .25rem; align-self: center; font-size: .6875rem; font-weight: 600; line-height: 1; font-variant-numeric: tabular-nums; }
        .phcs-signal-ms span { font-weight: 400; color: var(--phv2-text-muted); }
        .phcs-signal-ms b { font: inherit; }
        .phcs-signal-bar { width: 3px; border-radius: 1px; background: var(--phv2-raise-2); }
        .phcs-tier { margin-top: .5rem; display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
        .phcs-badge { display: inline-flex; align-items: center; gap: .25rem; padding: .125rem .5rem; border-radius: .5rem; background: var(--phv2-raise-2); font-size: .6875rem; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: var(--phv2-text-nav); }
        .phcs-badge-info { display: inline-flex; cursor: help; color: var(--phv2-text-muted); }
        .phcs-badge-info svg { width: .75rem; height: .75rem; }
        .phcs-unlock { display: inline-flex; align-items: center; gap: .375rem; min-height: 1.75rem; padding: 0 .75rem; border-radius: 9999px; background: var(--phv2-raise-2); font-size: .75rem; font-weight: 600; color: var(--phv2-text); transition: background-color 150ms ease; }
        .phcs-unlock:hover { background: var(--phv2-raise-3); }
        .phcs-unlock-cost { display: inline-flex; align-items: center; gap: .25rem; color: var(--phv2-text-nav); font-variant-numeric: tabular-nums; }
        .phcs-unlock-cost svg { width: .875rem; height: .875rem; }
        .phcs-note { font-size: .75rem; color: var(--phv2-text-muted); }
    </style>

    <div
        x-data="{
            state: $wire.{{ $applyStateBindingModifiers("\$entangle('{$statePath}')") }},
            pings: {},
            picked: false,
            select(val) {
                this.state = val;
                this.picked = true;
            },
            {{-- The browser's own ping to each timed card (window.phLocationPings), then the
                 closest card that can be picked, unless the visitor picked one first. --}}
            init() {
                const probes = @js((object) $probes);
                const codes = Object.keys(probes);
                if (codes.length === 0) return;
                const start = (tries) => {
                    if (!window.phLocationPings) {
                        if (tries > 0) setTimeout(() => start(tries - 1), 250);
                        return;
                    }
                    Promise.all(codes.map(async (code) => {
                        const ms = await window.phLocationPings.measure(probes[code]);
                        if (ms !== null) this.pings[code] = ms;
                    })).then(() => this.pickClosest());
                };
                start(20);
            },
            pickClosest() {
                if (this.picked) return;
                let best = null;
                this.$root.querySelectorAll('[data-phcs-selectable]').forEach((card) => {
                    const ms = this.pings[card.dataset.phcsValue];
                    if (ms !== undefined && (best === null || ms < best.ms)) best = { value: card.dataset.phcsValue, ms };
                });
                if (best !== null && best.value !== this.state) this.state = best.value;
            },
        }"
        class="grid gap-3 {{ $gridClass }}"
    >
        @foreach ($options as $option)
            @php
                $isLocked = $option['locked'] ?? false;
                $tier = $option['tier'];
                // WP13 tier pill: one neutral badge per tier. The tier is a category, not a
                // status, so it takes no colour of its own.
                $hasTierPill = in_array($tier, ['premium', 'plus', 'free'], true);
                // A locked Plus/Premium card is inert (its own unlock button drives selection
                // later), not a plain disabled option; everything else follows the normal rule.
                $optionDisabled = $isDisabled || $option['disabled'];
                $radioDisabled = $optionDisabled || $isLocked;
                $isSelected = (string) $state === $option['value'];
            @endphp

            @if ($isCentered)
                {{-- Centered card layout (used for games/eggs) --}}
                <label
                    @click="if (!{{ $radioDisabled ? 'true' : 'false' }}) select('{{ $option['value'] }}')"
                    class="phcs-card phcs-centered {{ $radioDisabled ? ($optionDisabled && !$isLocked ? 'is-disabled' : '') : 'is-clickable' }} {{ $isSelected && !$radioDisabled ? 'is-selected' : '' }}"
                    :class="{
                        'is-selected': state == '{{ $option['value'] }}' && !{{ $radioDisabled ? 'true' : 'false' }},
                        'is-hoverable': state != '{{ $option['value'] }}' && !{{ $radioDisabled ? 'true' : 'false' }}
                    }"
                >
                    <input
                        type="radio"
                        name="{{ $getId() }}"
                        value="{{ $option['value'] }}"
                        x-model="state"
                        class="sr-only"
                        style="position: absolute; opacity: 0; width: 1px; height: 1px; pointer-events: none;"
                        @disabled($radioDisabled)
                        {{ $getExtraInputAttributeBag() }}
                    />

                    <span class="phcs-logo">
                        @if ($option['image'])
                            <img
                                src="{{ $option['image'] }}"
                                alt="{{ $option['label'] }}"
                                loading="lazy"
                            />
                        @elseif ($option['icon'])
                            <x-filament::icon :icon="$option['icon']" />
                        @endif
                    </span>

                    <span class="phcs-body">
                        <span class="phcs-label">
                            {{ $option['label'] }}
                        </span>

                        @if ($option['description'])
                            <span class="phcs-desc">
                                {{ $option['description'] }}
                            </span>
                        @endif
                    </span>
                </label>
            @else
                {{-- Standard horizontal card layout (used for locations and plans) --}}
                <label
                    @click="if (!{{ $radioDisabled ? 'true' : 'false' }}) select('{{ $option['value'] }}')"
                    data-phcs-value="{{ $option['value'] }}"
                    @if (!$radioDisabled) data-phcs-selectable @endif
                    class="phcs-card phcs-row {{ $option['signal'] || $option['probes'] !== [] ? 'has-signal' : '' }} {{ $radioDisabled ? ($optionDisabled && !$isLocked ? 'is-disabled' : '') : 'is-clickable' }} {{ $isSelected && !$radioDisabled ? 'is-selected' : '' }}"
                    :class="{
                        'is-selected': state == '{{ $option['value'] }}' && !{{ $radioDisabled ? 'true' : 'false' }},
                        'is-hoverable': state != '{{ $option['value'] }}' && !{{ $radioDisabled ? 'true' : 'false' }} && !{{ $isLocked ? 'true' : 'false' }}
                    }"
                >
                    <input
                        type="radio"
                        name="{{ $getId() }}"
                        value="{{ $option['value'] }}"
                        x-model="state"
                        x-on:change="picked = true"
                        @class(['fi-radio-input mt-0.5 shrink-0', 'hidden' => $isLocked])
                        @disabled($radioDisabled)
                        {{ $getExtraInputAttributeBag() }}
                    />

                    @if ($option['signal'])
                        {{-- WP3 latency as a Minecraft-style stepped signal meter: four bars of
                             rising height, filled up to the strength, coloured by quality (green →
                             amber → red). Inline colours so it renders without a Tailwind rebuild. --}}
                        @php
                            $sig = $option['signal'];
                            $signalColor = match ($sig['level'] ?? 'poor') {
                                'great' => 'var(--success-500)',
                                'good'  => 'var(--success-400)',
                                'ok'    => 'var(--warning-500)',
                                default => 'var(--danger-500)',
                            };
                        @endphp
                        <span
                            class="phcs-signal"
                            title="{{ $sig['ms'] }} ms"
                            aria-label="{{ $sig['ms'] }} ms latency"
                        >
                            <span class="phcs-signal-ms" style="color: {{ $signalColor }};">
                                {{ $sig['ms'] }}<span> ms</span>
                            </span>
                            @for ($i = 1; $i <= 4; $i++)
                                <span
                                    aria-hidden="true"
                                    class="phcs-signal-bar"
                                    style="height: {{ 3 + $i * 2 }}px; {{ $i <= ($sig['bars'] ?? 0) ? 'background: ' . $signalColor . ';' : '' }}"
                                ></span>
                            @endfor
                        </span>
                    @elseif ($option['probes'] !== [])
                        {{-- The same meter from the browser's own ping (init() above); hidden
                             until a node answers. Only Alpine draws it, so morphs leave it be. --}}
                        @php $ping = 'pings[' . \Illuminate\Support\Js::from($option['value']) . ']'; @endphp
                        <span
                            wire:ignore
                            class="phcs-signal"
                            style="display: none;"
                            x-show="{{ $ping }} !== undefined"
                            x-bind:title="{{ $ping }} + ' ms'"
                            x-bind:aria-label="{{ $ping }} + ' ms latency'"
                        >
                            <span class="phcs-signal-ms" x-bind:style="{ color: window.phLocationPings?.color({{ $ping }}) }">
                                <b x-text="{{ $ping }}"></b><span> ms</span>
                            </span>
                            @for ($i = 1; $i <= 4; $i++)
                                <span
                                    aria-hidden="true"
                                    class="phcs-signal-bar"
                                    style="height: {{ 3 + $i * 2 }}px;"
                                    x-bind:style="(window.phLocationPings?.bars({{ $ping }}) ?? 0) >= {{ $i }} ? { background: window.phLocationPings.color({{ $ping }}) } : {}"
                                ></span>
                            @endfor
                        </span>
                    @endif

                    @if ($option['svg'])
                        {{-- Inline vendored flag SVG (WP3). Trusted content — flag-icons files,
                             not user input — so raw output is safe. No public/ dependency, no
                             flag-icons `.fi-*` CSS to collide with Filament. --}}
                        <span aria-hidden="true" class="phcs-flag">{!! $option['svg'] !!}</span>
                    @elseif ($option['image'])
                        {{-- Self-hosted SVG, not a flag-icons CSS class: flag-icons and
                             Filament both own the `.fi-*` prefix. --}}
                        <span aria-hidden="true" class="phcs-flag">
                            <img src="{{ $option['image'] }}" alt="" loading="lazy" />
                        </span>
                    @elseif ($option['icon'])
                        <x-filament::icon :icon="$option['icon']" class="phcs-icon" />
                    @endif

                    <span class="phcs-body">
                        <span class="phcs-label">
                            {{ $option['label'] }}
                        </span>

                        @if ($option['description'])
                            <span class="phcs-desc">
                                {{ $option['description'] }}
                            </span>
                        @endif

                        @if ($option['meta'])
                            <span class="phcs-meta">
                                {{ $option['meta'] }}
                            </span>
                        @endif

                        @if ($hasTierPill)
                            {{-- WP13 tier pill. Stays on unlocked Plus/Premium cards too, so the
                                 tier remains visible. The (i) shows the tier's uptime on hover or
                                 focus; clicking it does not select the card. A locked card's
                                 button opens the Token Store: Plus for Plus locations, PlusPlus for
                                 Premium ones. --}}
                            <span class="phcs-tier">
                                <span class="phcs-badge">
                                    {{ trans("plugged-host-servers::strings.tiers.{$tier}") }}
                                    <span
                                        x-data
                                        x-tooltip="{
                                            content: @js(trans("plugged-host-servers::strings.uptime.{$tier}")),
                                            theme: $store.theme,
                                        }"
                                        x-on:click.prevent
                                        tabindex="0"
                                        role="img"
                                        aria-label="{{ trans("plugged-host-servers::strings.uptime.{$tier}") }}"
                                        class="phcs-badge-info"
                                    >
                                        <x-filament::icon icon="tabler-info-circle" />
                                    </span>
                                </span>

                                @if ($isLocked)
                                    <button
                                        type="button"
                                        wire:click="unlockPremium"
                                        @click.stop
                                        class="phcs-unlock"
                                    >
                                        <span>{{ trans($option['requires'] === 'plusplus' ? 'plugged-host-servers::strings.requires_plusplus' : 'plugged-host-servers::strings.requires_plus') }}</span>
                                    </button>
                                @elseif ($tier === 'free')
                                    <span class="phcs-note">
                                        {{ trans('plugged-host-servers::strings.free_tier_desc') }}
                                    </span>
                                @endif
                            </span>
                        @endif
                    </span>
                </label>
            @endif
        @endforeach
    </div>
</x-dynamic-component>
