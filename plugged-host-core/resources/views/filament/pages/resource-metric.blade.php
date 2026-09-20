@php
    $barColor = $metric['percentage'] >= 90
        ? 'bg-danger-500'
        : ($metric['percentage'] >= 75 ? 'bg-warning-500' : 'bg-success-500');
    $pieColor = $metric['percentage'] >= 90
        ? '#ef4444'
        : ($metric['percentage'] >= 75 ? '#f59e0b' : '#22c55e');
    $pieStyle = "background: conic-gradient({$pieColor} {$metric['percentage']}%, #d1d5db {$metric['percentage']}% 100%);";
@endphp

<div class="flex gap-4 rounded-xl border border-gray-950/5 bg-gray-50/70 p-4 dark:border-white/10 dark:bg-white/[0.03]">
    <div class="relative flex h-20 w-20 shrink-0 items-center justify-center rounded-full" style="{{ $pieStyle }}" role="img" aria-label="{{ $definition['label'] }}: {{ $metric['percentage'] }}% used">
        <div class="flex h-14 w-14 items-center justify-center rounded-full bg-white text-center dark:bg-gray-900">
            <span class="text-sm font-bold text-gray-950 dark:text-white">{{ $metric['percentage'] }}%</span>
        </div>
    </div>

    <div class="min-w-0 flex-1 space-y-2">
        <div class="flex items-center gap-2">
            <x-filament::icon :icon="$definition['icon']" class="h-5 w-5 text-gray-500 dark:text-gray-400" />
            <span class="font-medium text-gray-950 dark:text-white">{{ $definition['label'] }}</span>
        </div>

        @if ($metric['total'] > 0)
            <div class="h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                <div class="h-full rounded-full transition-all duration-500 {{ $barColor }}" style="width: {{ $metric['percentage'] }}%"></div>
            </div>
            <dl class="space-y-1 text-sm">
                <div class="flex justify-between gap-2">
                    <dt class="text-gray-500 dark:text-gray-400">Used</dt>
                    <dd class="font-medium text-gray-950 dark:text-white">{{ $metric['usedLabel'] }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-gray-500 dark:text-gray-400">Available</dt>
                    <dd class="font-medium text-success-600 dark:text-success-400">{{ $metric['availableLabel'] }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-gray-500 dark:text-gray-400">Total</dt>
                    <dd class="font-medium text-gray-950 dark:text-white">{{ $metric['totalLabel'] }}</dd>
                </div>
            </dl>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">Unavailable</p>
        @endif
    </div>
</div>
