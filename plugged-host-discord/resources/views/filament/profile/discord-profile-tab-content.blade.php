{{--
    Discord profile tab.

    Theme v2's vocabulary: <x-filament::section> for every container, no card inside a
    card (groups are separated by whitespace or a single hairline), colour and type from
    the --phv2-* tokens in the scoped <style> below, and coloured badges only for real
    state (verified, boosting, left, debt). One filled button at a time: "Join Discord
    Server" while the account is not in the server, "Check & Claim Rewards" once it is,
    "Link Discord Account" before anything is linked. Unlinking is a danger link, not a
    filled button — the confirmation is the high-emphasis step.

    Layout utilities here are compiled by plugins/plugged-host-core/resources/css/
    plugged-host.css; run `yarn build` after introducing a new class. The <style> is
    inside the root element because this is a Livewire component's view.
--}}
<div class="flex flex-col gap-6">
    <style>
        .ph-dc-name { font-size: 1rem; font-weight: 600; line-height: 1.5rem; color: var(--phv2-text); }
        .ph-dc-title { font-size: 0.875rem; font-weight: 600; line-height: 1.25rem; color: var(--phv2-text); }
        .ph-dc-muted { font-size: 0.875rem; line-height: 1.375rem; color: var(--phv2-text-muted); }
        .ph-dc-small { font-size: 0.75rem; line-height: 1.125rem; color: var(--phv2-text-muted); }
        .ph-dc-value { font-size: 1.5rem; font-weight: 600; line-height: 2rem; font-variant-numeric: tabular-nums; color: var(--phv2-accent-bright); }
        .ph-dc-num { font-variant-numeric: tabular-nums; }
        .ph-dc-danger { color: var(--danger-400); }
        .ph-dc-avatar { width: 3.5rem; height: 3.5rem; flex-shrink: 0; border-radius: 9999px; background-color: var(--phv2-raise-2); }
        .ph-dc-divider { padding-top: 1.25rem; border-top: 1px solid var(--phv2-hairline); }
        .ph-dc-tiers > * + * { border-top: 1px solid var(--phv2-hairline); }
        .ph-dc-tier { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding-block: 0.625rem; }
        .ph-dc-tier:first-child { padding-top: 0; }
        .ph-dc-tier:last-child { padding-bottom: 0; }
        .ph-dc-dot { width: 0.5rem; height: 0.5rem; flex-shrink: 0; border-radius: 9999px; background-color: var(--phv2-raise-3); }
        .ph-dc-dot-on { background-color: var(--success-500); }
    </style>

    @if (!$isConfigured)
        <x-filament::callout
            color="warning"
            :heading="trans('plugged-host-discord::strings.misconfigured_title')"
            :description="trans('plugged-host-discord::strings.misconfigured_body')"
        />
    @endif

    @if ($isLinked)
        {{-- Account, and what it currently unlocks --}}
        <x-filament::section>
            <div class="flex flex-col gap-5">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex min-w-0 items-center gap-4">
                        <img
                            src="{{ $link?->getAvatarUrl() ?? 'https://cdn.discordapp.com/embed/avatars/0.png' }}"
                            alt=""
                            class="ph-dc-avatar"
                        />

                        <div class="flex min-w-0 flex-col gap-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="ph-dc-name truncate">{{ $link?->discord_username ?? $discordId }}</span>

                                @if ($isVerified)
                                    <x-filament::badge color="success" size="sm">
                                        {{ trans('plugged-host-discord::strings.badge_verified') }}
                                    </x-filament::badge>
                                @else
                                    <x-filament::badge color="warning" size="sm">
                                        {{ trans('plugged-host-discord::strings.badge_not_in_server') }}
                                    </x-filament::badge>
                                @endif
                            </div>

                            <div class="ph-dc-small flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span class="font-mono tabular-nums">{{ $discordId }}</span>

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
                            >
                                {{ trans('plugged-host-discord::strings.join_discord_server') }}
                            </x-filament::button>
                        @endif

                        <x-filament::button
                            wire:click="verifyMembership"
                            wire:loading.attr="disabled"
                            color="gray"
                        >
                            {{ trans('plugged-host-discord::strings.verify_membership') }}
                        </x-filament::button>

                        <x-filament::link
                            tag="button"
                            color="danger"
                            wire:click="unlinkDiscord"
                            wire:confirm="{{ trans('plugged-host-discord::strings.unlink_confirm') }}"
                        >
                            {{ trans('plugged-host-discord::strings.unlink_discord') }}
                        </x-filament::link>
                    </div>
                </div>

                {{-- Guild verification status: what the badge above means for this account --}}
                <div class="ph-dc-divider flex flex-col gap-1">
                    <h3 class="ph-dc-title">
                        {{ $isVerified
                            ? trans('plugged-host-discord::strings.role_active_title', ['role' => $roleName])
                            : trans('plugged-host-discord::strings.action_required') }}
                    </h3>
                    <p class="ph-dc-muted max-w-2xl">
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
        >
            <x-slot name="afterHeader">
                <div class="flex flex-wrap items-center gap-3">
                    <x-filament::button
                        wire:click="syncRewards"
                        wire:loading.attr="disabled"
                        :color="$isVerified ? 'primary' : 'gray'"
                    >
                        {{ trans('plugged-host-discord::strings.check_and_claim_rewards') }}
                    </x-filament::button>

                    @if ($storeUrl)
                        <x-filament::button
                            :href="$storeUrl"
                            tag="a"
                            color="gray"
                        >
                            {{ trans('plugged-host-discord::strings.go_to_resource_store') }}
                        </x-filament::button>
                    @endif
                </div>
            </x-slot>

            <div class="grid grid-cols-1 gap-8 lg:grid-cols-2">
                {{-- Invites --}}
                <div class="flex flex-col gap-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-2 gap-y-1">
                        <h4 class="ph-dc-title">{{ trans('plugged-host-discord::strings.invite_rewards_title') }}</h4>
                        <span class="ph-dc-small">
                            <span class="ph-dc-num">+{{ number_format($creditsPerInvite) }}</span>
                            {{ trans('plugged-host-discord::strings.credits_per_invite') }}
                        </span>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="flex flex-col gap-0.5">
                            <span class="ph-dc-small">{{ trans('plugged-host-discord::strings.total_invites') }}</span>
                            <span class="ph-dc-value">{{ number_format($inviteCount) }}</span>
                        </div>

                        <div class="flex flex-col gap-0.5">
                            <span class="ph-dc-small">{{ trans('plugged-host-discord::strings.credits_earned') }}</span>
                            <span class="ph-dc-value">{{ number_format($creditsFromInvites) }}</span>
                        </div>
                    </div>

                    {{-- Escrow, departures and debt. Only rendered when there is something
                         to say, so a user with a clean slate keeps the simpler view. --}}
                    @if ($pendingInvites > 0 || $leftInvites > 0 || $inviteDebt > 0 || $legacyInvites > 0)
                        <div class="flex flex-wrap gap-2">
                            @if ($pendingInvites > 0)
                                <x-filament::badge color="gray" size="sm">
                                    {{ trans('plugged-host-discord::strings.pending_invites') }}:
                                    <span class="tabular-nums">{{ number_format($pendingInvites) }}</span>
                                    (+{{ number_format($creditsPending) }})
                                </x-filament::badge>
                            @endif

                            @if ($leftInvites > 0)
                                <x-filament::badge color="warning" size="sm">
                                    {{ trans('plugged-host-discord::strings.left_invites') }}:
                                    <span class="tabular-nums">{{ number_format($leftInvites) }}</span>
                                </x-filament::badge>
                            @endif

                            @if ($legacyInvites > 0)
                                <x-filament::badge color="gray" size="sm">
                                    {{ trans('plugged-host-discord::strings.legacy_invites') }}:
                                    <span class="tabular-nums">{{ number_format($legacyInvites) }}</span>
                                </x-filament::badge>
                            @endif

                            @if ($inviteDebt > 0)
                                <x-filament::badge color="danger" size="sm">
                                    {{ trans('plugged-host-discord::strings.invite_debt') }}:
                                    <span class="tabular-nums">{{ number_format($inviteDebt) }}</span>
                                </x-filament::badge>
                            @endif
                        </div>
                    @endif

                    <div class="ph-dc-small flex flex-col gap-2">
                        <p>{{ trans('plugged-host-discord::strings.invite_rewards_hint', ['days' => $minAccountAgeDays]) }}</p>

                        @if ($maturityHours > 0)
                            <p>{{ trans('plugged-host-discord::strings.invite_pending_hint', ['hours' => $maturityHours]) }}</p>
                        @endif

                        <p>{{ trans('plugged-host-discord::strings.invite_left_hint') }}</p>

                        @if ($legacyInvites > 0)
                            <p>{{ trans('plugged-host-discord::strings.legacy_invites_hint') }}</p>
                        @endif

                        @if ($inviteDebt > 0)
                            <p class="ph-dc-danger">{{ trans('plugged-host-discord::strings.invite_debt_hint') }}</p>
                        @endif
                    </div>
                </div>

                {{-- Boosts --}}
                <div class="flex flex-col gap-4">
                    <div class="flex flex-wrap items-center justify-between gap-x-2 gap-y-1">
                        <h4 class="ph-dc-title">{{ trans('plugged-host-discord::strings.boost_rewards_title') }}</h4>

                        @if ($isBoosting)
                            <x-filament::badge color="success" size="sm">
                                {{ trans('plugged-host-discord::strings.boost_active', ['count' => $boostCount]) }}
                            </x-filament::badge>
                        @else
                            <x-filament::badge color="gray" size="sm">
                                {{ trans('plugged-host-discord::strings.boost_inactive') }}
                            </x-filament::badge>
                        @endif
                    </div>

                    {{-- One row per tier; the dot is green once this account's boosts reach it --}}
                    <div class="ph-dc-tiers flex flex-col">
                        @foreach ([1 => $creditsPerBoost1, 2 => $creditsPerBoost2] as $tier => $reward)
                            <div class="ph-dc-tier">
                                <div class="flex min-w-0 items-center gap-2.5">
                                    <span @class(['ph-dc-dot', 'ph-dc-dot-on' => $boostCount >= $tier]) aria-hidden="true"></span>
                                    <span class="ph-dc-title truncate">
                                        {{ trans_choice('plugged-host-discord::strings.boost_tier', $tier, ['count' => $tier]) }}
                                    </span>
                                </div>

                                <span class="ph-dc-small shrink-0">
                                    <span class="ph-dc-num">+{{ number_format($reward) }}</span>
                                    {{ trans('plugged-host-discord::strings.credits') }}
                                </span>
                            </div>
                        @endforeach
                    </div>

                    <p class="ph-dc-small">{{ trans('plugged-host-discord::strings.boost_rewards_hint') }}</p>
                </div>
            </div>
        </x-filament::section>
    @else
        {{-- Not linked --}}
        <x-filament::section
            :heading="trans('plugged-host-discord::strings.not_linked_heading')"
            :description="trans('plugged-host-discord::strings.not_linked_description', ['role' => $roleName])"
        >
            <div class="flex flex-wrap items-center gap-3">
                <x-filament::button
                    wire:click="linkDiscord"
                    wire:loading.attr="disabled"
                    color="primary"
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
                    >
                        {{ trans('plugged-host-discord::strings.join_discord_server') }}
                    </x-filament::button>
                @endif
            </div>
        </x-filament::section>
    @endif
</div>
