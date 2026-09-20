{{--
    The Economy page.

    Utilities used here are compiled by plugins/plugged-host-core/resources/css/
    plugged-host.css, which is the only Tailwind entry that scans plugins/**. If
    you introduce a class that no plugin view used before, run `yarn build` or it
    will silently render unstyled.

    Phone first, at 360px: card padding, section gaps and grid columns all step up at
    `sm` rather than being sized for a desktop and left to overflow. The transaction
    history is the one that mattered — a four-column table in an `overflow-x-auto`
    reads as broken on a phone, so below `sm` the same rows render as stacked cards
    and the table itself is hidden.
--}}
<x-filament-panels::page>
    <div class="flex flex-col gap-5 sm:gap-8">
        {{--
            The wallet: both currencies as equal cards.

            They used to be one box with credits at text-4xl and tokens as a quiet row
            under a divider, which read as "credits, plus a footnote" — and tokens now buy
            something on this very page, so a footnote is the wrong shape. Each card says
            the balance, what that balance is for, and where more comes from. The credits
            card spans the row on its own when the token half of the economy is switched
            off, rather than leaving a hole next to it.
        --}}
        <div @class(['grid grid-cols-1 gap-5 sm:gap-6', 'lg:grid-cols-2' => $tokensEnabled])>
            {{-- Credits. Hand-rolled rather than <x-filament::section> so `h-full` can
                 put both cards' buttons on one baseline; class list mirrors .fi-section. --}}
            <div class="flex h-full flex-col gap-5 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 sm:p-6 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-center gap-4">
                    <div class="flex size-12 shrink-0 items-center justify-center rounded-full bg-warning-50 text-warning-600 ring-4 ring-warning-500/10 dark:bg-warning-400/10 dark:text-warning-400">
                        <x-filament::icon icon="tabler-coins" class="size-6" />
                    </div>

                    <div class="flex min-w-0 flex-col gap-0.5">
                        <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            {{ trans('plugged-host-economy::strings.credits.credits') }}
                        </span>
                        <div class="flex flex-wrap items-baseline gap-2">
                            <span class="text-3xl font-bold tabular-nums tracking-tight text-gray-950 dark:text-white">
                                {{ number_format($credits) }}
                            </span>
                            <span class="text-sm font-semibold text-gray-500 dark:text-gray-400">
                                {{ trans('plugged-host-economy::strings.credits.credits') }}
                            </span>
                        </div>
                    </div>
                </div>

                {{-- Grows into the slack so both cards' buttons line up --}}
                <div class="flex flex-1 flex-col gap-1.5">
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ trans('plugged-host-economy::strings.tokens.what_credits_buy') }}
                    </p>
                    <p class="text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                        {{ trans('plugged-host-economy::strings.credits.balance_hint') }}
                    </p>
                </div>

                @if ($discordProfileUrl)
                    <x-filament::button
                        :href="$discordProfileUrl"
                        tag="a"
                        color="primary"
                        icon="tabler-brand-discord-filled"
                        class="w-full justify-center"
                    >
                        {{ trans('plugged-host-economy::strings.credits.earn_discord_rewards') }}
                    </x-filament::button>
                @endif
            </div>

            {{-- Tokens --}}
            @if ($tokensEnabled)
                <div class="flex h-full flex-col gap-5 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 sm:p-6 dark:bg-gray-900 dark:ring-white/10">
                    <div class="flex items-center gap-4">
                        <div class="flex size-12 shrink-0 items-center justify-center rounded-full bg-info-50 text-info-600 ring-4 ring-info-500/10 dark:bg-info-400/10 dark:text-info-400">
                            <x-filament::icon icon="tabler-bolt" class="size-6" />
                        </div>

                        <div class="flex min-w-0 flex-col gap-0.5">
                            <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                {{ trans('plugged-host-economy::strings.tokens.tokens') }}
                            </span>
                            <div class="flex flex-wrap items-baseline gap-2">
                                <span class="text-3xl font-bold tabular-nums tracking-tight text-gray-950 dark:text-white">
                                    {{ number_format($tokens) }}
                                </span>
                                <span class="text-sm font-semibold text-gray-500 dark:text-gray-400">
                                    {{ trans('plugged-host-economy::strings.tokens.tokens') }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-1 flex-col gap-1.5">
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-300">
                            {{ trans('plugged-host-economy::strings.tokens.what_tokens_buy') }}
                        </p>
                        <p class="text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                            {{ trans('plugged-host-economy::strings.tokens.balance_hint') }}
                        </p>
                    </div>

                    @if ($earnTokensUrl)
                        <x-filament::button
                            :href="$earnTokensUrl"
                            tag="a"
                            color="info"
                            icon="tabler-bolt"
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
            <div class="flex flex-col gap-5 sm:gap-6">
                <div class="flex flex-col gap-1">
                    <div class="flex items-center gap-2">
                        <x-filament::icon icon="tabler-rocket" class="size-5 text-gray-400 dark:text-gray-500" />
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                            {{ trans('plugged-host-economy::strings.priority.heading') }}
                        </h3>
                    </div>
                    <p class="max-w-2xl text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                        {{ trans('plugged-host-economy::strings.priority.description') }}
                    </p>
                </div>

                {{-- The receipt for a window that is already running. Shown even when
                     selling has been switched off, because withdrawing an offer does not
                     repossess what somebody already paid for. --}}
                @if ($priority['active'])
                    <div class="flex flex-col gap-2 rounded-2xl bg-success-50 p-4 ring-1 ring-success-600/20 sm:p-5 dark:bg-success-400/10 dark:ring-success-400/30">
                        <div class="flex items-center gap-2">
                            <x-filament::icon icon="tabler-circle-check-filled" class="size-5 shrink-0 text-success-600 dark:text-success-400" />
                            <span class="text-sm font-semibold text-gray-950 dark:text-white">
                                {{ trans('plugged-host-economy::strings.priority.active_heading') }}
                            </span>
                        </div>
                        <p class="text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                            {{ trans('plugged-host-economy::strings.priority.active_body', ['remaining' => $priority['remaining']]) }}
                        </p>
                        @if ($priority['adFree'])
                            <p class="text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                                {{ trans('plugged-host-economy::strings.priority.active_ad_free', ['remaining' => $priority['adFreeRemaining']]) }}
                            </p>
                        @endif
                    </div>
                @endif

                @if (filled($priority['tiers']))
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 sm:gap-6">
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

                            <div @class([
                                'flex h-full flex-col gap-5 rounded-2xl bg-white p-4 shadow-sm sm:p-6 dark:bg-gray-900',
                                'ring-2 ring-info-500/60 dark:ring-info-400/50' => $adFree,
                                'ring-1 ring-gray-950/5 dark:ring-white/10' => !$adFree,
                            ])>
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex min-w-0 flex-col gap-1">
                                        <h4 class="truncate text-sm font-semibold text-gray-950 dark:text-white">
                                            {{ $label }}
                                        </h4>
                                        <div class="flex flex-wrap items-baseline gap-2">
                                            <span class="text-2xl font-bold tabular-nums tracking-tight text-gray-950 dark:text-white">
                                                {{ trans('plugged-host-economy::strings.priority.tier_days', ['days' => $tier['days']]) }}
                                            </span>
                                        </div>
                                    </div>

                                    <x-filament::badge :color="$adFree ? 'info' : 'gray'" size="sm" class="shrink-0 rounded-full">
                                        {{ trans($adFree
                                            ? 'plugged-host-economy::strings.priority.badge_no_ads'
                                            : 'plugged-host-economy::strings.priority.badge_ads') }}
                                    </x-filament::badge>
                                </div>

                                {{-- Grows so both cards' buttons land on one baseline --}}
                                <ul class="flex flex-1 flex-col gap-2">
                                    @foreach ($perks as $perk)
                                        <li class="flex items-start gap-2 text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                                            <x-filament::icon
                                                :icon="$perk === 'perk_ads_stay' ? 'tabler-minus' : 'tabler-check'"
                                                @class([
                                                    'mt-0.5 size-4 shrink-0',
                                                    'text-success-600 dark:text-success-400' => $perk !== 'perk_ads_stay',
                                                    'text-gray-400 dark:text-gray-500' => $perk === 'perk_ads_stay',
                                                ])
                                            />
                                            <span>{{ trans("plugged-host-economy::strings.priority.{$perk}") }}</span>
                                        </li>
                                    @endforeach
                                </ul>

                                <div class="flex flex-col gap-2">
                                    <x-filament::button
                                        wire:click="buyPriority('{{ $tier['key'] }}')"
                                        wire:loading.attr="disabled"
                                        wire:confirm="{{ trans('plugged-host-economy::strings.priority.confirm', ['cost' => number_format($tier['cost']), 'days' => $tier['days']]) }}"
                                        :disabled="$tier['unaffordable']"
                                        :color="$adFree ? 'info' : 'gray'"
                                        icon="tabler-bolt"
                                        class="w-full justify-center"
                                    >
                                        {{ trans('plugged-host-economy::strings.priority.cta', ['cost' => number_format($tier['cost'])]) }}
                                    </x-filament::button>

                                    @if ($tier['unaffordable'])
                                        <p class="text-center text-xs text-gray-500 dark:text-gray-400">
                                            {{ trans('plugged-host-economy::strings.priority.balance', ['balance' => number_format($tokens)]) }}
                                            @if ($earnTokensUrl)
                                                <a href="{{ $earnTokensUrl }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                                                    {{ trans('plugged-host-economy::strings.priority.earn_link') }}
                                                </a>
                                            @endif
                                        </p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ trans('plugged-host-economy::strings.priority.extend_hint') }}
                    </p>
                @endif
            </div>
        @endif

        {{-- Upgrade cards --}}
        @if (filled($upgrades))
            <div class="flex flex-col gap-5 sm:gap-6">
                <div class="flex flex-col gap-1">
                    <div class="flex items-center gap-2">
                        <x-filament::icon icon="tabler-shopping-bag" class="size-5 text-gray-400 dark:text-gray-500" />
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                            {{ trans('plugged-host-economy::strings.credits.available_upgrades') }}
                        </h3>
                    </div>
                    <p class="max-w-2xl text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                        {{ trans('plugged-host-economy::strings.credits.available_upgrades_description') }}
                    </p>
                </div>

                <div @class(['grid grid-cols-1 gap-5 sm:gap-6', $upgradeGridClass])>
                    @foreach ($upgrades as $upgrade)
                        {{--
                            Hand-rolled rather than <x-filament::section> because the cards
                            need `flex h-full flex-col` on the content box so the purchase
                            buttons of all four land on one baseline. Class list mirrors
                            .fi-section so they read as native panel cards.
                        --}}
                        <div class="flex h-full flex-col gap-5 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 sm:gap-6 sm:p-6 dark:bg-gray-900 dark:ring-white/10">
                            {{-- Identity --}}
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    <div class="flex size-11 shrink-0 items-center justify-center rounded-full bg-gray-100 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                                        <x-filament::icon :icon="$upgrade['icon']" @class(['size-5', $upgrade['accent']]) />
                                    </div>
                                    <h4 class="truncate text-sm font-semibold text-gray-950 dark:text-white">
                                        {{ $upgrade['label'] }}
                                    </h4>
                                </div>

                                @if ($upgrade['at_max'])
                                    <x-filament::badge color="success" size="sm" class="shrink-0 rounded-full">
                                        {{ ($upgrade['unlimited'] ?? false)
                                            ? trans('plugged-host-core::strings.unlimited')
                                            : trans('plugged-host-economy::strings.credits.max_tier') }}
                                    </x-filament::badge>
                                @endif
                            </div>

                            {{-- Current vs. max --}}
                            <div class="flex flex-col gap-2.5">
                                <div class="flex items-baseline justify-between gap-2">
                                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                                        {{ $upgrade['percent'] === null
                                            ? trans('plugged-host-economy::strings.credits.current_limit')
                                            : trans('plugged-host-economy::strings.credits.limit') }}
                                    </span>
                                    <span class="text-sm font-semibold tabular-nums text-gray-950 dark:text-white">
                                        {{ $upgrade['current'] }}
                                    </span>
                                </div>

                                @if ($upgrade['percent'] !== null)
                                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                                        <div
                                            @class(['h-full rounded-full transition-all duration-500 ease-out', $upgrade['bar']])
                                            style="width: {{ max(2, $upgrade['percent']) }}%"
                                        ></div>
                                    </div>

                                    <div class="flex items-baseline justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
                                        <span class="tabular-nums">{{ $upgrade['percent'] }}%</span>
                                        <span>
                                            {{ trans('plugged-host-economy::strings.credits.max') }}:
                                            <span class="font-semibold tabular-nums text-gray-700 dark:text-gray-300">{{ $upgrade['max'] }}</span>
                                        </span>
                                    </div>
                                @endif
                            </div>

                            {{-- Grows to fill the slack so every card's buttons line up --}}
                            <p class="flex-1 text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                                {{ $upgrade['description'] }}
                            </p>

                            {{-- Purchase tiers --}}
                            <div class="flex flex-col gap-2.5">
                                @foreach ($upgrade['tiers'] as $tier)
                                    {{-- Label and price sit on one line from `sm` up and stack
                                         below it. At 360px in a two-up grid there is not enough
                                         room for both, and `justify-between` spent that shortfall
                                         on crushing the label — which is the half that says what
                                         you are buying. --}}
                                    <x-filament::button
                                        wire:click="{{ $tier['method'] }}({{ $tier['amount'] }})"
                                        wire:loading.attr="disabled"
                                        wire:confirm="{{ trans('plugged-host-economy::strings.credits.confirm_purchase', ['cost' => number_format($tier['price']), 'item' => $tier['label']]) }}"
                                        :disabled="$tier['disabled']"
                                        :color="$loop->last ? 'primary' : 'gray'"
                                        class="w-full"
                                    >
                                        <span class="flex w-full flex-col items-start gap-0.5 text-left sm:flex-row sm:items-center sm:justify-between sm:gap-3">
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
        <x-filament::section
            :heading="trans('plugged-host-economy::strings.credits.recent_transactions')"
            icon="tabler-receipt"
            icon-color="gray"
            class="rounded-2xl"
        >
            @if ($transactions->isEmpty())
                <div class="flex flex-col items-center justify-center gap-3 py-10 text-center">
                    <div class="flex size-11 items-center justify-center rounded-full bg-gray-100 ring-4 ring-gray-100/50 dark:bg-white/5 dark:ring-white/5">
                        <x-filament::icon icon="tabler-receipt-off" class="size-5 text-gray-400 dark:text-gray-500" />
                    </div>
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        {{ trans('plugged-host-economy::strings.credits.no_transactions') }}
                    </span>
                </div>
            @else
                {{--
                    The same rows twice: stacked cards below `sm`, the table from `sm` up.

                    Four columns inside `overflow-x-auto` was the worst thing on this page
                    at 360px — a horizontally scrolling table reads as a broken layout
                    rather than as something you are meant to scroll, and the amount, which
                    is the column people came for, was the one off the right edge.
                --}}
                <div class="flex flex-col gap-2.5 sm:hidden">
                    @foreach ($transactions as $tx)
                        <div class="flex flex-col gap-1.5 rounded-xl bg-gray-50 p-3 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                            <div class="flex items-start justify-between gap-3">
                                <span class="min-w-0 text-sm text-gray-950 dark:text-white">
                                    {{ $tx->description }}
                                </span>
                                <x-filament::badge :color="$tx->amount > 0 ? 'success' : 'danger'" size="sm" class="shrink-0 rounded-full">
                                    <span class="tabular-nums">
                                        {{ $tx->amount > 0 ? '+' : '−' }}{{ number_format(abs($tx->amount)) }}
                                    </span>
                                </x-filament::badge>
                            </div>

                            <div class="flex items-baseline justify-between gap-3 text-xs tabular-nums text-gray-500 dark:text-gray-400">
                                <span>{{ $tx->created_at->format('Y-m-d H:i') }}</span>
                                <span>
                                    {{ trans('plugged-host-economy::strings.credits.balance') }}:
                                    <span class="font-semibold text-gray-700 dark:text-gray-300">{{ number_format($tx->balance_after) }}</span>
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="hidden overflow-x-auto sm:block">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                <th scope="col" class="pb-3 pr-4 font-medium">{{ trans('plugged-host-economy::strings.credits.date') }}</th>
                                <th scope="col" class="pb-3 pr-4 font-medium">{{ trans('plugged-host-economy::strings.credits.description') }}</th>
                                <th scope="col" class="pb-3 pr-4 text-right font-medium">{{ trans('plugged-host-economy::strings.credits.amount') }}</th>
                                <th scope="col" class="pb-3 text-right font-medium">{{ trans('plugged-host-economy::strings.credits.balance') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                            @foreach ($transactions as $tx)
                                <tr>
                                    <td class="whitespace-nowrap py-3.5 pr-4 text-xs tabular-nums text-gray-500 dark:text-gray-400">
                                        {{ $tx->created_at->format('Y-m-d H:i') }}
                                    </td>
                                    <td class="py-3.5 pr-4 text-sm text-gray-950 dark:text-white">
                                        {{ $tx->description }}
                                    </td>
                                    <td class="whitespace-nowrap py-3.5 pr-4 text-right">
                                        <x-filament::badge :color="$tx->amount > 0 ? 'success' : 'danger'" size="sm" class="rounded-full">
                                            <span class="tabular-nums">
                                                {{ $tx->amount > 0 ? '+' : '−' }}{{ number_format(abs($tx->amount)) }}
                                            </span>
                                        </x-filament::badge>
                                    </td>
                                    <td class="whitespace-nowrap py-3.5 text-right text-xs font-semibold tabular-nums text-gray-500 dark:text-gray-400">
                                        {{ number_format($tx->balance_after) }}
                                    </td>
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
