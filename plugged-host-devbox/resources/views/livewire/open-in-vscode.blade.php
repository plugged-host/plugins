{{--
    "Open in VS Code": the devbox part of the server settings' VS Code section. Rows like the
    SFTP card (.phset-*), one filled button (Open in VS Code), everything else secondary.
--}}
@php
    $t = fn (string $key, array $replace = []) => trans('plugged-host-devbox::strings.card.' . $key, $replace);
    $idle = (int) config('plugged-host-devbox.idle_minutes', 15);
@endphp

<div @if (($polling ?? false)) wire:poll.3s="poll" @endif>
    @if ($state !== 'hidden')
        <style>
            .phdb-rows { display: flex; flex-direction: column; margin-bottom: 1rem; }
            .phdb-row { display: flex; align-items: center; gap: 1rem; min-height: 2.75rem; padding: .5rem 0; }
            .phdb-row + .phdb-row { border-top: 1px solid var(--phv2-hairline); }
            .phdb-label { flex: none; width: 7rem; font-size: .8125rem; color: var(--phv2-text-muted); }
            .phdb-value { flex: 1; min-width: 0; font-size: .875rem; color: var(--phv2-text); overflow-wrap: anywhere; }
            .phdb-hint { display: block; margin-top: .125rem; font-size: .75rem; line-height: 1.125rem; color: var(--phv2-text-muted); }
            .phdb-error { color: var(--danger-400); }
            .phdb-copied { color: var(--success-400); }
            .phdb-actions { display: inline-flex; flex: none; align-items: center; gap: .5rem; }
            .phdb-open { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; padding-top: .75rem; }
            @media (max-width: 640px) { .phdb-label { width: 5.5rem; } }
        </style>

        <div class="phdb-rows">
            @if ($state === 'locked')
                <div class="phdb-row">
                    <span class="phdb-label">{{ $t('devbox') }}</span>
                    <span class="phdb-value">
                        {{ $t('locked') }}
                        <span class="phdb-hint">{{ $t('locked_hint') }}</span>
                    </span>
                    <x-filament::link :href="$plusUrl" size="sm">{{ $t('get_plus') }}</x-filament::link>
                </div>
            @else
                {{-- Status --}}
                <div class="phdb-row">
                    <span class="phdb-label">{{ $t('devbox') }}</span>
                    <span class="phdb-value">
                        {{ $t('states.' . $state, ['connections' => $connections ?? 0]) }}
                        <span class="phdb-hint">
                            @if ($state === 'ready')
                                {{ $host }}:{{ $port }} · {{ $t('stops_idle', ['minutes' => $idle]) }}
                            @elseif (in_array($state, ['none', 'stopped'], true))
                                {{ $t('what', ['minutes' => $idle]) }}
                            @else
                                {{ $t('hints.' . $state) }}
                            @endif
                        </span>
                    </span>
                    @if (in_array($state, ['ready', 'starting', 'failed', 'mount_missing'], true) && ($canStop ?? true))
                        <span class="phdb-actions">
                            <x-filament::button color="gray" size="sm" wire:click="stop">{{ $t('stop') }}</x-filament::button>
                        </span>
                    @endif
                </div>

                @if ($state === 'ready')
                    {{-- Connect code --}}
                    <div x-data="{ copied: false }" class="phdb-row">
                        <span class="phdb-label">{{ $t('code') }}</span>
                        <span class="phdb-value">
                            <span class="font-mono">{{ $code }}</span>
                            <span class="phdb-hint">{{ $t('code_hint') }}</span>
                        </span>
                        <button type="button" class="fi-icon-btn fi-size-sm"
                            @click="navigator.clipboard.writeText(@js($code)); copied = true; setTimeout(() => copied = false, 2000)"
                            aria-label="{{ $t('copy_code') }}" x-tooltip="{ content: @js($t('copy_code')), theme: $store.theme }">
                            <x-filament::icon x-show="!copied" icon="tabler-copy" />
                            <x-filament::icon x-show="copied" icon="tabler-check" class="phdb-copied" style="display: none;" />
                        </button>
                    </div>

                    {{-- SSH --}}
                    <div x-data="{ copied: false }" class="phdb-row">
                        <span class="phdb-label">{{ $t('ssh') }}</span>
                        <span class="phdb-value">
                            <span class="font-mono">{{ $sshCommand }}</span>
                            @if ($hostKey)
                                <span class="phdb-hint">{{ $t('host_key', ['key' => $hostKey]) }}</span>
                            @endif
                        </span>
                        <button type="button" class="fi-icon-btn fi-size-sm"
                            @click="navigator.clipboard.writeText(@js($sshCommand)); copied = true; setTimeout(() => copied = false, 2000)"
                            aria-label="{{ $t('copy_ssh') }}" x-tooltip="{ content: @js($t('copy_ssh')), theme: $store.theme }">
                            <x-filament::icon x-show="!copied" icon="tabler-copy" />
                            <x-filament::icon x-show="copied" icon="tabler-check" class="phdb-copied" style="display: none;" />
                        </button>
                    </div>
                @endif

                @if ($isOwner && $ownerHasPlus)
                    {{-- Shared devbox --}}
                    <div class="phdb-row">
                        <span class="phdb-label">{{ $t('shared') }}</span>
                        <span class="phdb-value">
                            {{ $shared ? $t('shared_on') : $t('shared_off') }}
                            <span class="phdb-hint">{{ $t('shared_hint') }}</span>
                        </span>
                        <span class="phdb-actions">
                            @if ($shared)
                                <x-filament::button color="gray" size="sm" wire:click="disableShared" wire:confirm="{{ $t('shared_disable_confirm') }}">{{ $t('shared_disable') }}</x-filament::button>
                            @else
                                <x-filament::button color="gray" size="sm" wire:click="enableShared" wire:confirm="{{ $t('shared_enable_confirm') }}">{{ $t('shared_enable') }}</x-filament::button>
                            @endif
                        </span>
                    </div>
                @endif

                @if ($error)
                    <div class="phdb-row">
                        <span class="phdb-label"></span>
                        <span class="phdb-value phdb-error">{{ trans('plugged-host-devbox::strings.errors.' . $error) }}</span>
                    </div>
                @endif

                <div class="phdb-open">
                    @if ($state === 'ready')
                        <x-filament::button tag="a" :href="$vscodeUrl">{{ $t('open') }}</x-filament::button>
                        @if ($web ?? false)
                            <x-filament::button color="gray" wire:click="openInBrowser">{{ $t('open_browser') }}</x-filament::button>
                        @endif
                    @elseif (in_array($state, ['none', 'stopped', 'failed'], true))
                        <x-filament::button wire:click="open" wire:loading.attr="disabled" wire:target="open">{{ $t('start') }}</x-filament::button>
                    @endif
                    <span class="phdb-hint">
                        {{ $t('needs_remote_ssh') }}
                        <x-filament::link :href="$remoteSshUrl" size="xs">{{ $t('install_remote_ssh') }}</x-filament::link>
                    </span>
                </div>
            @endif
        </div>
    @endif
</div>
