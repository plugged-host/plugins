{{--
    The first-login welcome tour (PluggedHost\Core\Filament\App\Pages\WelcomeTourPage).

    Rendered in the simple layout, so this view is a single card under the panel logo with no
    navigation anywhere on screen -- that absence is what makes step 1 unskippable without
    having to disable anything.

    The step body is keyed by $step. Livewire's morph therefore *replaces* that element on
    every transition rather than patching it in place, which restarts the CSS animation below
    -- no Alpine state, nothing to keep in sync with the server. The <style> lives here in the
    body rather than at STYLES_BEFORE for the reason plugged-host.css documents: wire:navigate
    never garbage-collects a <style> it added to <head>, and a page-scoped rule left behind
    once hid the profile page's tab strip.
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
            transition: width 200ms ease, background-color 200ms ease;
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

    <x-filament::section class="rounded-2xl">
        <div class="flex flex-col gap-6">
            {{-- Progress: a dot per step, the current one widened into a bar. --}}
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-1.5" role="presentation">
                    @for ($i = 1; $i <= $count; $i++)
                        <span
                            @class([
                                'ph-tour-dot h-1.5 rounded-full',
                                'w-6 bg-primary-600 dark:bg-primary-500' => $i === $step,
                                'w-1.5 bg-gray-300 dark:bg-gray-600' => $i !== $step,
                            ])
                        ></span>
                    @endfor
                </div>

                <span class="text-xs font-medium text-gray-400 dark:text-gray-500">
                    {{ trans('plugged-host-core::strings.tour.progress', ['step' => $step, 'total' => $count]) }}
                </span>
            </div>

            <div class="ph-tour-step flex flex-col gap-4" wire:key="ph-tour-step-{{ $step }}">
                <div class="flex items-start gap-4">
                    <div class="flex size-11 shrink-0 items-center justify-center rounded-full bg-gray-100 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                        <x-filament::icon :icon="$current['icon']" class="size-6 text-primary-600 dark:text-primary-400" />
                    </div>

                    <div class="flex min-w-0 flex-col gap-2">
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">
                            {{ $current['heading'] }}
                        </h2>

                        <p class="text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                            {{ $current['body'] }}
                        </p>
                    </div>
                </div>

                @if ($current['bullets'] !== [])
                    <ul class="flex flex-col gap-2 sm:pl-15">
                        @foreach ($current['bullets'] as $bullet)
                            <li class="flex items-start gap-2.5">
                                <x-filament::icon
                                    icon="tabler-circle-check"
                                    class="mt-0.5 size-4 shrink-0 text-primary-500 dark:text-primary-400"
                                />
                                <span class="text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                                    {{ $bullet }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Step 1 offers exactly one button on purpose: it is the acknowledgement the
                 gate waits for, so there must be nothing else to click. --}}
            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-1">
                    @unless ($this->isFirstStep())
                        <x-filament::button
                            wire:click="back"
                            color="gray"
                            icon="tabler-arrow-left"
                            size="sm"
                            outlined
                        >
                            {{ trans('plugged-host-core::strings.tour.back') }}
                        </x-filament::button>

                        @unless ($this->isLastStep())
                            <x-filament::link
                                tag="button"
                                wire:click="skipRest"
                                color="gray"
                                size="sm"
                                class="ms-2"
                            >
                                {{ trans('plugged-host-core::strings.tour.skip') }}
                            </x-filament::link>
                        @endunless
                    @endunless
                </div>

                <div class="flex">
                    @if ($this->isFirstStep())
                        <x-filament::button wire:click="next" icon="tabler-arrow-right" icon-position="after" class="w-full sm:w-auto">
                            {{ trans('plugged-host-core::strings.tour.acknowledge') }}
                        </x-filament::button>
                    @elseif ($this->isLastStep())
                        <x-filament::button wire:click="finish" icon="tabler-server" class="w-full sm:w-auto">
                            {{ trans('plugged-host-core::strings.tour.finish') }}
                        </x-filament::button>
                    @else
                        <x-filament::button wire:click="next" icon="tabler-arrow-right" icon-position="after" class="w-full sm:w-auto">
                            {{ trans('plugged-host-core::strings.tour.next') }}
                        </x-filament::button>
                    @endif
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page.simple>
