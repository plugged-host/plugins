{{--
    VS Code over the node's own SFTP: rows like the SFTP card (extension, sign-in, setup), then
    the ready-made .vscode/sftp.json in an inset well with copy and download. Scoped plain CSS on
    the theme tokens, the same rules as the sibling settings cards.
--}}
@php
    $extensionUrl = 'vscode:extension/Natizyskunk.sftp';
@endphp

<style>
    .phset-rows { display: flex; flex-direction: column; }
    .phset-row { display: flex; align-items: center; gap: 1rem; min-height: 2.75rem; padding: .5rem 0; }
    .phset-row + .phset-row { border-top: 1px solid var(--phv2-hairline); }
    .phset-label { flex: none; width: 7rem; font-size: .8125rem; color: var(--phv2-text-muted); }
    .phset-value { flex: 1; min-width: 0; font-size: .875rem; color: var(--phv2-text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .phset-value-wrap { white-space: normal; }
    .phset-hint { display: block; margin-top: .125rem; font-size: .75rem; line-height: 1.125rem; color: var(--phv2-text-muted); }
    .phset-copied { color: var(--success-400); }
    .phset-actions { display: inline-flex; flex: none; align-items: center; gap: .25rem; }
    .phset-config { display: flex; align-items: flex-start; gap: .5rem; margin-top: 1rem; padding: .5rem .5rem .5rem 1rem; border-radius: .75rem; background: var(--phv2-sunken); }
    .phset-config-text { flex: 1; min-width: 0; margin: 0; padding: .375rem 0; font-size: .8125rem; line-height: 1.25rem; color: var(--phv2-text-nav); white-space: pre; overflow-x: auto; }
    @media (max-width: 640px) { .phset-label { width: 5.5rem; } }
</style>

<div class="phset-rows">
    {{-- Extension --}}
    <div class="phset-row">
        <span class="phset-label">{{ trans('plugged-host-servers::strings.server_settings.vscode.extension') }}</span>
        <span class="phset-value phset-value-wrap">
            {{ trans('plugged-host-servers::strings.server_settings.vscode.extension_name') }}
            <span class="phset-hint">{{ trans('plugged-host-servers::strings.server_settings.vscode.extension_hint') }}</span>
        </span>
        <a
            href="{{ $extensionUrl }}"
            class="fi-icon-btn fi-size-sm"
            aria-label="{{ trans('plugged-host-servers::strings.server_settings.vscode.open_extension') }}"
            x-tooltip="{ content: @js(trans('plugged-host-servers::strings.server_settings.vscode.open_extension')), theme: $store.theme }">
            <x-filament::icon icon="tabler-external-link" />
        </a>
    </div>

    {{-- Sign-in --}}
    <div class="phset-row">
        <span class="phset-label">{{ trans('plugged-host-servers::strings.server_settings.vscode.sign_in') }}</span>
        <span class="phset-value phset-value-wrap">
            @if ($keyPath)
                {{ trans('plugged-host-servers::strings.server_settings.vscode.key') }}
                <span class="phset-hint">{{ trans('plugged-host-servers::strings.server_settings.vscode.key_hint', ['path' => $keyPath]) }}</span>
            @else
                {{ trans('plugged-host-servers::strings.server_settings.vscode.password') }}
                <span class="phset-hint">
                    {{ trans('plugged-host-servers::strings.server_settings.vscode.password_hint') }}
                    @if ($sshKeysUrl)
                        <x-filament::link :href="$sshKeysUrl" size="xs">
                            {{ trans('plugged-host-servers::strings.server_settings.vscode.add_key') }}
                        </x-filament::link>
                    @endif
                </span>
            @endif
        </span>
    </div>

    {{-- Setup --}}
    <div class="phset-row">
        <span class="phset-label">{{ trans('plugged-host-servers::strings.server_settings.vscode.setup') }}</span>
        <span class="phset-value phset-value-wrap">{{ trans('plugged-host-servers::strings.server_settings.vscode.setup_body') }}</span>
    </div>
</div>

{{-- .vscode/sftp.json --}}
<div x-data="{ copied: false, config: @js($config) }" class="phset-config">
    <pre class="phset-config-text font-mono">{{ $config }}</pre>
    <span class="phset-actions">
        <button
            type="button"
            @click="navigator.clipboard.writeText(config); copied = true; setTimeout(() => copied = false, 2000)"
            class="fi-icon-btn fi-size-sm"
            aria-label="{{ trans('plugged-host-servers::strings.server_settings.vscode.copy_config') }}"
            x-tooltip="{ content: @js(trans('plugged-host-servers::strings.server_settings.vscode.copy_config')), theme: $store.theme }">
            <x-filament::icon x-show="!copied" icon="tabler-copy" />
            <x-filament::icon x-show="copied" icon="tabler-check" class="phset-copied" style="display: none;" />
        </button>
        <button
            type="button"
            @click="const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([config + '\n'], { type: 'application/json' })); a.download = 'sftp.json'; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 1000)"
            class="fi-icon-btn fi-size-sm"
            aria-label="{{ trans('plugged-host-servers::strings.server_settings.vscode.download_config') }}"
            x-tooltip="{ content: @js(trans('plugged-host-servers::strings.server_settings.vscode.download_config')), theme: $store.theme }">
            <x-filament::icon icon="tabler-download" />
        </button>
    </span>
</div>
