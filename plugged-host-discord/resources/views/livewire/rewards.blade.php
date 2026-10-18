{{--
    Discord rewards, a tab of the profile page. Built from the stores' shared classes
    (plugged-host-economy's store-styles: .ph-eco-card, .ph-eco-list, .ph-eco-tx, the type
    ramp) so it reads as the same design: full-width rows, the number on the right, the action
    under its text on a phone.

    At most one filled button: "Link Discord" while there is no link, "Join
    the Discord server" while linked but not in it. Every other action is gray.
--}}
<div class="flex flex-col gap-6">
    @includeIf('plugged-host-economy::filament.partials.store-styles')

    @if (! $linked)
        <div class="ph-eco-card">
            <div class="flex flex-col gap-1">
                <h3 class="ph-eco-heading">{{ trans('plugged-host-discord::strings.rewards.heading') }}</h3>
                <p class="ph-eco-text max-w-2xl">{{ trans('plugged-host-discord::strings.rewards.unlinked_body', ['credits' => number_format($perInvite), 'app' => config('app.name')]) }}</p>
            </div>

            <div class="flex flex-wrap gap-3">
                @if ($linkUrl)
                    <x-filament::button :href="$linkUrl" tag="a">
                        {{ $linkElsewhere
                            ? trans('plugged-host-discord::strings.rewards.link_discord_on', ['portal' => $linkElsewhere])
                            : trans('plugged-host-discord::strings.rewards.link_discord') }}
                    </x-filament::button>
                @endif

                @if ($inviteUrl)
                    <x-filament::button :href="$inviteUrl" tag="a" target="_blank" rel="noopener" color="gray">
                        {{ trans('plugged-host-discord::strings.rewards.join_server') }}
                    </x-filament::button>
                @endif
            </div>
        </div>
    @else
        <div class="ph-eco-card">
            <div class="flex flex-col gap-1">
                <span class="ph-eco-heading">{{ trans('plugged-host-discord::strings.rewards.earned') }}</span>
                <span class="ph-eco-value">{{ number_format($earned) }}</span>
                <p class="ph-eco-muted">
                    {{ trans('plugged-host-discord::strings.rewards.linked_as', ['name' => $username ?: '—']) }}
                    ·
                    @if ($inGuild)
                        {{ trans('plugged-host-discord::strings.rewards.in_server') }}
                    @else
                        <span class="ph-eco-danger">{{ trans('plugged-host-discord::strings.rewards.not_in_server') }}</span>
                    @endif
                </p>
            </div>

            <div class="flex flex-wrap gap-3">
                @if ($inviteUrl)
                    <x-filament::button :href="$inviteUrl" tag="a" target="_blank" rel="noopener" :color="$inGuild ? 'gray' : 'primary'">
                        {{ trans($inGuild ? 'plugged-host-discord::strings.rewards.open_server' : 'plugged-host-discord::strings.rewards.join_server') }}
                    </x-filament::button>
                @endif

                <x-filament::button wire:click="checkNow" wire:loading.attr="disabled" wire:target="checkNow" color="gray">
                    {{ trans('plugged-host-discord::strings.rewards.check') }}
                </x-filament::button>
            </div>
        </div>

        <section class="flex flex-col gap-3">
            <div class="flex flex-col gap-1">
                <h3 class="ph-eco-heading">{{ trans('plugged-host-discord::strings.rewards.invites_heading') }}</h3>
                <p class="ph-eco-muted max-w-2xl">
                    {{ trans_choice('plugged-host-discord::strings.rewards.invites_rule', $maturityHours, ['credits' => number_format($perInvite), 'hours' => $maturityHours]) }}
                    {{ trans_choice('plugged-host-discord::strings.rewards.invites_age', $minAgeDays, ['days' => $minAgeDays]) }}
                </p>
            </div>

            <div class="ph-eco-list">
                <div class="ph-eco-tx">
                    <div class="flex min-w-0 flex-col">
                        <span class="ph-eco-text">{{ trans('plugged-host-discord::strings.rewards.paid') }}</span>
                        <span class="ph-eco-small">{{ trans_choice('plugged-host-discord::strings.rewards.friends', $invites['paid'], ['count' => number_format($invites['paid'])]) }}</span>
                    </div>
                    <span class="ph-eco-amount ph-eco-amount-in">+{{ number_format($inviteCredits) }}</span>
                </div>

                <div class="ph-eco-tx">
                    <div class="flex min-w-0 flex-col">
                        <span class="ph-eco-text">{{ trans('plugged-host-discord::strings.rewards.waiting') }}</span>
                        <span class="ph-eco-small">
                            @if ($invites['waiting'] > 0 && $invites['next_payout'])
                                {{ $invites['next_payout']->isPast()
                                    ? trans('plugged-host-discord::strings.rewards.next_soon')
                                    : trans('plugged-host-discord::strings.rewards.next_in', ['time' => $invites['next_payout']->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE)]) }}
                            @else
                                {{ trans('plugged-host-discord::strings.rewards.waiting_none') }}
                            @endif
                        </span>
                    </div>
                    <span class="ph-eco-amount ph-eco-amount-out">{{ number_format($invites['waiting']) }}</span>
                </div>

                @if ($invites['left'] > 0)
                    <div class="ph-eco-tx">
                        <div class="flex min-w-0 flex-col">
                            <span class="ph-eco-text">{{ trans('plugged-host-discord::strings.rewards.left') }}</span>
                            <span class="ph-eco-small">{{ trans($clawback ? 'plugged-host-discord::strings.rewards.left_clawback' : 'plugged-host-discord::strings.rewards.left_kept') }}</span>
                        </div>
                        <span class="ph-eco-amount ph-eco-amount-out">{{ number_format($invites['left']) }}</span>
                    </div>
                @endif

                @if ($invites['too_new'] > 0)
                    <div class="ph-eco-tx">
                        <div class="flex min-w-0 flex-col">
                            <span class="ph-eco-text">{{ trans('plugged-host-discord::strings.rewards.too_new') }}</span>
                            <span class="ph-eco-small">{{ trans_choice('plugged-host-discord::strings.rewards.too_new_hint', $minAgeDays, ['days' => $minAgeDays]) }}</span>
                        </div>
                        <span class="ph-eco-amount ph-eco-amount-out">{{ number_format($invites['too_new']) }}</span>
                    </div>
                @endif

                @if ($invites['debt'] > 0)
                    <div class="ph-eco-tx">
                        <div class="flex min-w-0 flex-col">
                            <span class="ph-eco-text">{{ trans('plugged-host-discord::strings.rewards.debt') }}</span>
                            <span class="ph-eco-small">{{ trans('plugged-host-discord::strings.rewards.debt_hint') }}</span>
                        </div>
                        <span class="ph-eco-amount ph-eco-danger">−{{ number_format($invites['debt']) }}</span>
                    </div>
                @endif

                @if ($invites['legacy'] > 0)
                    <div class="ph-eco-tx">
                        <div class="flex min-w-0 flex-col">
                            <span class="ph-eco-text">{{ trans('plugged-host-discord::strings.rewards.legacy') }}</span>
                            <span class="ph-eco-small">{{ trans('plugged-host-discord::strings.rewards.legacy_hint') }}</span>
                        </div>
                        <span class="ph-eco-amount ph-eco-amount-out">{{ number_format($invites['legacy']) }}</span>
                    </div>
                @endif
            </div>

            <p class="ph-eco-small max-w-2xl">{{ trans('plugged-host-discord::strings.rewards.invites_how', ['app' => config('app.name')]) }}</p>
        </section>

        <section class="flex flex-col gap-3">
            <div class="flex flex-col gap-1">
                @if ($boosts['servers'] !== null)
                    <h3 class="ph-eco-heading">{{ trans('plugged-host-discord::strings.rewards.boosts_heading_any') }}</h3>
                    <p class="ph-eco-muted max-w-2xl">
                        {{ $boosts['boosting']
                            ? trans_choice('plugged-host-discord::strings.rewards.boosting_any', max(1, $boosts['count']), ['count' => max(1, $boosts['count'])])
                            : trans('plugged-host-discord::strings.rewards.not_boosting_any') }}
                        {{ trans('plugged-host-discord::strings.rewards.boosts_any_rule', ['servers' => $boosts['servers']]) }}
                    </p>
                @else
                    <h3 class="ph-eco-heading">{{ trans('plugged-host-discord::strings.rewards.boosts_heading') }}</h3>
                    <p class="ph-eco-muted">
                        {{ $boosts['boosting']
                            ? trans_choice('plugged-host-discord::strings.rewards.boosting', max(1, $boosts['count']), ['count' => max(1, $boosts['count'])])
                            : trans('plugged-host-discord::strings.rewards.not_boosting') }}
                    </p>
                @endif
            </div>

            <div class="ph-eco-list">
                @foreach ($boosts['levels'] as $level)
                    <div class="ph-eco-tx">
                        <div class="flex min-w-0 flex-col">
                            <span class="ph-eco-text">{{ trans_choice('plugged-host-discord::strings.rewards.level', $level['boosts'], ['count' => $level['boosts']]) }}</span>
                            <span class="ph-eco-small">
                                {{ $level['boosts'] === 1
                                    ? trans('plugged-host-discord::strings.rewards.level_first', ['credits' => number_format($level['credits'])])
                                    : trans('plugged-host-discord::strings.rewards.level_more', ['credits' => number_format($level['credits']), 'total' => number_format($level['total'])]) }}
                            </span>
                        </div>

                        @if ($level['received'])
                            <x-filament::badge color="success">{{ trans('plugged-host-discord::strings.rewards.received') }}</x-filament::badge>
                        @else
                            <span class="ph-eco-amount ph-eco-amount-out">+{{ number_format($level['credits']) }}</span>
                        @endif
                    </div>
                @endforeach
            </div>

            <p class="ph-eco-small">{{ trans('plugged-host-discord::strings.rewards.boosts_once') }}</p>
        </section>

        @includeWhen(view()->exists('plugged-host-economy::filament.partials.ledger'), 'plugged-host-economy::filament.partials.ledger', [
            'heading' => trans('plugged-host-discord::strings.rewards.history'),
            'transactions' => $history,
            'empty' => trans('plugged-host-discord::strings.rewards.history_empty'),
        ])
    @endif
</div>
