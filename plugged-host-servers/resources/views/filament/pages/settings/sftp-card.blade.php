{{--
    SFTP credentials as full-width rows: address (copy, open in an SFTP client), username
    (copy) and where the password comes from. Scoped plain CSS on the theme tokens.
--}}
@php
    $server = $server ?? $getRecord();
    $user = user();
    $sftpUrl = $server->getSftpUrl();
    $hostAndPort = $server->node->fqdn . ':' . ($server->node->daemon_sftp ?? 2022);
    $username = ($user?->username ?? 'user') . '.' . $server->uuid_short;
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
    @media (max-width: 640px) { .phset-label { width: 5.5rem; } }
</style>

<div class="phset-rows">
    {{-- Connection Address --}}
    <div x-data="{ copied: false }" class="phset-row">
        <span class="phset-label">{{ trans('server/setting.server_info.sftp.connection') }}</span>
        <span class="phset-value font-mono" title="{{ $hostAndPort }}">{{ $hostAndPort }}</span>
        <span class="phset-actions">
            <button
                type="button"
                @click="navigator.clipboard.writeText('{{ $hostAndPort }}'); copied = true; setTimeout(() => copied = false, 2000)"
                class="fi-icon-btn fi-size-sm"
                aria-label="Copy Host"
                x-tooltip="{ content: 'Copy Host', theme: $store.theme }">
                <x-filament::icon x-show="!copied" icon="tabler-copy" />
                <x-filament::icon x-show="copied" icon="tabler-check" class="phset-copied" style="display: none;" />
            </button>
            <a
                href="{{ $sftpUrl }}"
                class="fi-icon-btn fi-size-sm"
                aria-label="Launch in SFTP Client"
                x-tooltip="{ content: 'Launch in SFTP Client', theme: $store.theme }">
                <x-filament::icon icon="tabler-external-link" />
            </a>
        </span>
    </div>

    {{-- Username --}}
    <div x-data="{ copied: false }" class="phset-row">
        <span class="phset-label">{{ trans('server/setting.server_info.sftp.username') }}</span>
        <span class="phset-value font-mono" title="{{ $username }}">{{ $username }}</span>
        <button
            type="button"
            @click="navigator.clipboard.writeText('{{ $username }}'); copied = true; setTimeout(() => copied = false, 2000)"
            class="fi-icon-btn fi-size-sm"
            aria-label="Copy Username"
            x-tooltip="{ content: 'Copy Username', theme: $store.theme }">
            <x-filament::icon x-show="!copied" icon="tabler-copy" />
            <x-filament::icon x-show="copied" icon="tabler-check" class="phset-copied" style="display: none;" />
        </button>
    </div>

    {{-- Password --}}
    <div class="phset-row">
        <span class="phset-label">{{ trans('server/setting.server_info.sftp.password') }}</span>
        <span class="phset-value phset-value-wrap">
            Your Account Password
            <span class="phset-hint">{{ trans('server/setting.server_info.sftp.password_body') }}</span>
        </span>
    </div>
</div>
