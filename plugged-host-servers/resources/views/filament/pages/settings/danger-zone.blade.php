{{--
    Danger Zone Content
    Warning notices and clear explanations of destructive actions.
--}}
@php
    $server = $server ?? $getRecord();
    $canReinstall = (bool) user()?->can(\App\Enums\SubuserPermission::SettingsReinstall, $server);
    $canDelete = $canDelete ?? false;
@endphp

<div class="flex flex-col gap-5">
    <div class="flex items-start gap-3 p-3.5 rounded-xl bg-danger-500/10 border border-danger-500/20 text-danger-700 dark:text-danger-300">
        <x-filament::icon icon="tabler-alert-triangle" class="size-5 shrink-0 text-danger-500 mt-0.5" />
        <div class="text-xs leading-relaxed">
            <span class="font-bold">Caution:</span>
            Actions in this area are destructive and directly affect your server runtime. Ensure you have backed up any critical data or world files before proceeding.
        </div>
    </div>

    <div class="flex flex-col divide-y divide-gray-200/60 dark:divide-white/5">
        @if ($canReinstall)
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 py-3 first:pt-0">
                <div class="flex items-start gap-3 min-w-0">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-warning-500/10 text-warning-500 mt-0.5">
                        <x-filament::icon icon="tabler-refresh" class="size-5" />
                    </div>
                    <div>
                        <span class="block text-sm font-semibold text-gray-900 dark:text-white">
                            {{ trans('server/setting.reinstall.title') }}
                        </span>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 leading-relaxed">
                            {{ trans('server/setting.reinstall.body') }} {{ trans('server/setting.reinstall.body2') }}
                        </p>
                    </div>
                </div>
            </div>
        @endif

        @if ($canDelete)
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 py-3 last:pb-0">
                <div class="flex items-start gap-3 min-w-0">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-danger-500/10 text-danger-500 mt-0.5">
                        <x-filament::icon icon="tabler-trash" class="size-5" />
                    </div>
                    <div>
                        <span class="block text-sm font-semibold text-danger-600 dark:text-danger-400">
                            {{ trans('plugged-host-servers::strings.modals.delete_server') }}
                        </span>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 leading-relaxed">
                            {{ trans('plugged-host-servers::strings.modals.delete_server_warning') }}
                        </p>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>

