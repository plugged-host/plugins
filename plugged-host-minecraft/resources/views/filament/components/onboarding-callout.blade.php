{{--
    "Your server is not set up yet", shown at the top of the server console while the setup guide
    is still pending.

    The nav item alone is not enough: a new owner lands on the console and looks at the log, not at
    a sidebar of fourteen entries. This is the pointer, and it disappears with the guide — the
    render hook only draws it while ServerOnboardingPage::canAccess() is true, so finishing setup
    removes both at once and there is nothing to dismiss.

    It still renders for servers where setup is an offer rather than a requirement: a server that
    predates the wizard, or one an admin created, is not gated by RequireServerOnboarding, so the
    console is reachable and this is the only thing that mentions the guide at all.

    Filament's own callout, which theme v2 draws as the accent-tinted bar with a 4px start border
    — the panel's one callout look. No icon: the heading says it.
--}}
<div class="mb-6">
    <x-filament::callout
        color="primary"
        :heading="trans('plugged-host-minecraft::strings.onboarding.callout_heading')"
        :description="trans('plugged-host-minecraft::strings.onboarding.callout_body')"
    >
        <x-slot name="footer">
            <x-filament::button
                tag="a"
                :href="$url"
                size="sm"
            >
                {{ trans('plugged-host-minecraft::strings.onboarding.callout_action') }}
            </x-filament::button>
        </x-slot>
    </x-filament::callout>
</div>
