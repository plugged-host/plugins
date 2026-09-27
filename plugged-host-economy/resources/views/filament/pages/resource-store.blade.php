{{--
    The Economy page.

    Layout utilities used here are compiled by plugins/plugged-host-core/resources/css/
    plugged-host.css, which is the only Tailwind entry that scans plugins/**. If you
    introduce a class that no plugin view used before, run `yarn build` or it will
    silently render unstyled. Colour, type and surfaces come from the scoped <style>
    below, which reads Theme v2's --phv2-* tokens, so nothing here depends on
    Filament's light/dark switch.

    Phone first, at 360px: every list is a column of full-width rows whose action drops
    under its text below `sm`. The transaction history is the one that mattered most — a
    four-column table in an `overflow-x-auto` reads as broken on a phone, so below `sm`
    the same rows render stacked and the table itself is hidden.

    One filled button on the page: the ad-free priority tier. Every other action is a
    gray (secondary) button.
--}}
<x-filament-panels::page>
    <style>
        .ph-eco-card { display: flex; flex-direction: column; gap: 1.25rem; padding: 1rem; border-radius: 1rem; background-color: var(--phv2-raise); }
        .ph-eco-list { display: flex; flex-direction: column; padding: 0.25rem 1rem; border-radius: 1rem; background-color: var(--phv2-raise); }
        .ph-eco-row { display: flex; flex-direction: column; gap: 1rem; padding-block: 1rem; }
        .ph-eco-row + .ph-eco-row { border-top: 1px solid var(--phv2-hairline); }
        .ph-eco-row-action { display: flex; flex-direction: column; gap: 0.5rem; width: 100%; }
        @media (min-width: 640px) {
            .ph-eco-card { padding: 1.5rem; }
            .ph-eco-list { padding-inline: 1.5rem; }
            .ph-eco-row { flex-direction: row; align-items: center; justify-content: space-between; gap: 1.5rem; }
            .ph-eco-row-action { width: 15rem; flex-shrink: 0; }
        }

        .ph-eco-heading { font-size: 1rem; font-weight: 600; line-height: 1.5rem; color: var(--phv2-text); }
        .ph-eco-title { font-size: 0.875rem; font-weight: 600; line-height: 1.25rem; color: var(--phv2-text); }
        .ph-eco-text { font-size: 0.875rem; line-height: 1.375rem; color: var(--phv2-text-nav); }
        .ph-eco-muted { font-size: 0.8125rem; line-height: 1.25rem; color: var(--phv2-text-muted); }
        .ph-eco-small { font-size: 0.75rem; line-height: 1.125rem; color: var(--phv2-text-muted); }
        .ph-eco-caption { font-size: 0.6875rem; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; color: var(--phv2-text-muted); }
        .ph-eco-value { font-size: 1.5rem; font-weight: 600; line-height: 2rem; font-variant-numeric: tabular-nums; color: var(--phv2-accent-bright); }
        .ph-eco-num { font-variant-numeric: tabular-nums; font-weight: 600; color: var(--phv2-text); }
        .ph-eco-link { font-weight: 500; color: var(--phv2-accent-bright); }
        .ph-eco-link:hover { color: var(--phv2-accent-glow); text-decoration: underline; }

        .ph-eco-track { height: 4px; border-radius: 9999px; overflow: hidden; background-color: var(--phv2-raise); }
        .ph-eco-fill { display: block; height: 100%; border-radius: 9999px; background-color: var(--phv2-accent-bright); }

        .ph-eco-amount { flex-shrink: 0; font-size: 0.875rem; font-weight: 600; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .ph-eco-amount-in { color: var(--success-400); }
        .ph-eco-amount-out { color: var(--phv2-text-muted); }

        .ph-eco-tx { display: flex; flex-direction: column; gap: 0.25rem; padding-block: 0.75rem; }
        .ph-eco-tx:first-child { padding-top: 0; }
        .ph-eco-tx:last-child { padding-bottom: 0; }
        .ph-eco-tx + .ph-eco-tx { border-top: 1px solid var(--phv2-hairline); }

        .ph-eco-table { width: 100%; text-align: left; font-size: 0.875rem; }
        .ph-eco-table th { padding: 0 1rem 0.75rem 0; }
        .ph-eco-table th:last-child, .ph-eco-table td:last-child { padding-right: 0; }
        .ph-eco-table td { padding: 0.75rem 1rem 0.75rem 0; border-top: 1px solid var(--phv2-hairline); vertical-align: middle; }
        .ph-eco-table .ph-eco-end { text-align: right; white-space: nowrap; }
    </style>

    <div class="flex flex-col gap-6">
        {{--
            The wallet: both currencies as equal cards.

            Each card says the balance, what that balance is for, and where more comes
            from. The credits card spans the row on its own when the token half of the
            economy is switched off, rather than leaving a hole next to it. The body grows
            (flex-1) so both cards' buttons land on one baseline.
        --}}
        <div @class(['grid grid-cols-1 gap-4', 'lg:grid-cols-2' => $tokensEnabled])>
            <div class="ph-eco-card">
                <div class="flex flex-col gap-1">
                    <span class="ph-eco-heading">{{ trans('plugged-host-economy::strings.credits.credits') }}</span>
                    <span class="ph-eco-value">{{ number_format($credits) }}</span>
                </div>

                <div class="flex flex-1 flex-col gap-1">
                    <p class="ph-eco-text">{{ trans('plugged-host-economy::strings.tokens.what_credits_buy') }}</p>
                    <p class="ph-eco-muted">{{ trans('plugged-host-economy::strings.credits.balance_hint') }}</p>
                </div>

                @if ($discordProfileUrl)
                    <x-filament::button
                        :href="$discordProfileUrl"
                        tag="a"
                        color="gray"
                        class="w-full justify-center"
                    >
                        {{ trans('plugged-host-economy::strings.credits.earn_discord_rewards') }}
                    </x-filament::button>
                @endif
            </div>

            @if ($tokensEnabled)
                <div class="ph-eco-card">
                    <div class="flex flex-col gap-1">
                        <span class="ph-eco-heading">{{ trans('plugged-host-economy::strings.tokens.tokens') }}</span>
                        <span class="ph-eco-value">{{ number_format($tokens) }}</span>
                    </div>

                    <div class="flex flex-1 flex-col gap-1">
                        <p class="ph-eco-text">{{ trans('plugged-host-economy::strings.tokens.what_tokens_buy') }}</p>
                        <p class="ph-eco-muted">{{ trans('plugged-host-economy::strings.tokens.balance_hint') }}</p>
                    </div>

                    @if ($earnTokensUrl)
                        <x-filament::button
                            :href="$earnTokensUrl"
                            tag="a"
                            color="gray"
                            class="w-full justify-center"
                        >
                            {{ trans('plugged-host-economy::strings.tokens.earn_tokens') }}
                        </x-filament::button>
                    @endif
                </div>
            @endif
        </div>

        {{--
            Where earning lands, directly under the wallet.

            plugged-host-ads registers into this hook; economy does not know that, and
            with ads uninstalled or disabled the hook renders nothing and the page is
            exactly what it was. The name is a literal on both sides — see
            ResourceStorePage::HOOK_AFTER_TOKENS.

            The sponsored outbound anchor is deliberately *not* here. It only earns if
            it was rewritten in the browser at DOMContentLoaded, so it stays on the ads
            plugin's own page and what appears here links to it.
        --}}
        {{ \Filament\Support\Facades\FilamentView::renderHook(\PluggedHost\Economy\Filament\App\Pages\ResourceStorePage::HOOK_AFTER_TOKENS) }}

        {{--
            Priority. Above the resource upgrades on purpose: the other place it is sold
            is the queue's waiting page, which can only be reached by pressing Start on a
            node that is already full — so anyone who had not yet been made to wait had
            no way of knowing this existed.

            Absent entirely when the queue plugin is not installed ($priority is null).
        --}}
        @if ($priority)
            <div class="flex flex-col gap-4">
                <div class="flex flex-col gap-1">
                    <h3 class="ph-eco-heading">{{ trans('plugged-host-economy::strings.priority.heading') }}</h3>
                    <p class="ph-eco-muted max-w-2xl">{{ trans('plugged-host-economy::strings.priority.description') }}</p>
                </div>

                {{-- The receipt for a window that is already running. Shown even when
                     selling has been switched off, because withdrawing an offer does not
                     repossess what somebody already paid for. --}}
                @if ($priority['active'])
                    <x-filament::callout
                        color="success"
                        :heading="trans('plugged-host-economy::strings.priority.active_heading')"
                        :description="trans('plugged-host-economy::strings.priority.active_body', ['remaining' => $priority['remaining']])
                            . ($priority['adFree'] ? ' ' . trans('plugged-host-economy::strings.priority.active_ad_free', ['remaining' => $priority['adFreeRemaining']]) : '')"
                    />
                @endif

                @if (filled($priority['tiers']))
                    {{-- One full-width row per tier, cheapest first. The ad-free tier's
                         button is the page's one filled button: it is the one being sold. --}}
                    <div class="ph-eco-list">
                        @foreach ($priority['tiers'] as $tier)
                            @php
                                $adFree = $tier['ad_free'];
                                $label = trans($adFree
                                    ? 'plugged-host-economy::strings.priority.tier_ad_free'
                                    : 'plugged-host-economy::strings.priority.tier_standard');
                                $perks = $adFree
                                    ? ['perk_queue', 'perk_transfer', 'perk_no_ads']
                                    : ['perk_queue', 'perk_transfer', 'perk_ads_stay'];
                            @endphp

                            <div class="ph-eco-row">
                                <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                                    <div class="flex flex-wrap items-baseline gap-x-2">
                                        <h4 class="ph-eco-title">{{ $label }}</h4>
                                        <span class="ph-eco-muted tabular-nums">
                                            {{ trans('plugged-host-economy::strings.priority.tier_days', ['days' => $tier['days']]) }}
                                        </span>
                                    </div>

                                    <ul class="flex flex-col gap-0.5">
                                        @foreach ($perks as $perk)
                                            <li class="ph-eco-muted">{{ trans("plugged-host-economy::strings.priority.{$perk}") }}</li>
                                        @endforeach
                                    </ul>
                                </div>

                                <div class="ph-eco-row-action">
                                    <x-filament::button
                                        wire:click="buyPriority('{{ $tier['key'] }}')"
                                        wire:loading.attr="disabled"
                                        wire:confirm="{{ trans('plugged-host-economy::strings.priority.confirm', ['cost' => number_format($tier['cost']), 'days' => $tier['days']]) }}"
                                        :disabled="$tier['unaffordable']"
                                        :color="$adFree ? 'primary' : 'gray'"
                                        class="w-full justify-center"
                                    >
                                        {{ trans('plugged-host-economy::strings.priority.cta', ['cost' => number_format($tier['cost'])]) }}
                                    </x-filament::button>

                                    @if ($tier['unaffordable'])
                                        <p class="ph-eco-small text-center">
                                            {{ trans('plugged-host-economy::strings.priority.balance', ['balance' => number_format($tokens)]) }}
                                            @if ($earnTokensUrl)
                                                <a href="{{ $earnTokensUrl }}" class="ph-eco-link">
                                                    {{ trans('plugged-host-economy::strings.priority.earn_link') }}
                                                </a>
                                            @endif
                                        </p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <p class="ph-eco-small">{{ trans('plugged-host-economy::strings.priority.extend_hint') }}</p>
                @endif
            </div>
        @endif

        {{-- Resource upgrades: one full-width row per resource, the purchase tiers on the
             right from `sm` up and under the text below it. --}}
        @if (filled($upgrades))
            <div class="flex flex-col gap-4">
                <div class="flex flex-col gap-1">
                    <h3 class="ph-eco-heading">{{ trans('plugged-host-economy::strings.credits.available_upgrades') }}</h3>
                    <p class="ph-eco-muted max-w-2xl">{{ trans('plugged-host-economy::strings.credits.available_upgrades_description') }}</p>
                </div>

                <div class="ph-eco-list">
                    @foreach ($upgrades as $upgrade)
                        <div class="ph-eco-row">
                            <div class="flex min-w-0 flex-1 flex-col gap-2">
                                <div class="flex items-center justify-between gap-3">
                                    <h4 class="ph-eco-title truncate">{{ $upgrade['label'] }}</h4>

                                    @if ($upgrade['at_max'])
                                        <x-filament::badge color="success" size="sm" class="shrink-0">
                                            {{ ($upgrade['unlimited'] ?? false)
                                                ? trans('plugged-host-core::strings.unlimited')
                                                : trans('plugged-host-economy::strings.credits.max_tier') }}
                                        </x-filament::badge>
                                    @endif
                                </div>

                                @if ($upgrade['percent'] !== null)
                                    <div class="ph-eco-track">
                                        <span class="ph-eco-fill" style="width: {{ max(2, $upgrade['percent']) }}%"></span>
                                    </div>

                                    <div class="ph-eco-small flex flex-wrap items-baseline justify-between gap-x-2">
                                        <span>
                                            {{ trans('plugged-host-economy::strings.credits.limit') }}:
                                            <span class="ph-eco-num">{{ $upgrade['current'] }}</span>
                                        </span>
                                        <span>
                                            {{ trans('plugged-host-economy::strings.credits.max') }}:
                                            <span class="ph-eco-num">{{ $upgrade['max'] }}</span>
                                        </span>
                                    </div>
                                @else
                                    <span class="ph-eco-small">
                                        {{ trans('plugged-host-economy::strings.credits.current_limit') }}:
                                        <span class="ph-eco-num">{{ $upgrade['current'] }}</span>
                                    </span>
                                @endif

                                <p class="ph-eco-muted">{{ $upgrade['description'] }}</p>
                            </div>

                            <div class="ph-eco-row-action">
                                @foreach ($upgrade['tiers'] as $tier)
                                    <x-filament::button
                                        wire:click="{{ $tier['method'] }}({{ $tier['amount'] }})"
                                        wire:loading.attr="disabled"
                                        wire:confirm="{{ trans('plugged-host-economy::strings.credits.confirm_purchase', ['cost' => number_format($tier['price']), 'item' => $tier['label']]) }}"
                                        :disabled="$tier['disabled']"
                                        color="gray"
                                        class="w-full"
                                    >
                                        <span class="flex w-full items-center justify-between gap-3">
                                            <span class="font-semibold">{{ $tier['label'] }}</span>
                                            <span class="tabular-nums">
                                                {{ number_format($tier['price']) }} {{ trans('plugged-host-economy::strings.credits.credits_short') }}
                                            </span>
                                        </span>
                                    </x-filament::button>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Transaction history --}}
        <x-filament::section :heading="trans('plugged-host-economy::strings.credits.recent_transactions')">
            @if ($transactions->isEmpty())
                <p class="ph-eco-muted">{{ trans('plugged-host-economy::strings.credits.no_transactions') }}</p>
            @else
                {{--
                    The same rows twice: stacked rows below `sm`, the table from `sm` up.

                    Four columns inside `overflow-x-auto` was the worst thing on this page
                    at 360px — a horizontally scrolling table reads as a broken layout
                    rather than as something you are meant to scroll, and the amount, which
                    is the column people came for, was the one off the right edge.
                --}}
                <div class="flex flex-col sm:hidden">
                    @foreach ($transactions as $tx)
                        <div class="ph-eco-tx">
                            <div class="flex items-start justify-between gap-3">
                                <span class="ph-eco-text min-w-0">{{ $tx->description }}</span>
                                <span @class(['ph-eco-amount', 'ph-eco-amount-in' => $tx->amount > 0, 'ph-eco-amount-out' => $tx->amount <= 0])>
                                    {{ $tx->amount > 0 ? '+' : '−' }}{{ number_format(abs($tx->amount)) }}
                                </span>
                            </div>

                            <div class="ph-eco-small flex items-baseline justify-between gap-3 tabular-nums">
                                <span>{{ $tx->created_at->format('Y-m-d H:i') }}</span>
                                <span>
                                    {{ trans('plugged-host-economy::strings.credits.balance') }}:
                                    <span class="ph-eco-num">{{ number_format($tx->balance_after) }}</span>
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="hidden overflow-x-auto sm:block">
                    <table class="ph-eco-table">
                        <thead>
                            <tr class="ph-eco-caption">
                                <th scope="col">{{ trans('plugged-host-economy::strings.credits.date') }}</th>
                                <th scope="col">{{ trans('plugged-host-economy::strings.credits.description') }}</th>
                                <th scope="col" class="ph-eco-end">{{ trans('plugged-host-economy::strings.credits.amount') }}</th>
                                <th scope="col" class="ph-eco-end">{{ trans('plugged-host-economy::strings.credits.balance') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($transactions as $tx)
                                <tr>
                                    <td class="ph-eco-small whitespace-nowrap tabular-nums">{{ $tx->created_at->format('Y-m-d H:i') }}</td>
                                    <td class="ph-eco-text">{{ $tx->description }}</td>
                                    <td class="ph-eco-end">
                                        <span @class(['ph-eco-amount', 'ph-eco-amount-in' => $tx->amount > 0, 'ph-eco-amount-out' => $tx->amount <= 0])>
                                            {{ $tx->amount > 0 ? '+' : '−' }}{{ number_format(abs($tx->amount)) }}
                                        </span>
                                    </td>
                                    <td class="ph-eco-end ph-eco-small tabular-nums">{{ number_format($tx->balance_after) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        {{-- WP4: a leaderboard under the ledger. Renders nothing (no reserved space) unless the
             slot is granted — exempt users, capped pages and disabled placements see nothing. --}}
        <x-plugged-host-ads::slot name="store-under-tx" />
    </div>
</x-filament-panels::page>
