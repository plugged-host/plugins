<p class="fi-noprofit-reset-note" style="margin-top: 1rem; font-size: .8125rem; line-height: 1.5; color: rgb(156 163 175); text-align: center;">
    {{ trans('plugged-host-noprofit::strings.noprofit.reset_moved') }}
    <x-filament::link :href="$accountsUrl . '/settings'" size="sm">
        {{ trans('plugged-host-noprofit::strings.noprofit.reset_moved_link') }}
    </x-filament::link>
</p>
