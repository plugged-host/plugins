<x-filament-panels::page>
    <div class="mx-auto flex max-w-xl flex-col items-center gap-6 py-8 text-center">
        <div class="flex h-16 w-16 items-center justify-center rounded-full bg-primary-500/10 text-primary-500">
            <x-filament::icon icon="tabler-moon-2" class="h-8 w-8" />
        </div>

        <div class="space-y-2">
            <p class="text-base text-gray-600 dark:text-gray-300">
                {{ trans('plugged-host-limbo::strings.body') }}
            </p>
            @if ($enteredAt)
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $enteredAt }}
                </p>
            @endif
        </div>

        <div class="flex w-full items-center justify-center gap-6 rounded-xl border border-gray-200 bg-white p-5 dark:border-white/10 dark:bg-gray-900">
            <div class="flex flex-col">
                <span class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Cost</span>
                <span class="font-mono text-2xl font-semibold text-gray-950 dark:text-white">{{ $cost }} ⭐</span>
            </div>
            <div class="h-10 w-px bg-gray-200 dark:bg-white/10"></div>
            <div class="flex flex-col">
                <span class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Your balance</span>
                <span class="font-mono text-2xl font-semibold text-gray-950 dark:text-white">{{ $this->balance() }} ⭐</span>
            </div>
        </div>
    </div>
</x-filament-panels::page>
