{{--
    Danger Zone content: one line of advice, then what each action below does, as plain rows
    split by a hairline. The buttons are the section's footer actions. Scoped plain CSS.
--}}
@php
    $server = $server ?? $getRecord();
    $canReinstall = (bool) user()?->can(\App\Enums\SubuserPermission::SettingsReinstall, $server);
    $canDelete = $canDelete ?? false;
@endphp

<style>
    .phset-danger-note { font-size: .8125rem; line-height: 1.25rem; color: var(--phv2-text-muted); }
    .phset-danger-rows { display: flex; flex-direction: column; margin-top: .75rem; }
    .phset-danger-row { padding: .75rem 0; }
    .phset-danger-row + .phset-danger-row { border-top: 1px solid var(--phv2-hairline); }
    .phset-danger-row strong { display: block; font-size: .875rem; font-weight: 600; color: var(--phv2-text); }
    .phset-danger-row p { margin-top: .125rem; font-size: .8125rem; line-height: 1.25rem; color: var(--phv2-text-muted); }
</style>

<div>
    <p class="phset-danger-note">
        {{ trans('plugged-host-servers::strings.server_settings.danger_note') }}
    </p>

    <div class="phset-danger-rows">
        @if ($canReinstall)
            <div class="phset-danger-row">
                <strong>{{ trans('server/setting.reinstall.title') }}</strong>
                <p>{{ trans('server/setting.reinstall.body') }} {{ trans('server/setting.reinstall.body2') }}</p>
            </div>
        @endif

        @if ($canDelete)
            <div class="phset-danger-row">
                <strong>{{ trans('plugged-host-servers::strings.modals.delete_server') }}</strong>
                <p>{{ trans('plugged-host-servers::strings.modals.delete_server_warning') }}</p>
            </div>
        @endif
    </div>
</div>
