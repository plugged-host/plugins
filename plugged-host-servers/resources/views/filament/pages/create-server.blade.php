<x-filament-panels::page>
    @if ($games->isEmpty())
        <x-filament::section>
            <div class="flex flex-col items-center justify-center gap-2 py-8 text-center">
                <x-filament::icon icon="tabler-egg-off" class="h-10 w-10 text-gray-400" />
                <h2 class="text-lg font-semibold text-gray-950 dark:text-white">
                    {{ trans('plugged-host-servers::strings.no_games') }}
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ trans('plugged-host-servers::strings.no_games_description') }}
                </p>
            </div>
        </x-filament::section>
    @else
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($games as $game)
                <a
                    href="{{ \PluggedHost\Servers\Filament\App\Pages\ConfigureServerPage::getUrl(['game' => $game['id']]) }}"
                    wire:navigate
                    class="group flex flex-col items-center gap-3 rounded-2xl bg-white p-5 text-center shadow-md ring-1 ring-gray-950/5 transition hover:ring-primary-500 dark:bg-gray-900 dark:ring-white/10 dark:hover:ring-primary-400"
                >
                    <div class="flex h-16 w-16 items-center justify-center">
                        @if ($game['icon'])
                            <img
                                src="{{ $game['icon'] }}"
                                alt="{{ $game['name'] }}"
                                class="h-16 w-16 rounded-xl object-contain"
                                loading="lazy"
                            />
                        @else
                            <x-filament::icon
                                icon="tabler-device-gamepad-2"
                                class="h-10 w-10 text-gray-400 transition group-hover:text-primary-500 dark:group-hover:text-primary-400"
                            />
                        @endif
                    </div>

                    <div class="space-y-1">
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">
                            {{ $game['name'] }}
                        </h3>

                        @if ($game['description'])
                            <p class="line-clamp-2 text-xs text-gray-500 dark:text-gray-400">
                                {{ $game['description'] }}
                            </p>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>

        {{-- WP4: under the game grid on the create page. Renders nothing (no reserved space,
             no layout shift) unless the slot is granted — see AdDisplayService::slot(). --}}
        <x-plugged-host-ads::slot name="create-under-plan" />
    @endif
</x-filament-panels::page>
