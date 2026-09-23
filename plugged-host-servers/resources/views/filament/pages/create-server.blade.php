<x-filament-panels::page
    id="form"
    :wire:key="$this->getId() . '.forms.' . $this->getFormStatePath()"
    wire:submit="save">

    <style>
        @keyframes ph-fade-slide {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes ph-pulse-glow {
            0%, 100% {
                box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.4);
            }
            50% {
                box-shadow: 0 0 0 8px rgba(59, 130, 246, 0);
            }
        }

        .ph-creation-page {
            position: relative;
        }

        /* Smooth transitions for all sections and cards */
        .ph-creation-page .fi-section {
            transition: all 0.25s ease-out;
            scroll-margin-top: 5rem;
        }

        .ph-creation-page .fi-section:hover {
            border-color: rgba(255, 255, 255, 0.12);
        }

        /* Subtle glow for selected items */
        .ph-deploy-btn:not(:disabled):hover .ph-rocket-icon {
            transform: translate(2px, -2px) scale(1.1);
        }
    </style>

    <div class="ph-creation-page space-y-6">
        {{-- Single scrollable form containing Game, Location, Plan, and Details sections --}}
        {{ $this->form }}

        {{-- Deploy Action --}}
        @php
            $canDeploy = $this->canDeploy();
            $disabledReason = $this->getDeployDisabledReason();
        @endphp

        <div class="flex flex-col sm:flex-row items-end sm:items-center justify-end gap-3 pt-2">
            @if (! $canDeploy && $disabledReason)
                <span class="inline-flex items-center gap-1.5 text-xs text-danger-600 dark:text-danger-400 font-medium">
                    <x-filament::icon icon="tabler-alert-circle" class="h-4 w-4 shrink-0" />
                    <span>{{ $disabledReason }}</span>
                </span>
            @endif

            <button
                type="submit"
                wire:loading.attr="disabled"
                @disabled(! $canDeploy)
                @if (! $canDeploy && $disabledReason)
                    title="{{ $disabledReason }}"
                @endif
                @class([
                    'ph-deploy-btn inline-flex items-center justify-center gap-2 rounded-xl px-6 py-3 text-sm font-semibold transition-all duration-200 select-none',
                    'bg-gray-200 text-gray-400 cursor-not-allowed shadow-none dark:bg-gray-800 dark:text-gray-500 opacity-60' => ! $canDeploy,
                    'bg-primary-600 text-white shadow-lg shadow-primary-600/30 hover:bg-primary-500 hover:shadow-primary-500/50 hover:-translate-y-0.5 active:scale-[0.98]' => $canDeploy,
                ])
            >
                <span wire:loading.remove wire:target="save" class="inline-flex items-center gap-2">
                    <x-filament::icon icon="tabler-rocket" class="ph-rocket-icon h-5 w-5 transition-transform duration-200" />
                    <span>{{ trans('plugged-host-servers::strings.deploy_server') }}</span>
                </span>

                <span wire:loading wire:target="save" class="inline-flex items-center gap-2">
                    <x-filament::loading-indicator class="h-5 w-5 text-white" />
                    <span>{{ trans('plugged-host-servers::strings.deploying') }}</span>
                </span>
            </button>
        </div>

        {{-- Ad slot under creation form --}}
        <x-plugged-host-ads::slot name="create-under-plan" />
    </div>
</x-filament-panels::page>
