{{--
    Waiting room for a server whose egg is still installing.
    See PluggedHost\Minecraft\Filament\Server\Pages\ServerInstallingPage for why there is no
    percentage here and why the poll drives the page rather than a websocket.

    wire:poll re-renders the whole page every few seconds, which is also what keeps the
    elapsed time current without any client-side clock.

    Utilities here are compiled by plugins/plugged-host-core/resources/css/plugged-host.css;
    run `yarn build` after introducing a new class.
--}}
<x-filament-panels::page>
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
        {{-- Status --}}
        <x-filament::section class="rounded-2xl">
            <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-5">
                    <div @class([
                        'flex size-14 shrink-0 items-center justify-center rounded-full ring-1',
                        'bg-danger-50 ring-danger-600/20 dark:bg-danger-400/10 dark:ring-danger-400/30' => $failed,
                        'bg-gray-100 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10' => !$failed,
                    ])>
                        @if ($failed)
                            <x-filament::icon icon="tabler-alert-triangle" class="size-6 text-danger-600 dark:text-danger-400" />
                        @else
                            <x-filament::loading-indicator class="size-6 text-primary-600 dark:text-primary-400" />
                        @endif
                    </div>

                    <div class="flex min-w-0 flex-col gap-1.5">
                        <div class="flex flex-wrap items-center gap-2.5">
                            <span class="truncate text-lg font-semibold tracking-tight text-gray-950 dark:text-white">
                                {{ $server->name }}
                            </span>

                            <x-filament::badge :color="$failed ? 'danger' : 'primary'" size="sm" class="rounded-full">
                                {{ $server->status?->getLabel() }}
                            </x-filament::badge>
                        </div>

                        <p class="text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                            @if ($failed)
                                {{ trans('plugged-host-minecraft::strings.installing.failed_body') }}
                            @else
                                {{ trans('plugged-host-minecraft::strings.installing.status_body') }}
                            @endif
                        </p>
                    </div>
                </div>

                <div class="flex shrink-0 flex-wrap items-center gap-4">
                    @if ($startedAt)
                        <div class="flex flex-col gap-1 sm:items-end sm:text-right">
                            <span class="text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                {{ trans('plugged-host-minecraft::strings.installing.elapsed') }}
                            </span>
                            <span class="text-base font-semibold leading-none tabular-nums text-gray-950 dark:text-white">
                                {{ $startedAt->diffForHumans(null, true) }}
                            </span>
                        </div>
                    @endif

                    @if ($failed && $canReinstall)
                        <x-filament::button
                            wire:click="retryReinstall"
                            wire:target="retryReinstall"
                            wire:loading.attr="disabled"
                            color="primary"
                            icon="tabler-refresh"
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
                            color="danger"
                            icon="tabler-brand-discord"
                        >
                            {{ trans('plugged-host-minecraft::strings.installing.get_help') }}
                        </x-filament::button>
                    @endif
                </div>
            </div>
        </x-filament::section>

        {{-- Steps --}}
        <x-filament::section class="rounded-2xl">
            <x-slot name="heading">
                {{ trans('plugged-host-minecraft::strings.installing.steps_heading') }}
            </x-slot>

            <ol class="flex flex-col gap-5">
                @foreach ($steps as $step)
                    <li class="flex items-center gap-4">
                        <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-gray-100 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                            @switch($step['state'])
                                @case('done')
                                    <x-filament::icon icon="tabler-check" class="size-4 text-success-600 dark:text-success-400" />

                                    @break
                                @case('active')
                                    <x-filament::loading-indicator class="size-4 text-primary-600 dark:text-primary-400" />

                                    @break
                                @case('failed')
                                    <x-filament::icon icon="tabler-x" class="size-4 text-danger-600 dark:text-danger-400" />

                                    @break
                                @default
                                    <x-filament::icon icon="tabler-point" class="size-4 text-gray-400 dark:text-gray-600" />
                            @endswitch
                        </div>

                        <span @class([
                            'text-sm',
                            'font-medium text-gray-950 dark:text-white' => in_array($step['state'], ['active', 'failed'], true),
                            'text-gray-500 dark:text-gray-400' => $step['state'] === 'done',
                            'text-gray-400 dark:text-gray-500' => $step['state'] === 'pending',
                        ])>
                            {{ $step['label'] }}
                        </span>
                    </li>
                @endforeach
            </ol>
        </x-filament::section>

        {{-- What is being built. Tiles rather than a bare definition list: at four columns
             a label sitting straight on top of its value reads as one cramped block, so each
             pair gets its own inset card with an icon to anchor it. --}}
        <x-filament::section class="rounded-2xl">
            <x-slot name="heading">
                {{ trans('plugged-host-minecraft::strings.installing.details_heading') }}
            </x-slot>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    ['icon' => 'tabler-cube', 'label' => trans('plugged-host-minecraft::strings.installing.detail_type'), 'value' => $server->egg?->name ?? '—'],
                    ['icon' => 'tabler-cpu', 'label' => trans('plugged-host-minecraft::strings.cpu'), 'value' => $server->cpu > 0 ? $server->cpu . '%' : trans('plugged-host-minecraft::strings.unlimited')],
                    ['icon' => 'tabler-device-sd-card', 'label' => trans('plugged-host-minecraft::strings.memory'), 'value' => $server->memory > 0 ? $server->memory . $suffix : trans('plugged-host-minecraft::strings.unlimited')],
                    ['icon' => 'tabler-database', 'label' => trans('plugged-host-minecraft::strings.disk'), 'value' => $server->disk > 0 ? $server->disk . $suffix : trans('plugged-host-minecraft::strings.unlimited')],
                ] as $detail)
                    <div class="flex items-center gap-4 rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-white ring-1 ring-gray-950/5 dark:bg-white/10 dark:ring-white/10">
                            <x-filament::icon :icon="$detail['icon']" class="size-5 text-gray-400 dark:text-gray-500" />
                        </div>

                        <div class="flex min-w-0 flex-col gap-1.5">
                            <span class="text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                {{ $detail['label'] }}
                            </span>
                            <span class="truncate text-base font-semibold leading-none text-gray-950 dark:text-white">
                                {{ $detail['value'] }}
                            </span>
                        </div>
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
