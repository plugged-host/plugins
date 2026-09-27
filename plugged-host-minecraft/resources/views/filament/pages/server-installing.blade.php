{{--
    Waiting room for a server whose egg is still installing.
    See PluggedHost\Minecraft\Filament\Server\Pages\ServerInstallingPage for why there is no
    percentage here and why the poll drives the page rather than a websocket.

    wire:poll re-renders the whole page every few seconds, which is also what keeps the
    elapsed time current without any client-side clock.

    Styled by the scoped <style> below on theme-v2's --phv2-* tokens (no asset build). The
    few Tailwind utilities left (flex/gap) are compiled by
    plugins/plugged-host-core/resources/css/plugged-host.css.
--}}
<x-filament-panels::page>
    <style>
        .phi-status { display: flex; flex-direction: column; gap: 1.25rem; }
        .phi-main { display: flex; flex-direction: column; gap: .375rem; min-width: 0; }
        .phi-title { display: flex; align-items: center; gap: .625rem; min-width: 0; }
        .phi-spinner { width: 1.25rem; height: 1.25rem; flex: none; color: var(--phv2-accent-bright); }
        .phi-name { font-size: 1rem; font-weight: 600; color: var(--phv2-text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .phi-body { font-size: .875rem; line-height: 1.5; color: var(--phv2-text-muted); }
        .phi-side { display: flex; flex-wrap: wrap; align-items: center; gap: 1rem; flex: none; }
        .phi-caption { font-size: .6875rem; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: var(--phv2-text-muted); }
        .phi-value { font-size: .875rem; font-weight: 600; color: var(--phv2-text); font-variant-numeric: tabular-nums; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .phi-stat { display: flex; flex-direction: column; gap: .25rem; min-width: 0; }
        .phi-steps { display: flex; flex-direction: column; gap: 1rem; }
        .phi-step { display: flex; align-items: center; gap: .75rem; font-size: .875rem; }
        .phi-step-icon { width: 1rem; height: 1rem; flex: none; }
        .phi-done { color: var(--phv2-text-muted); } .phi-done .phi-step-icon { color: #4ade80; }
        .phi-active { color: var(--phv2-text); font-weight: 500; } .phi-active .phi-step-icon { color: var(--phv2-accent-bright); }
        .phi-failed { color: var(--phv2-text); font-weight: 500; } .phi-failed .phi-step-icon { color: #f87171; }
        .phi-pending { color: var(--phv2-text-faint); }
        .phi-details { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: 1rem 1.5rem; }
        @media (min-width: 640px) {
            .phi-status { flex-direction: row; align-items: center; justify-content: space-between; }
            .phi-side .phi-stat { align-items: flex-end; text-align: right; }
        }
    </style>
    <div class="flex flex-col gap-6"
         wire:poll.keep-alive.{{ $poll }}="checkInstallState"
         x-data="{
             checking: false,
             check() {
                 if (this.checking) return;
                 this.checking = true;
                 try {
                     let p = $wire.checkInstallState();
                     if (p && typeof p.finally === 'function') {
                         p.finally(() => { this.checking = false; });
                     } else {
                         this.checking = false;
                     }
                 } catch (e) {
                     this.checking = false;
                     window.location.reload();
                 }
             },
             init() {
                 document.addEventListener('visibilitychange', () => {
                     if (document.visibilityState === 'visible') {
                         this.check();
                     }
                 });
                 window.addEventListener('focus', () => {
                     this.check();
                 });

                 setInterval(() => {
                     if (document.visibilityState === 'visible') {
                         this.check();
                     }
                 }, 3000);

                 window.addEventListener('server-installed', (e) => {
                     if (e.detail?.url) {
                         window.location.href = e.detail.url;
                     }
                 });

                 document.addEventListener('livewire:navigate', (e) => {
                     e.preventDefault();
                     window.location.href = e.detail.url;
                 });
             }
         }">
        {{-- Status. The page title already names the state (installing / failed), so there is no
             badge repeating it: a spinner while it runs, the explanation, and the one next step. --}}
        <x-filament::section>
            <div class="phi-status">
                <div class="phi-main">
                    <div class="phi-title">
                        @unless ($failed)
                            <x-filament::loading-indicator class="phi-spinner" />
                        @endunless
                        <span class="phi-name">{{ $server->name }}</span>
                    </div>

                    <p class="phi-body">
                        @if ($failed)
                            {{ trans('plugged-host-minecraft::strings.installing.failed_body') }}
                        @else
                            {{ trans('plugged-host-minecraft::strings.installing.status_body') }}
                        @endif
                    </p>
                </div>

                <div class="phi-side">
                    @if ($startedAt)
                        <div class="phi-stat">
                            <span class="phi-caption">{{ trans('plugged-host-minecraft::strings.installing.elapsed') }}</span>
                            <span class="phi-value">{{ $startedAt->diffForHumans(null, true) }}</span>
                        </div>
                    @endif

                    @if ($failed && $canReinstall)
                        <x-filament::button
                            wire:click="retryReinstall"
                            wire:target="retryReinstall"
                            wire:loading.attr="disabled"
                            color="primary"
                        >
                            <span wire:loading.remove wire:target="retryReinstall">
                                {{ trans('plugged-host-minecraft::strings.installing.retry_reinstall') }}
                            </span>
                            <span wire:loading wire:target="retryReinstall">
                                {{ trans('plugged-host-minecraft::strings.installing.retry_starting') }}
                            </span>
                        </x-filament::button>
                    @endif

                    @if ($failed && filled($supportUrl))
                        <x-filament::button
                            tag="a"
                            :href="$supportUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            color="gray"
                        >
                            {{ trans('plugged-host-minecraft::strings.installing.get_help') }}
                        </x-filament::button>
                    @endif
                </div>
            </div>
        </x-filament::section>

        {{-- Steps --}}
        <x-filament::section>
            <x-slot name="heading">
                {{ trans('plugged-host-minecraft::strings.installing.steps_heading') }}
            </x-slot>

            <ol class="phi-steps">
                @foreach ($steps as $step)
                    <li @class(['phi-step', 'phi-' . $step['state']])>
                        @switch($step['state'])
                            @case('done')
                                <x-filament::icon icon="tabler-check" class="phi-step-icon" />

                                @break
                            @case('active')
                                <x-filament::loading-indicator class="phi-step-icon" />

                                @break
                            @case('failed')
                                <x-filament::icon icon="tabler-x" class="phi-step-icon" />

                                @break
                            @default
                                <x-filament::icon icon="tabler-point" class="phi-step-icon" />
                        @endswitch

                        <span>{{ $step['label'] }}</span>
                    </li>
                @endforeach
            </ol>
        </x-filament::section>

        {{-- What is being built: label over value, grouped by whitespace. --}}
        <x-filament::section>
            <x-slot name="heading">
                {{ trans('plugged-host-minecraft::strings.installing.details_heading') }}
            </x-slot>

            <div class="phi-details">
                @foreach ([
                    ['label' => trans('plugged-host-minecraft::strings.installing.detail_type'), 'value' => $server->egg?->name ?? '—'],
                    ['label' => trans('plugged-host-minecraft::strings.cpu'), 'value' => $server->cpu > 0 ? $server->cpu . '%' : trans('plugged-host-minecraft::strings.unlimited')],
                    ['label' => trans('plugged-host-minecraft::strings.memory'), 'value' => $server->memory > 0 ? $server->memory . $suffix : trans('plugged-host-minecraft::strings.unlimited')],
                    ['label' => trans('plugged-host-minecraft::strings.disk'), 'value' => $server->disk > 0 ? $server->disk . $suffix : trans('plugged-host-minecraft::strings.unlimited')],
                ] as $detail)
                    <div class="phi-stat">
                        <span class="phi-caption">{{ $detail['label'] }}</span>
                        <span class="phi-value" title="{{ $detail['value'] }}">{{ $detail['value'] }}</span>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        {{-- Somewhere to look while waiting, and the least intrusive place there is to
             mention a perk: the user is already stuck on this screen with nothing to do. --}}
        @include('plugged-host-core::filament.components.perk-list', [
            'perks' => $perks,
            'heading' => trans('plugged-host-minecraft::strings.installing.while_you_wait'),
        ])

        {{-- WP4: the install wait is the least intrusive place there is for a unit — the user is
             already stuck here with nothing to do. Renders nothing unless the slot is granted. --}}
        <x-plugged-host-ads::slot name="onboarding-install" />
    </div>
</x-filament-panels::page>
