{{--
    Discord profile tab.

    Same design vocabulary as the Resource Store page: <x-filament::section> for
    every container at Nord's rounded-2xl radius, neutral icon circles with a
    semantic-coloured glyph, Filament badges/buttons for all pills. Colours come
    only from Filament's palette tokens (primary/info/success/warning), which the
    nord-theme plugin already maps onto the Nord colours — no literal hex, so the
    tab follows the panel's theme rather than defining a second one.

    Utilities here are compiled by plugins/plugged-host-core/resources/css/
    plugged-host.css; run `yarn build` after introducing a new class.
--}}
<div class="flex flex-col gap-6">
    @if (!$isConfigured)
        <x-filament::section class="rounded-2xl">
            <div class="flex items-start gap-4">
                <div class="flex size-11 shrink-0 items-center justify-center rounded-full bg-gray-100 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                    <x-filament::icon icon="tabler-alert-triangle" class="size-5 text-warning-600 dark:text-warning-400" />
                </div>

                <div class="flex flex-col gap-1">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">
                        {{ trans('plugged-host-discord::strings.misconfigured_title') }}
                    </h3>
                    <p class="text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                        {{ trans('plugged-host-discord::strings.misconfigured_body') }}
                    </p>
                </div>
            </div>
        </x-filament::section>
    @endif

    @if ($isLinked)
        {{-- Account --}}
        <x-filament::section class="rounded-2xl">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex items-center gap-5">
                    <img
                        src="{{ $link?->getAvatarUrl() ?? 'https://cdn.discordapp.com/embed/avatars/0.png' }}"
                        alt=""
                        class="size-14 shrink-0 rounded-full bg-gray-100 ring-4 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10"
                    />

                    <div class="flex min-w-0 flex-col gap-1">
                        <div class="flex flex-wrap items-center gap-2.5">
                            <span class="truncate text-lg font-semibold tracking-tight text-gray-950 dark:text-white">
                                {{ $link?->discord_username ?? $discordId }}
                            </span>

                            @if ($isVerified)
                                <x-filament::badge color="success" icon="tabler-circle-check" size="sm" class="rounded-full">
                                    {{ trans('plugged-host-discord::strings.badge_verified') }}
                                </x-filament::badge>
                            @else
                                <x-filament::badge color="warning" icon="tabler-alert-circle" size="sm" class="rounded-full">
                                    {{ trans('plugged-host-discord::strings.badge_not_in_server') }}
                                </x-filament::badge>
                            @endif
                        </div>

                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                            <span class="inline-flex items-center gap-1.5">
                                <x-filament::icon icon="tabler-hash" class="size-3.5" />
                                <span class="font-mono tabular-nums">{{ $discordId }}</span>
                            </span>

                            @if ($link?->last_verified_at)
                                <span aria-hidden="true">&middot;</span>
                                <span>
                                    {{ trans('plugged-host-discord::strings.last_checked') }}:
                                    {{ $link->last_verified_at->diffForHumans() }}
                                </span>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="flex shrink-0 flex-wrap items-center gap-3">
                    @if (!$isVerified && $inviteUrl)
                        <x-filament::button
                            :href="$inviteUrl"
                            tag="a"
                            target="_blank"
                            rel="noopener noreferrer"
                            color="primary"
                            icon="tabler-external-link"
                        >
                            {{ trans('plugged-host-discord::strings.join_discord_server') }}
                        </x-filament::button>
                    @endif

                    <x-filament::button
                        wire:click="verifyMembership"
                        wire:loading.attr="disabled"
                        color="gray"
                        icon="tabler-refresh"
                    >
                        {{ trans('plugged-host-discord::strings.verify_membership') }}
                    </x-filament::button>

                    <x-filament::button
                        wire:click="unlinkDiscord"
                        wire:confirm="{{ trans('plugged-host-discord::strings.unlink_confirm') }}"
                        color="danger"
                        outlined
                        icon="tabler-unlink"
                    >
                        {{ trans('plugged-host-discord::strings.unlink_discord') }}
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>

        {{-- Guild verification status --}}
        <x-filament::section class="rounded-2xl">
            <div class="flex items-start gap-4">
                <div class="flex size-11 shrink-0 items-center justify-center rounded-full bg-gray-100 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                    @if ($isVerified)
                        <x-filament::icon icon="tabler-circle-check" class="size-5 text-success-600 dark:text-success-400" />
                    @else
                        <x-filament::icon icon="tabler-alert-circle" class="size-5 text-warning-600 dark:text-warning-400" />
                    @endif
                </div>

                <div class="flex flex-col gap-1">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">
                        {{ $isVerified
                            ? trans('plugged-host-discord::strings.role_active_title', ['role' => $roleName])
                            : trans('plugged-host-discord::strings.action_required') }}
                    </h3>
                    <p class="max-w-2xl text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                        {{ $isVerified
                            ? trans('plugged-host-discord::strings.role_active_body', ['role' => $roleName])
                            : trans('plugged-host-discord::strings.join_server_help', ['role' => $roleName]) }}
                    </p>
                </div>
            </div>
        </x-filament::section>

        {{-- Rewards --}}
        <x-filament::section
            :heading="trans('plugged-host-discord::strings.rewards_section_title')"
            :description="trans('plugged-host-discord::strings.rewards_section_description')"
            icon="tabler-gift"
            icon-color="primary"
            class="rounded-2xl"
        >
            <x-slot name="afterHeader">
                <div class="flex flex-wrap items-center gap-3">
                    <x-filament::button
                        wire:click="syncRewards"
                        wire:loading.attr="disabled"
                        color="primary"
                        icon="tabler-refresh"
                    >
                        {{ trans('plugged-host-discord::strings.check_and_claim_rewards') }}
                    </x-filament::button>

                    @if ($storeUrl)
                        <x-filament::button
                            :href="$storeUrl"
                            tag="a"
                            color="gray"
                            icon="tabler-shopping-bag"
                        >
                            {{ trans('plugged-host-discord::strings.go_to_resource_store') }}
                        </x-filament::button>
                    @endif
                </div>
            </x-slot>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                {{-- Invites --}}
                <div class="flex h-full flex-col gap-5 rounded-xl bg-gray-50 p-5 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                                <x-filament::icon icon="tabler-users" class="size-5 text-info-600 dark:text-info-400" />
                            </div>
                            <h4 class="truncate text-sm font-semibold text-gray-950 dark:text-white">
                                {{ trans('plugged-host-discord::strings.invite_rewards_title') }}
                            </h4>
                        </div>

                        <x-filament::badge color="info" size="sm" class="shrink-0 rounded-full">
                            <span class="tabular-nums">+{{ number_format($creditsPerInvite) }}</span>
                            {{ trans('plugged-host-discord::strings.credits_per_invite') }}
                        </x-filament::badge>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1 rounded-xl bg-white p-4 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                                {{ trans('plugged-host-discord::strings.total_invites') }}
                            </span>
                            <span class="text-2xl font-bold tabular-nums tracking-tight text-gray-950 dark:text-white">
                                {{ number_format($inviteCount) }}
                            </span>
                        </div>

                        <div class="flex flex-col gap-1 rounded-xl bg-white p-4 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                                {{ trans('plugged-host-discord::strings.credits_earned') }}
                            </span>
                            <span class="text-2xl font-bold tabular-nums tracking-tight text-warning-600 dark:text-warning-400">
                                {{ number_format($creditsFromInvites) }}
                            </span>
                        </div>
                    </div>

                    {{-- Escrow, departures and debt. Only rendered when there is something
                         to say, so a user with a clean slate keeps the simpler two-tile view. --}}
                    @if ($pendingInvites > 0 || $leftInvites > 0 || $inviteDebt > 0 || $legacyInvites > 0)
                        <div class="flex flex-wrap gap-2">
                            @if ($pendingInvites > 0)
                                <x-filament::badge color="gray" size="sm" class="rounded-full">
                                    {{ trans('plugged-host-discord::strings.pending_invites') }}:
                                    <span class="tabular-nums">{{ number_format($pendingInvites) }}</span>
                                    (+{{ number_format($creditsPending) }})
                                </x-filament::badge>
                            @endif

                            @if ($leftInvites > 0)
                                <x-filament::badge color="warning" size="sm" class="rounded-full">
                                    {{ trans('plugged-host-discord::strings.left_invites') }}:
                                    <span class="tabular-nums">{{ number_format($leftInvites) }}</span>
                                </x-filament::badge>
                            @endif

                            @if ($legacyInvites > 0)
                                <x-filament::badge color="info" size="sm" class="rounded-full">
                                    {{ trans('plugged-host-discord::strings.legacy_invites') }}:
                                    <span class="tabular-nums">{{ number_format($legacyInvites) }}</span>
                                </x-filament::badge>
                            @endif

                            @if ($inviteDebt > 0)
                                <x-filament::badge color="danger" size="sm" class="rounded-full">
                                    {{ trans('plugged-host-discord::strings.invite_debt') }}:
                                    <span class="tabular-nums">{{ number_format($inviteDebt) }}</span>
                                </x-filament::badge>
                            @endif
                        </div>
                    @endif

                    <div class="flex-1 space-y-2 text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                        <p>{{ trans('plugged-host-discord::strings.invite_rewards_hint', ['days' => $minAccountAgeDays]) }}</p>

                        @if ($maturityHours > 0)
                            <p>{{ trans('plugged-host-discord::strings.invite_pending_hint', ['hours' => $maturityHours]) }}</p>
                        @endif

                        <p>{{ trans('plugged-host-discord::strings.invite_left_hint') }}</p>

                        @if ($legacyInvites > 0)
                            <p>{{ trans('plugged-host-discord::strings.legacy_invites_hint') }}</p>
                        @endif

                        @if ($inviteDebt > 0)
                            <p class="text-danger-600 dark:text-danger-400">
                                {{ trans('plugged-host-discord::strings.invite_debt_hint') }}
                            </p>
                        @endif
                    </div>
                </div>

                {{-- Boosts --}}
                <div class="flex h-full flex-col gap-5 rounded-xl bg-gray-50 p-5 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                                <x-filament::icon icon="tabler-sparkles" class="size-5 text-primary-600 dark:text-primary-400" />
                            </div>
                            <h4 class="truncate text-sm font-semibold text-gray-950 dark:text-white">
                                {{ trans('plugged-host-discord::strings.boost_rewards_title') }}
                            </h4>
                        </div>

                        @if ($isBoosting)
                            <x-filament::badge color="success" icon="tabler-sparkles" size="sm" class="shrink-0 rounded-full">
                                {{ trans('plugged-host-discord::strings.boost_active', ['count' => $boostCount]) }}
                            </x-filament::badge>
                        @else
                            <x-filament::badge color="gray" size="sm" class="shrink-0 rounded-full">
                                {{ trans('plugged-host-discord::strings.boost_inactive') }}
                            </x-filament::badge>
                        @endif
                    </div>

                    <div class="flex flex-col gap-3">
                        @foreach ([1 => $creditsPerBoost1, 2 => $creditsPerBoost2] as $tier => $reward)
                            <div @class([
                                'flex items-center justify-between gap-3 rounded-xl bg-white p-4 ring-1 dark:bg-gray-900',
                                'ring-gray-950/5 dark:ring-white/10' => $boostCount < $tier,
                                'ring-success-600/30 dark:ring-success-400/30' => $boostCount >= $tier,
                            ])>
                                <div class="flex min-w-0 items-center gap-2.5">
                                    <x-filament::icon
                                        :icon="$boostCount >= $tier ? 'tabler-circle-check' : 'tabler-circle-dashed'"
                                        @class([
                                            'size-4 shrink-0',
                                            'text-success-600 dark:text-success-400' => $boostCount >= $tier,
                                            'text-gray-400 dark:text-gray-500' => $boostCount < $tier,
                                        ])
                                    />
                                    <span class="truncate text-sm font-medium text-gray-950 dark:text-white">
                                        {{ trans_choice('plugged-host-discord::strings.boost_tier', $tier, ['count' => $tier]) }}
                                    </span>
                                </div>

                                <x-filament::badge color="warning" size="sm" class="shrink-0 rounded-full">
                                    <span class="tabular-nums">+{{ number_format($reward) }}</span>
                                    {{ trans('plugged-host-discord::strings.credits') }}
                                </x-filament::badge>
                            </div>
                        @endforeach
                    </div>

                    <p class="flex-1 text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                        {{ trans('plugged-host-discord::strings.boost_rewards_hint') }}
                    </p>
                </div>
            </div>
        </x-filament::section>
    @else
        {{-- Not linked --}}
        <x-filament::section class="rounded-2xl">
            <div class="flex flex-col items-center justify-center gap-5 px-6 py-10 text-center">
                <div class="flex size-16 items-center justify-center rounded-full bg-gray-100 ring-4 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                    <x-filament::icon icon="tabler-brand-discord" class="size-8 text-primary-600 dark:text-primary-400" />
                </div>

                <div class="flex flex-col gap-2">
                    <h3 class="text-lg font-semibold tracking-tight text-gray-950 dark:text-white">
                        {{ trans('plugged-host-discord::strings.not_linked_heading') }}
                    </h3>
                    <p class="max-w-xl text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                        {{ trans('plugged-host-discord::strings.not_linked_description', ['role' => $roleName]) }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center justify-center gap-3">
                    <x-filament::button
                        wire:click="linkDiscord"
                        wire:loading.attr="disabled"
                        color="primary"
                        size="lg"
                        icon="tabler-brand-discord-filled"
                    >
                        {{ trans('plugged-host-discord::strings.link_discord_account') }}
                    </x-filament::button>

                    @if ($inviteUrl)
                        <x-filament::button
                            :href="$inviteUrl"
                            tag="a"
                            target="_blank"
                            rel="noopener noreferrer"
                            color="gray"
                            outlined
                            size="lg"
                            icon="tabler-external-link"
                        >
                            {{ trans('plugged-host-discord::strings.join_discord_server') }}
                        </x-filament::button>
                    @endif
                </div>
            </div>
        </x-filament::section>
    @endif
</div>
