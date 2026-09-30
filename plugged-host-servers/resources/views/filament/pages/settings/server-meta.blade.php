{{--
    Server metadata: UUID, identifier and node as full-width rows with a copy button, plus the
    read-only limits when resizing is off. Scoped plain CSS on the theme tokens.
--}}
@php
    $server = $server ?? $getRecord();
    $canEditResources = $canEditResources ?? false;
@endphp

<style>
    .phset-rows { display: flex; flex-direction: column; }
    .phset-row { display: flex; align-items: center; gap: 1rem; min-height: 2.75rem; padding: .5rem 0; }
    .phset-row + .phset-row { border-top: 1px solid var(--phv2-hairline); }
    .phset-label { flex: none; width: 7rem; font-size: .8125rem; color: var(--phv2-text-muted); }
    .phset-value { flex: 1; min-width: 0; font-size: .875rem; color: var(--phv2-text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .phset-value-muted { color: var(--phv2-text-muted); }
    .phset-copied { color: var(--success-400); }
    .phset-caption { display: block; margin: 1.5rem 0 .75rem; font-size: .6875rem; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: var(--phv2-text-muted); }
    .phset-limits { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
    .phset-limit small { display: block; font-size: .75rem; color: var(--phv2-text-muted); }
    .phset-limit strong { display: block; margin-top: .125rem; font-size: .875rem; font-weight: 600; color: var(--phv2-text); }
    @media (min-width: 640px) { .phset-limits { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    @media (min-width: 1024px) { .phset-limits { grid-template-columns: repeat(6, minmax(0, 1fr)); } }
    @media (max-width: 640px) { .phset-label { width: 5.5rem; } }
</style>

<div>
    <div class="phset-rows">
        {{-- Full UUID --}}
        <div x-data="{ copied: false }" class="phset-row">
            <span class="phset-label">UUID</span>
            <span class="phset-value font-mono" title="{{ $server->uuid }}">{{ $server->uuid }}</span>
            <button
                type="button"
                @click="navigator.clipboard.writeText('{{ $server->uuid }}'); copied = true; setTimeout(() => copied = false, 2000)"
                class="fi-icon-btn fi-size-sm"
                aria-label="Copy UUID"
                x-tooltip="{ content: 'Copy UUID', theme: $store.theme }">
                <x-filament::icon x-show="!copied" icon="tabler-copy" />
                <x-filament::icon x-show="copied" icon="tabler-check" class="phset-copied" style="display: none;" />
            </button>
        </div>

        {{-- Identifier (Short UUID) --}}
        <div x-data="{ copied: false }" class="phset-row">
            <span class="phset-label">Identifier</span>
            <span class="phset-value font-mono">{{ $server->uuid_short }}</span>
            <button
                type="button"
                @click="navigator.clipboard.writeText('{{ $server->uuid_short }}'); copied = true; setTimeout(() => copied = false, 2000)"
                class="fi-icon-btn fi-size-sm"
                aria-label="Copy Identifier"
                x-tooltip="{ content: 'Copy Identifier', theme: $store.theme }">
                <x-filament::icon x-show="!copied" icon="tabler-copy" />
                <x-filament::icon x-show="copied" icon="tabler-check" class="phset-copied" style="display: none;" />
            </button>
        </div>

        {{-- Hosting Node --}}
        <div class="phset-row">
            <span class="phset-label">Hosting Node</span>
            <span class="phset-value">
                {{ $server->node->name }} <span class="phset-value-muted font-mono">#{{ $server->node->id }}</span>
            </span>
        </div>
    </div>

    {{-- Read-only limits summary if resizing is disabled --}}
    @if (!$canEditResources)
        <span class="phset-caption">{{ trans('server/setting.server_info.limits.title') }}</span>
        <div class="phset-limits">
            <div class="phset-limit">
                <small>{{ trans('server/setting.server_info.limits.cpu') }}</small>
                <strong class="tabular-nums">{{ !$server->cpu ? trans('server/setting.server_info.limits.unlimited') : format_number($server->cpu) . '%' }}</strong>
            </div>

            <div class="phset-limit">
                <small>{{ trans('server/setting.server_info.limits.memory') }}</small>
                <strong class="tabular-nums">{{ !$server->memory ? trans('server/setting.server_info.limits.unlimited') : convert_bytes_to_readable($server->memory * 2 ** 20) }}</strong>
            </div>

            <div class="phset-limit">
                <small>{{ trans('server/setting.server_info.limits.disk') }}</small>
                <strong class="tabular-nums">{{ !$server->disk ? trans('server/setting.server_info.limits.unlimited') : convert_bytes_to_readable($server->disk * 2 ** 20) }}</strong>
            </div>

            <div class="phset-limit">
                <small>{{ trans('server/setting.server_info.limits.backups') }}</small>
                <strong class="tabular-nums">{{ $server->backups->count() }} / {{ $server->backup_limit ?: '∞' }}</strong>
            </div>

            <div class="phset-limit">
                <small>{{ trans('server/setting.server_info.limits.databases') }}</small>
                <strong class="tabular-nums">{{ $server->databases->count() }} / {{ $server->database_limit ?: '∞' }}</strong>
            </div>

            <div class="phset-limit">
                <small>{{ trans('server/setting.server_info.limits.allocations') }}</small>
                <strong class="tabular-nums">{{ $server->allocations->count() }} / {{ $server->allocation_limit ?: '∞' }}</strong>
            </div>
        </div>
    @endif
</div>
