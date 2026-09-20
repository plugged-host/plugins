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

    Every utility class here is already used by perk-list.blade.php, so nothing new has to reach
    Tailwind for this to render styled.
--}}
<div class="mb-6">
    <x-filament::section class="rounded-2xl">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-4">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-gray-100 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                    <x-filament::icon icon="tabler-rocket" class="size-5 text-primary-600 dark:text-primary-400" />
                </div>

                <div class="flex min-w-0 flex-col gap-1">
                    <h4 class="text-sm font-semibold text-gray-950 dark:text-white">
                        {{ trans('plugged-host-core::strings.onboarding.callout_heading') }}
                    </h4>
                    <p class="text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                        {{ trans('plugged-host-core::strings.onboarding.callout_body') }}
                    </p>
                </div>
            </div>

            <div class="shrink-0 sm:pl-4">
                <x-filament::button
                    tag="a"
                    :href="$url"
                    icon="tabler-wand"
                    size="sm"
                >
                    {{ trans('plugged-host-core::strings.onboarding.callout_action') }}
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>
</div>
