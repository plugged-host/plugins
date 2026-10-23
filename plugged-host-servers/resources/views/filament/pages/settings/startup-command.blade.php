{{--
    The active startup command in an inset well with a copy button. The section heading already
    names it, so no caption. Scoped plain CSS on the theme tokens.
--}}
@php
    $server = $server ?? $getRecord();
@endphp

<style>
    .phset-cmd { display: flex; align-items: flex-start; gap: .5rem; padding: .5rem .5rem .5rem 1rem; border-radius: .75rem; background: var(--phv2-sunken); }
    .phset-cmd-text { flex: 1; min-width: 0; padding: .375rem 0; font-size: .8125rem; line-height: 1.25rem; color: var(--phv2-text-nav); word-break: break-all; user-select: all; }
    .phset-copied { color: var(--success-400); }
</style>

<div x-data="{ copied: false }" class="phset-cmd">
    <div class="phset-cmd-text font-mono">{{ $server->startup }}</div>
    <button
        type="button"
        @click="navigator.clipboard.writeText({{ \Illuminate\Support\Js::from((string) $server->startup) }}); copied = true; setTimeout(() => copied = false, 2000)"
        class="fi-icon-btn fi-size-sm"
        aria-label="{{ trans('plugged-host-servers::strings.server_settings.copy_command') }}"
        x-tooltip="{ content: @js(trans('plugged-host-servers::strings.server_settings.copy_command')), theme: $store.theme }">
        <x-filament::icon x-show="!copied" icon="tabler-copy" />
        <x-filament::icon x-show="copied" icon="tabler-check" class="phset-copied" style="display: none;" />
    </button>
</div>
