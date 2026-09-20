{{--
    Server Metadata & Status Chips
    Renders clean copyable chips for UUID, Short ID, Node, and read-only limits.
--}}
@php
    $server = $server ?? $getRecord();
    $canEditResources = $canEditResources ?? false;
@endphp

<div class="flex flex-col gap-4">
    {{-- Identifier & Node Chips --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        {{-- Full UUID --}}
        <div x-data="{ copied: false }" class="flex items-center justify-between p-3.5 rounded-xl bg-gray-50/80 dark:bg-white/[0.03] border border-gray-200/80 dark:border-white/10 transition hover:border-gray-300 dark:hover:border-white/20">
            <div class="flex items-center gap-3 min-w-0">
                <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary-500/10 text-primary-500">
                    <x-filament::icon icon="tabler-fingerprint" class="size-5" />
                </div>
                <div class="min-w-0">
                    <span class="block text-[10px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">UUID</span>
                    <span class="font-mono text-xs font-semibold text-gray-800 dark:text-gray-200 truncate block" title="{{ $server->uuid }}">
                        {{ $server->uuid }}
                    </span>
                </div>
            </div>
            <button
                type="button"
                @click="navigator.clipboard.writeText('{{ $server->uuid }}'); copied = true; setTimeout(() => copied = false, 2000)"
                class="shrink-0 p-1.5 rounded-lg text-gray-400 hover:text-primary-500 hover:bg-gray-100 dark:hover:bg-white/10 transition"
                :title="copied ? 'Copied!' : 'Copy UUID'">
                <x-filament::icon x-show="!copied" icon="tabler-copy" class="size-4" />
                <x-filament::icon x-show="copied" icon="tabler-check" class="size-4 text-success-500" style="display: none;" />
            </button>
        </div>

        {{-- Identifier (Short UUID) --}}
        <div x-data="{ copied: false }" class="flex items-center justify-between p-3.5 rounded-xl bg-gray-50/80 dark:bg-white/[0.03] border border-gray-200/80 dark:border-white/10 transition hover:border-gray-300 dark:hover:border-white/20">
            <div class="flex items-center gap-3 min-w-0">
                <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary-500/10 text-primary-500">
                    <x-filament::icon icon="tabler-hash" class="size-5" />
                </div>
                <div class="min-w-0">
                    <span class="block text-[10px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Identifier</span>
                    <span class="font-mono text-xs font-semibold text-gray-800 dark:text-gray-200 truncate block">
                        {{ $server->uuid_short }}
                    </span>
                </div>
            </div>
            <button
                type="button"
                @click="navigator.clipboard.writeText('{{ $server->uuid_short }}'); copied = true; setTimeout(() => copied = false, 2000)"
                class="shrink-0 p-1.5 rounded-lg text-gray-400 hover:text-primary-500 hover:bg-gray-100 dark:hover:bg-white/10 transition"
                :title="copied ? 'Copied!' : 'Copy Identifier'">
                <x-filament::icon x-show="!copied" icon="tabler-copy" class="size-4" />
                <x-filament::icon x-show="copied" icon="tabler-check" class="size-4 text-success-500" style="display: none;" />
            </button>
        </div>

        {{-- Hosting Node --}}
        <div class="flex items-center justify-between p-3.5 rounded-xl bg-gray-50/80 dark:bg-white/[0.03] border border-gray-200/80 dark:border-white/10 transition hover:border-gray-300 dark:hover:border-white/20">
            <div class="flex items-center gap-3 min-w-0">
                <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-success-500/10 text-success-500">
                    <x-filament::icon icon="tabler-server-2" class="size-5" />
                </div>
                <div class="min-w-0">
                    <span class="block text-[10px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Hosting Node</span>
                    <div class="flex items-center gap-1.5">
                        <span class="size-2 rounded-full bg-success-500 animate-pulse shrink-0"></span>
                        <span class="text-xs font-semibold text-gray-800 dark:text-gray-200 truncate block">
                            {{ $server->node->name }}
                        </span>
                    </div>
                </div>
            </div>
            <span class="text-[10px] font-mono px-2 py-0.5 rounded-md bg-gray-200/60 dark:bg-white/10 text-gray-600 dark:text-gray-400">
                #{{ $server->node->id }}
            </span>
        </div>
    </div>

    {{-- Read-only limits summary if resizing is disabled --}}
    @if (!$canEditResources)
        <div class="rounded-xl bg-gray-50/50 dark:bg-white/[0.02] border border-gray-200/60 dark:border-white/10 p-4">
            <span class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-3 uppercase tracking-wider">
                {{ trans('server/setting.server_info.limits.title') }}
            </span>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                {{-- CPU --}}
                <div class="flex flex-col p-2.5 rounded-lg bg-white dark:bg-white/5 border border-gray-200/60 dark:border-white/5">
                    <span class="text-[10px] text-gray-500 dark:text-gray-400 flex items-center gap-1">
                        <x-filament::icon icon="tabler-cpu" class="size-3.5" />
                        {{ trans('server/setting.server_info.limits.cpu') }}
                    </span>
                    <span class="text-xs font-bold text-gray-900 dark:text-white mt-1">
                        {{ !$server->cpu ? trans('server/setting.server_info.limits.unlimited') : format_number($server->cpu) . '%' }}
                    </span>
                </div>

                {{-- RAM --}}
                <div class="flex flex-col p-2.5 rounded-lg bg-white dark:bg-white/5 border border-gray-200/60 dark:border-white/5">
                    <span class="text-[10px] text-gray-500 dark:text-gray-400 flex items-center gap-1">
                        <x-filament::icon icon="tabler-device-desktop-analytics" class="size-3.5" />
                        {{ trans('server/setting.server_info.limits.memory') }}
                    </span>
                    <span class="text-xs font-bold text-gray-900 dark:text-white mt-1">
                        {{ !$server->memory ? trans('server/setting.server_info.limits.unlimited') : convert_bytes_to_readable($server->memory * 2 ** 20) }}
                    </span>
                </div>

                {{-- Disk --}}
                <div class="flex flex-col p-2.5 rounded-lg bg-white dark:bg-white/5 border border-gray-200/60 dark:border-white/5">
                    <span class="text-[10px] text-gray-500 dark:text-gray-400 flex items-center gap-1">
                        <x-filament::icon icon="tabler-device-sd-card" class="size-3.5" />
                        {{ trans('server/setting.server_info.limits.disk') }}
                    </span>
                    <span class="text-xs font-bold text-gray-900 dark:text-white mt-1">
                        {{ !$server->disk ? trans('server/setting.server_info.limits.unlimited') : convert_bytes_to_readable($server->disk * 2 ** 20) }}
                    </span>
                </div>

                {{-- Backups --}}
                <div class="flex flex-col p-2.5 rounded-lg bg-white dark:bg-white/5 border border-gray-200/60 dark:border-white/5">
                    <span class="text-[10px] text-gray-500 dark:text-gray-400 flex items-center gap-1">
                        <x-filament::icon icon="tabler-file-zip" class="size-3.5" />
                        {{ trans('server/setting.server_info.limits.backups') }}
                    </span>
                    <span class="text-xs font-bold text-gray-900 dark:text-white mt-1">
                        {{ $server->backups->count() }} / {{ $server->backup_limit ?: '∞' }}
                    </span>
                </div>

                {{-- Databases --}}
                <div class="flex flex-col p-2.5 rounded-lg bg-white dark:bg-white/5 border border-gray-200/60 dark:border-white/5">
                    <span class="text-[10px] text-gray-500 dark:text-gray-400 flex items-center gap-1">
                        <x-filament::icon icon="tabler-database" class="size-3.5" />
                        {{ trans('server/setting.server_info.limits.databases') }}
                    </span>
                    <span class="text-xs font-bold text-gray-900 dark:text-white mt-1">
                        {{ $server->databases->count() }} / {{ $server->database_limit ?: '∞' }}
                    </span>
                </div>

                {{-- Allocations --}}
                <div class="flex flex-col p-2.5 rounded-lg bg-white dark:bg-white/5 border border-gray-200/60 dark:border-white/5">
                    <span class="text-[10px] text-gray-500 dark:text-gray-400 flex items-center gap-1">
                        <x-filament::icon icon="tabler-network" class="size-3.5" />
                        {{ trans('server/setting.server_info.limits.allocations') }}
                    </span>
                    <span class="text-xs font-bold text-gray-900 dark:text-white mt-1">
                        {{ $server->allocations->count() }} / {{ $server->allocation_limit ?: '∞' }}
                    </span>
                </div>
            </div>
        </div>
    @endif
</div>

