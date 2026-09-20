{{--
    Startup Command Terminal Display
    Renders the active startup command in a sleek dark terminal box with 1-click copy.
--}}
@php
    $server = $server ?? $getRecord();
@endphp

<div class="flex flex-col gap-2">
    <div class="flex items-center justify-between text-xs font-semibold text-gray-500 dark:text-gray-400">
        <span class="flex items-center gap-1.5">
            <x-filament::icon icon="tabler-terminal" class="size-4 text-primary-500" />
            <span>{{ trans('server/startup.command') }}</span>
        </span>
    </div>
    <div x-data="{ copied: false }" class="relative group">
        <div class="p-3.5 rounded-xl bg-gray-950 font-mono text-xs text-emerald-400 border border-white/10 break-all select-all leading-relaxed shadow-inner">
            {{ $server->startup }}
        </div>
        <button
            type="button"
            @click="navigator.clipboard.writeText(`{{ str_replace('`', '\`', $server->startup) }}`); copied = true; setTimeout(() => copied = false, 2000)"
            class="absolute top-2.5 right-2.5 p-1.5 rounded-lg bg-white/10 hover:bg-white/20 text-gray-300 transition backdrop-blur-sm opacity-0 group-hover:opacity-100 focus:opacity-100 shadow-sm"
            :title="copied ? 'Copied!' : 'Copy Command'">
            <x-filament::icon x-show="!copied" icon="tabler-copy" class="size-3.5" />
            <x-filament::icon x-show="copied" icon="tabler-check" class="size-3.5 text-success-400" style="display: none;" />
        </button>
    </div>
</div>

