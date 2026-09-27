{{--
    The first-login welcome tour (PluggedHost\Core\Filament\App\Pages\WelcomeTourPage).

    Rendered in the simple layout, so this view is a single card under the panel logo with no
    navigation anywhere on screen -- that absence is what makes step 1 unskippable without
    having to disable anything. The simple layout already *is* the card, so the content sits
    in it directly rather than in a section of its own (a card inside a card).

    The step body is keyed by $step. Livewire's morph therefore *replaces* that element on
    every transition rather than patching it in place, which restarts the CSS animation below
    -- no Alpine state, nothing to keep in sync with the server. The <style> lives here in the
    body rather than at STYLES_BEFORE for the reason plugged-host.css documents: wire:navigate
    never garbage-collects a <style> it added to <head>, and a page-scoped rule left behind
    once hid the profile page's tab strip.

    Colours are theme v2 tokens only. The step's icon is deliberately not drawn: the heading
    already says what the step is about, and a glyph in a tinted circle is decoration.
--}}
<x-filament-panels::page.simple>
    <style>
        .ph-tour-step {
            animation: ph-tour-step-in 260ms ease-out both;
        }

        @keyframes ph-tour-step-in {
            from { opacity: 0; transform: translateY(0.5rem); }
            to { opacity: 1; transform: none; }
        }

        .ph-tour-dot {
            display: block;
            height: 0.375rem;
            width: 0.375rem;
            border-radius: 9999px;
            background-color: var(--phv2-raise-2);
            transition: width 200ms ease, background-color 200ms ease;
        }

        .ph-tour-dot-current {
            width: 1.5rem;
            background-color: var(--phv2-accent-bright);
        }

        .ph-tour-progress {
            font-size: 0.75rem;
            line-height: 1rem;
            font-variant-numeric: tabular-nums;
            color: var(--phv2-text-muted);
        }

        .ph-tour-body {
            font-size: 0.875rem;
            line-height: 1.625;
            color: var(--phv2-text-muted);
        }

        .ph-tour-bullets {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            padding-inline-start: 1.25rem;
            list-style: disc;
            font-size: 0.875rem;
            line-height: 1.625;
            color: var(--phv2-text-nav);
        }

        .ph-tour-bullets > li::marker {
            color: var(--phv2-text-faint);
        }

        @media (prefers-reduced-motion: reduce) {
            .ph-tour-step { animation: none; }
            .ph-tour-dot { transition: none; }
        }
    </style>

    @php
        $current = $this->currentStep();
        $count = $this->stepCount();
    @endphp

    <div class="flex flex-col gap-6">
        {{-- Progress: a dot per step, the current one widened into a bar. --}}
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-1.5" role="presentation">
                @for ($i = 1; $i <= $count; $i++)
                    <span
                        @class([
                            'ph-tour-dot',
                            'ph-tour-dot-current' => $i === $step,
                        ])
                    ></span>
                @endfor
            </div>

            <span class="ph-tour-progress">
                {{ trans('plugged-host-core::strings.tour.progress', ['step' => $step, 'total' => $count]) }}
            </span>
        </div>

        <div class="ph-tour-step flex flex-col gap-4" wire:key="ph-tour-step-{{ $step }}">
            <div class="flex flex-col gap-2">
                <h2 class="ph-heading">
                    {{ $current['heading'] }}
                </h2>

                <p class="ph-tour-body">
                    {{ $current['body'] }}
                </p>
            </div>

            @if ($current['bullets'] !== [])
                <ul class="ph-tour-bullets">
                    @foreach ($current['bullets'] as $bullet)
                        <li>{{ $bullet }}</li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- Step 1 offers exactly one button on purpose: it is the acknowledgement the
             gate waits for, so there must be nothing else to click. Wizard order: going
             back on the left, the one primary action on the right. --}}
        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                @unless ($this->isFirstStep())
                    <x-filament::button
                        wire:click="back"
                        color="gray"
                    >
                        {{ trans('plugged-host-core::strings.tour.back') }}
                    </x-filament::button>

                    @unless ($this->isLastStep())
                        <x-filament::link
                            tag="button"
                            wire:click="skipRest"
                            color="gray"
                            size="sm"
                        >
                            {{ trans('plugged-host-core::strings.tour.skip') }}
                        </x-filament::link>
                    @endunless
                @endunless
            </div>

            <div class="flex">
                @if ($this->isFirstStep())
                    <x-filament::button wire:click="next" class="w-full sm:w-auto">
                        {{ trans('plugged-host-core::strings.tour.acknowledge') }}
                    </x-filament::button>
                @elseif ($this->isLastStep())
                    <x-filament::button wire:click="finish" class="w-full sm:w-auto">
                        {{ trans('plugged-host-core::strings.tour.finish') }}
                    </x-filament::button>
                @else
                    <x-filament::button wire:click="next" class="w-full sm:w-auto">
                        {{ trans('plugged-host-core::strings.tour.next') }}
                    </x-filament::button>
                @endif
            </div>
        </div>
    </div>
</x-filament-panels::page.simple>
