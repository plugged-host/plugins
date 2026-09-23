@php
    $statePath = $getStatePath();
    $isDisabled = $isDisabled();
    $options = $getCardOptions();
    $state = $getState();
    $isCentered = $isCentered();

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
    <div
        x-data="{
            state: $wire.{{ $applyStateBindingModifiers("\$entangle('{$statePath}')") }},
            select(val) {
                this.state = val;
            }
        }"
        class="grid gap-3 {{ $gridClass }}"
    >
        @foreach ($options as $option)
            @php
                $isLocked = $option['locked'] ?? false;
                $isPremium = ($option['premium'] ?? null) === true;
                $hasTier = ($option['premium'] ?? null) !== null;
                // A locked premium card is inert (its own unlock button drives selection later),
                // not a plain disabled option; everything else follows the normal disabled rule.
                $optionDisabled = $isDisabled || $option['disabled'];
                $radioDisabled = $optionDisabled || $isLocked;
                $isSelected = (string) $state === $option['value'];
            @endphp

            @if ($isCentered)
                {{-- Centered card layout (used for games/eggs) --}}
                <label
                    @click="if (!{{ $radioDisabled ? 'true' : 'false' }}) select('{{ $option['value'] }}')"
                    class="group relative flex h-full flex-col items-center text-center rounded-2xl border bg-white p-4 transition-all duration-150 ease-out dark:bg-gray-900 select-none {{ $radioDisabled ? ($optionDisabled && !$isLocked ? 'cursor-not-allowed border-gray-200 opacity-50 dark:border-white/10' : '') : 'cursor-pointer' }} {{ $isSelected && !$radioDisabled ? 'border-primary-500 ring-2 ring-primary-500 shadow-md shadow-primary-500/10 bg-primary-50/5 dark:bg-primary-950/20 dark:border-primary-400 dark:ring-primary-400 scale-[1.01]' : 'border-gray-200 dark:border-white/10' }}"
                    :class="{
                        'border-primary-500 ring-2 ring-primary-500 shadow-md shadow-primary-500/10 bg-primary-50/5 dark:bg-primary-950/20 dark:border-primary-400 dark:ring-primary-400 scale-[1.01]': state == '{{ $option['value'] }}' && !{{ $radioDisabled ? 'true' : 'false' }},
                        'border-gray-200 hover:border-primary-400 hover:-translate-y-0.5 hover:shadow-md dark:border-white/10 dark:hover:border-primary-500': state != '{{ $option['value'] }}' && !{{ $radioDisabled ? 'true' : 'false' }}
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

                    <span
                        x-show="state == '{{ $option['value'] }}' && !{{ $radioDisabled ? 'true' : 'false' }}"
                        x-cloak
                        @if (! $isSelected) style="display: none;" @endif
                        class="pointer-events-none absolute right-2.5 top-2.5 flex h-5 w-5 items-center justify-center rounded-full bg-primary-500 text-white shadow-sm transition-all duration-150"
                    >
                        <x-filament::icon icon="tabler-check" class="h-3.5 w-3.5" />
                    </span>

                    <div class="flex h-12 w-12 items-center justify-center transition-transform duration-150 group-hover:scale-105 shrink-0">
                        @if ($option['image'])
                            <img
                                src="{{ $option['image'] }}"
                                alt="{{ $option['label'] }}"
                                class="h-10 w-10 rounded-lg object-contain"
                                loading="lazy"
                            />
                        @elseif ($option['icon'])
                            <x-filament::icon :icon="$option['icon']" class="h-8 w-8 text-gray-400 transition group-hover:text-primary-500 dark:group-hover:text-primary-400" />
                        @endif
                    </div>

                    <div class="mt-2.5 flex flex-1 flex-col items-center justify-start w-full space-y-0.5">
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white leading-tight">
                            {{ $option['label'] }}
                        </h3>

                        @if ($option['description'])
                            <p class="line-clamp-2 text-xs text-gray-500 dark:text-gray-400 leading-snug">
                                {{ $option['description'] }}
                            </p>
                        @endif
                    </div>
                </label>
            @else
                {{-- Standard horizontal card layout (used for locations and plans) --}}
                <label
                    @click="if (!{{ $radioDisabled ? 'true' : 'false' }}) select('{{ $option['value'] }}')"
                    class="group relative flex items-start gap-3 rounded-xl border bg-white p-4 transition-all duration-150 ease-out dark:bg-gray-900 select-none {{ $radioDisabled ? ($optionDisabled && !$isLocked ? 'cursor-not-allowed border-gray-200 opacity-50 dark:border-white/10' : ($isLocked ? 'border-primary-400/40 dark:border-primary-400/30' : '')) : 'cursor-pointer' }} {{ $isSelected && !$radioDisabled ? 'border-primary-500 ring-2 ring-primary-500 shadow-sm shadow-primary-500/10 dark:border-primary-400 dark:ring-primary-400' : 'border-gray-200 dark:border-white/10' }}"
                    :class="{
                        'border-primary-500 ring-2 ring-primary-500 shadow-sm shadow-primary-500/10 dark:border-primary-400 dark:ring-primary-400': state == '{{ $option['value'] }}' && !{{ $radioDisabled ? 'true' : 'false' }},
                        'border-gray-200 hover:border-primary-400 hover:-translate-y-0.5 hover:shadow-md dark:border-white/10 dark:hover:border-primary-500': state != '{{ $option['value'] }}' && !{{ $radioDisabled ? 'true' : 'false' }} && !{{ $isLocked ? 'true' : 'false' }}
                    }"
                >
                    <input
                        type="radio"
                        name="{{ $getId() }}"
                        value="{{ $option['value'] }}"
                        x-model="state"
                        @class(['mt-0.5 shrink-0', 'hidden' => $isLocked])
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
                                'great' => '#22c55e',
                                'good'  => '#4ade80',
                                'ok'    => '#eab308',
                                default => '#ef4444',
                            };
                        @endphp
                        <span
                            class="pointer-events-none absolute right-2.5 top-2.5 inline-flex items-end gap-[3px]"
                            title="{{ $sig['ms'] }} ms"
                            aria-label="{{ $sig['ms'] }} ms latency"
                        >
                            <span class="mr-1 self-center text-[0.625rem] font-semibold tabular-nums leading-none" style="color: {{ $signalColor }};">
                                {{ $sig['ms'] }}<span class="font-normal" style="opacity: .55;"> ms</span>
                            </span>
                            @for ($i = 1; $i <= 4; $i++)
                                <span
                                    aria-hidden="true"
                                    style="width: 3px; height: {{ 3 + $i * 2 }}px; border-radius: 1px; background: {{ $i <= ($sig['bars'] ?? 0) ? $signalColor : 'rgb(148 163 184 / .3)' }};"
                                ></span>
                            @endfor
                        </span>
                    @endif

                    <span class="flex min-w-0 flex-1 items-start gap-3">
                        @if ($option['svg'])
                            {{-- Inline vendored flag SVG (WP3). Trusted content — flag-icons files,
                                 not user input — so raw output is safe. No public/ dependency, no
                                 flag-icons `.fi-*` CSS to collide with Filament. --}}
                            <span
                                aria-hidden="true"
                                class="mt-0.5 flex h-4 w-6 shrink-0 overflow-hidden rounded-sm shadow-sm ring-1 ring-gray-950/10 dark:ring-white/10 [&>svg]:h-full [&>svg]:w-full [&>svg]:object-cover"
                            >{!! $option['svg'] !!}</span>
                        @elseif ($option['image'])
                            {{-- Self-hosted SVG, not a flag-icons CSS class: flag-icons and
                                 Filament both own the `.fi-*` prefix. --}}
                            <img
                                src="{{ $option['image'] }}"
                                alt=""
                                aria-hidden="true"
                                class="mt-0.5 h-4 w-6 shrink-0 rounded-sm object-cover shadow-sm ring-1 ring-gray-950/10 dark:ring-white/10"
                                loading="lazy"
                            />
                        @elseif ($option['icon'])
                            <x-filament::icon :icon="$option['icon']" class="mt-0.5 h-5 w-5 shrink-0 text-gray-400" />
                        @endif

                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-semibold text-gray-950 dark:text-white">
                                {{ $option['label'] }}
                            </span>

                            @if ($option['description'])
                                <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">
                                    {{ $option['description'] }}
                                </span>
                            @endif

                            @if ($option['meta'])
                                <span class="mt-1 block text-xs font-medium text-gray-700 dark:text-gray-300">
                                    {{ $option['meta'] }}
                                </span>
                            @endif

                            @if ($isPremium)
                                {{-- WP13 Premium pill, in the WP2 accent-tint style. Stays on unlocked
                                     premium cards too, so the tier remains visible. --}}
                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[0.625rem] font-semibold uppercase tracking-wide"
                                          style="color: var(--phv2-accent-bright, #3b82f6); background: var(--phv2-accent-tint, rgb(29 78 216 / .12));">
                                        <x-filament::icon icon="tabler-star" class="h-3 w-3" />
                                        Premium
                                    </span>

                                    @if ($isLocked)
                                        <button
                                            type="button"
                                            wire:click="unlockPremium"
                                            @click.stop
                                            class="inline-flex items-center gap-1.5 rounded-lg bg-primary-500 px-2.5 py-1 text-xs font-semibold text-white transition hover:bg-primary-600"
                                        >
                                            <x-filament::icon icon="tabler-lock-open" class="h-3.5 w-3.5" />
                                            <span>{{ trans('plugged-host-servers::strings.unlock') }}</span>
                                            <span class="ml-0.5 inline-flex items-center gap-1 rounded-full bg-white/20 px-1.5 py-0.5">
                                                <x-filament::icon icon="tabler-coins" class="h-3 w-3" />
                                                <span class="tabular-nums">{{ $option['cost'] }}</span>
                                            </span>
                                        </button>
                                    @endif
                                </div>
                            @elseif ($hasTier)
                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[0.625rem] font-semibold uppercase tracking-wide"
                                          style="color: #34d399; background: rgb(16 185 129 / .12);">
                                        <x-filament::icon icon="tabler-circle-check" class="h-3 w-3" />
                                        {{ trans('plugged-host-servers::strings.free') }}
                                    </span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ trans('plugged-host-servers::strings.free_tier_desc') }}
                                    </span>
                                </div>
                            @endif
                        </span>
                    </span>
                </label>
            @endif
        @endforeach
    </div>
</x-dynamic-component>
