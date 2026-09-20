{{--
    SFTP Credentials Card
    A dedicated, high-contrast credential display with 1-click copy and SFTP launch.
--}}
@php
    $server = $server ?? $getRecord();
    $user = user();
    $sftpUrl = $server->getSftpUrl();
    $hostAndPort = $server->node->fqdn . ':' . ($server->node->daemon_sftp ?? 2022);
    $username = ($user?->username ?? 'user') . '.' . $server->uuid_short;
@endphp

<div class="flex flex-col gap-4">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        {{-- Connection Address --}}
        <div x-data="{ copied: false }" class="flex flex-col justify-between p-4 rounded-xl bg-gray-50/80 dark:bg-white/[0.03] border border-gray-200/80 dark:border-white/10 transition hover:border-gray-300 dark:hover:border-white/20">
            <div>
                <span class="block text-[10px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">
                    {{ trans('server/setting.server_info.sftp.connection') }}
                </span>
                <span class="font-mono text-xs font-semibold text-gray-900 dark:text-white break-all block">
                    {{ $hostAndPort }}
                </span>
            </div>
            <div class="flex items-center gap-2 mt-4 pt-3 border-t border-gray-200/60 dark:border-white/5">
                <button
                    type="button"
                    @click="navigator.clipboard.writeText('{{ $hostAndPort }}'); copied = true; setTimeout(() => copied = false, 2000)"
                    class="flex-1 inline-flex items-center justify-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold bg-white dark:bg-white/10 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-white/15 border border-gray-200 dark:border-white/10 transition shadow-sm">
                    <x-filament::icon x-show="!copied" icon="tabler-copy" class="size-3.5" />
                    <x-filament::icon x-show="copied" icon="tabler-check" class="size-3.5 text-success-500" style="display: none;" />
                    <span x-text="copied ? 'Copied!' : 'Copy Host'">Copy Host</span>
                </button>
                <a
                    href="{{ $sftpUrl }}"
                    class="inline-flex items-center justify-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-semibold bg-primary-500/10 text-primary-600 dark:text-primary-400 hover:bg-primary-500/20 transition"
                    title="Launch in SFTP Client">
                    <x-filament::icon icon="tabler-plug" class="size-3.5" />
                    <span>Launch</span>
                </a>
            </div>
        </div>

        {{-- Username --}}
        <div x-data="{ copied: false }" class="flex flex-col justify-between p-4 rounded-xl bg-gray-50/80 dark:bg-white/[0.03] border border-gray-200/80 dark:border-white/10 transition hover:border-gray-300 dark:hover:border-white/20">
            <div>
                <span class="block text-[10px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">
                    {{ trans('server/setting.server_info.sftp.username') }}
                </span>
                <span class="font-mono text-xs font-semibold text-gray-900 dark:text-white break-all block">
                    {{ $username }}
                </span>
            </div>
            <div class="mt-4 pt-3 border-t border-gray-200/60 dark:border-white/5">
                <button
                    type="button"
                    @click="navigator.clipboard.writeText('{{ $username }}'); copied = true; setTimeout(() => copied = false, 2000)"
                    class="w-full inline-flex items-center justify-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold bg-white dark:bg-white/10 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-white/15 border border-gray-200 dark:border-white/10 transition shadow-sm">
                    <x-filament::icon x-show="!copied" icon="tabler-copy" class="size-3.5" />
                    <x-filament::icon x-show="copied" icon="tabler-check" class="size-3.5 text-success-500" style="display: none;" />
                    <span x-text="copied ? 'Copied!' : 'Copy Username'">Copy Username</span>
                </button>
            </div>
        </div>

        {{-- Password --}}
        <div class="flex flex-col justify-between p-4 rounded-xl bg-gray-50/80 dark:bg-white/[0.03] border border-gray-200/80 dark:border-white/10 transition hover:border-gray-300 dark:hover:border-white/20">
            <div>
                <span class="block text-[10px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">
                    {{ trans('server/setting.server_info.sftp.password') }}
                </span>
                <span class="text-xs font-semibold text-gray-900 dark:text-white flex items-center gap-1.5">
                    <x-filament::icon icon="tabler-lock" class="size-4 text-warning-500 shrink-0" />
                    Your Account Password
                </span>
            </div>
            <div class="mt-4 pt-3 border-t border-gray-200/60 dark:border-white/5">
                <p class="text-[11px] leading-relaxed text-gray-500 dark:text-gray-400">
                    {{ trans('server/setting.server_info.sftp.password_body') }}
                </p>
            </div>
        </div>
    </div>
</div>

