<x-filament-panels::page
    id="form"
    :wire:key="$this->getId() . '.forms.' . $this->getFormStatePath()">

    <style>
        .phc-page { display: flex; flex-direction: column; gap: 1.5rem; }
        .phc-page .fi-section { scroll-margin-top: 5rem; }
        .phc-deploy { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: .75rem; }
        .phc-deploy-reason { font-size: .8125rem; color: var(--danger-400); }
        .phc-deploy-busy { display: inline-flex; align-items: center; gap: .5rem; }
        @media (max-width: 640px) {
            .phc-deploy { flex-direction: column; align-items: stretch; }
            .phc-deploy > .fi-btn { justify-content: center; }
        }
    </style>

    <div class="phc-page">
        {{-- Single scrollable form containing Game, Location, Plan, and Details sections --}}
        {{ $this->form }}

        {{-- Deploy Action --}}
        @php
            $canDeploy = $this->canDeploy();
            $disabledReason = $this->getDeployDisabledReason();
        @endphp

        <div class="phc-deploy">
            @if (! $canDeploy && $disabledReason)
                <span class="phc-deploy-reason">{{ $disabledReason }}</span>
            @endif

            <x-filament::button
                wire:click="save"
                :color="$canDeploy ? 'primary' : 'gray'"
                :disabled="! $canDeploy"
                wire:loading.attr="disabled"
                :title="(! $canDeploy && $disabledReason) ? $disabledReason : null"
            >
                <span wire:loading.remove wire:target="save">{{ trans('plugged-host-servers::strings.deploy_server') }}</span>

                <span wire:loading wire:target="save" class="phc-deploy-busy">
                    <x-filament::loading-indicator class="h-5 w-5" />
                    <span>{{ trans('plugged-host-servers::strings.deploying') }}</span>
                </span>
            </x-filament::button>
        </div>

        {{-- Ad slot under creation form --}}
        {{-- The component lives in plugged-host-ads; its view namespace only exists while ads is enabled. --}}
        @if (\PluggedHost\Core\Support\Plugins::enabled('plugged-host-ads'))
            <x-plugged-host-ads::slot name="create-under-plan" />
        @endif
    </div>
</x-filament-panels::page>
