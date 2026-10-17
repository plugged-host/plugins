{{--
    plugged.host Plus, and nothing else: the two plans side by side, what each adds over the free
    tier, and the questions people ask before buying. Tokens are earned on the Tokens page.

    One filled button on the page: Plus while it is not owned, otherwise PlusPlus for tokens.
    Every other action is a gray (secondary) button.
--}}
<x-filament-panels::page>
    @include('plugged-host-economy::filament.partials.store-styles')

    <style>
        .ph-plus-plans { display: grid; gap: 1rem; }
        .ph-plus-plan { display: flex; flex-direction: column; gap: 1.25rem; }
        .ph-plus-plan-body { display: flex; flex: 1; flex-direction: column; gap: 1rem; }
        .ph-plus-price { font-size: 1.5rem; font-weight: 600; line-height: 2rem; font-variant-numeric: tabular-nums; color: var(--phv2-text); }
        .ph-plus-perks { display: flex; flex-direction: column; gap: 0.5rem; }
        .ph-plus-perks li { font-size: 0.875rem; line-height: 1.375rem; color: var(--phv2-text-nav); }
        .ph-plus-compare { display: grid; grid-template-columns: minmax(0, 1.4fr) repeat(3, minmax(0, 1fr)); column-gap: 0.75rem; }
        .ph-plus-compare > div { padding-block: 0.75rem; font-size: 0.8125rem; line-height: 1.25rem; color: var(--phv2-text-nav); }
        .ph-plus-compare > .ph-plus-head { font-weight: 600; color: var(--phv2-text); }
        .ph-plus-compare > .ph-plus-rowhead { color: var(--phv2-text-muted); }
        .ph-plus-compare > div:nth-child(n + 5) { border-top: 1px solid var(--phv2-hairline); }
        .ph-plus-faq summary { cursor: pointer; padding-block: 0.875rem; font-size: 0.875rem; font-weight: 600; color: var(--phv2-text); }
        .ph-plus-faq details + details { border-top: 1px solid var(--phv2-hairline); }
        .ph-plus-faq p { padding-bottom: 0.875rem; }
        @media (min-width: 768px) {
            .ph-plus-plans { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
    </style>

    @if (!$membership)
        <p class="ph-eco-muted">{{ trans('plugged-host-economy::strings.membership.unavailable') }}</p>
    @else
        <div class="flex flex-col gap-6">
            @if ($membership['plusPlusActive'])
                <x-filament::callout
                    color="success"
                    :heading="trans('plugged-host-economy::strings.membership.plusplus_active_heading')"
                    :description="trans('plugged-host-economy::strings.membership.plusplus_active_body', ['until' => $membership['plusPlusUntil']])"
                />
            @elseif ($membership['hasPlus'])
                <x-filament::callout
                    color="success"
                    :heading="trans('plugged-host-economy::strings.plus_page.owned_heading')"
                    :description="trans('plugged-host-economy::strings.plus_page.owned_body')"
                />
            @endif

            <div class="ph-plus-plans">
                {{-- plugged.host Plus --}}
                <section class="ph-eco-card ph-plus-plan">
                    <div class="flex flex-col gap-1">
                        <h3 class="ph-eco-heading">{{ trans('plugged-host-economy::strings.membership.plus_title') }}</h3>
                        <p class="ph-eco-muted">{{ trans('plugged-host-economy::strings.plus_page.plus_pitch') }}</p>
                    </div>

                    <div class="flex flex-col gap-0.5">
                        <span class="ph-plus-price">{{ trans('plugged-host-economy::strings.plus_page.price_tokens', ['cost' => number_format($membership['plusCost'])]) }}</span>
                        <span class="ph-eco-small">{{ trans('plugged-host-economy::strings.membership.plus_term') }}</span>
                    </div>

                    <div class="ph-plus-plan-body">
                        <ul class="ph-plus-perks">
                            @foreach (['perk_plus_locations', 'perk_priority', 'perk_no_game_ads'] as $perk)
                                <li>{{ trans("plugged-host-economy::strings.membership.{$perk}") }}</li>
                            @endforeach
                            @if ($membership['aiPlus'] > 0)
                                <li>{{ trans('plugged-host-economy::strings.membership.perk_ai_plus', ['percent' => $membership['aiPlus']]) }}</li>
                            @endif
                            @if ($membership['devbox'])
                                <li>{{ trans('plugged-host-economy::strings.membership.perk_devbox', $membership['devbox']['plus']) }}</li>
                            @endif
                        </ul>
                    </div>

                    <div class="flex flex-col gap-2">
                        @if ($membership['hasPlus'])
                            <x-filament::badge color="success" class="self-start">
                                {{ trans('plugged-host-economy::strings.membership.owned') }}
                            </x-filament::badge>
                        @else
                            <x-filament::button
                                wire:click="buyPlus"
                                wire:loading.attr="disabled"
                                wire:confirm="{{ trans('plugged-host-economy::strings.membership.plus_confirm', ['cost' => number_format($membership['plusCost'])]) }}"
                                :disabled="$membership['plusUnaffordable']"
                                color="primary"
                                class="w-full justify-center"
                            >
                                {{ trans('plugged-host-economy::strings.plus_page.get_plus') }}
                            </x-filament::button>

                            @if ($membership['plusUnaffordable'])
                                <p class="ph-eco-small">
                                    {{ trans('plugged-host-economy::strings.membership.balance_tokens', ['balance' => number_format($tokens)]) }}
                                    @if ($earnTokensUrl)
                                        <a href="{{ $earnTokensUrl }}" class="ph-eco-link">{{ trans('plugged-host-economy::strings.membership.earn_link') }}</a>
                                    @endif
                                </p>
                            @endif
                        @endif
                    </div>
                </section>

                {{-- plugged.host PlusPlus, the add-on --}}
                <section class="ph-eco-card ph-plus-plan">
                    <div class="flex flex-col gap-1">
                        <h3 class="ph-eco-heading">{{ trans('plugged-host-economy::strings.membership.plusplus_title') }}</h3>
                        <p class="ph-eco-muted">{{ trans('plugged-host-economy::strings.plus_page.plusplus_pitch') }}</p>
                    </div>

                    <div class="flex flex-col gap-0.5">
                        <span class="ph-plus-price">{{ trans('plugged-host-economy::strings.plus_page.price_tokens', ['cost' => number_format($membership['plusPlusTokenCost'])]) }}</span>
                        <span class="ph-eco-small">{{ trans('plugged-host-economy::strings.plus_page.plusplus_term') }}</span>
                    </div>

                    <div class="ph-plus-plan-body">
                        <ul class="ph-plus-perks">
                            <li>{{ trans('plugged-host-economy::strings.membership.perk_everything_in_plus') }}</li>
                            <li>{{ trans('plugged-host-economy::strings.membership.perk_premium_locations') }}</li>
                            <li>{{ trans('plugged-host-economy::strings.membership.perk_no_ads') }}</li>
                            <li>{{ trans('plugged-host-economy::strings.membership.perk_front_of_queue') }}</li>
                            <li>{{ trans('plugged-host-economy::strings.membership.perk_cpu', ['cpu' => $membership['cpuBonus']]) }}</li>
                            @if ($membership['aiPlusPlus'] > 0)
                                <li>{{ trans('plugged-host-economy::strings.membership.perk_ai_plusplus', ['percent' => $membership['aiPlusPlus']]) }}</li>
                            @endif
                            @if ($membership['devbox'])
                                <li>{{ trans('plugged-host-economy::strings.membership.perk_devbox_plusplus', $membership['devbox']['plusplus']) }}</li>
                            @endif
                        </ul>
                    </div>

                    <div class="flex flex-col gap-2">
                        @foreach ($membership['offers'] as $offer)
                            @php($credits = $offer['pay'] === 'credits')
                            <x-filament::button
                                wire:click="buyPlusPlus('{{ $offer['pay'] }}')"
                                wire:loading.attr="disabled"
                                wire:confirm="{{ trans($credits ? 'plugged-host-economy::strings.membership.plusplus_confirm_credits' : 'plugged-host-economy::strings.membership.plusplus_confirm_tokens', ['cost' => number_format($offer['cost']), 'days' => $offer['days']]) }}"
                                :disabled="!$membership['hasPlus'] || $offer['unaffordable']"
                                :color="(!$credits && $membership['hasPlus']) ? 'primary' : 'gray'"
                                class="w-full"
                            >
                                <span class="flex w-full items-center justify-between gap-3">
                                    <span class="font-semibold">{{ trans('plugged-host-economy::strings.membership.days', ['days' => $offer['days']]) }}</span>
                                    <span class="tabular-nums">{{ number_format($offer['cost']) }} {{ trans($credits ? 'plugged-host-economy::strings.plus_page.credits' : 'plugged-host-economy::strings.membership.tokens_short') }}</span>
                                </span>
                            </x-filament::button>
                        @endforeach

                        <p class="ph-eco-small">
                            @if (!$membership['hasPlus'])
                                {{ trans('plugged-host-economy::strings.membership.needs_plus') }}
                            @else
                                {{ trans('plugged-host-economy::strings.plus_page.extend_hint') }}
                            @endif
                        </p>
                    </div>
                </section>
            </div>

            {{-- What each adds over the free tier. --}}
            <section class="flex flex-col gap-3">
                <h3 class="ph-eco-heading">{{ trans('plugged-host-economy::strings.plus_page.compare_heading') }}</h3>

                <div class="ph-eco-list">
                    <div class="ph-plus-compare" role="table" aria-label="{{ trans('plugged-host-economy::strings.plus_page.compare_heading') }}">
                        <div class="ph-plus-head" role="columnheader"></div>
                        <div class="ph-plus-head" role="columnheader">{{ trans('plugged-host-economy::strings.plus_page.col_free') }}</div>
                        <div class="ph-plus-head" role="columnheader">{{ trans('plugged-host-economy::strings.plus_page.col_plus') }}</div>
                        <div class="ph-plus-head" role="columnheader">{{ trans('plugged-host-economy::strings.plus_page.col_plusplus') }}</div>

                        @foreach (['locations', 'queue', 'game_ads', 'panel_ads', 'cpu', 'ai'] as $row)
                            <div class="ph-plus-rowhead" role="rowheader">{{ trans("plugged-host-economy::strings.plus_page.row_{$row}") }}</div>
                            @foreach (['free', 'plus', 'plusplus'] as $col)
                                <div role="cell">{{ trans("plugged-host-economy::strings.plus_page.cell_{$row}_{$col}", ['cpu' => $membership['cpuBonus'], 'plus' => $membership['aiPlus'], 'plusplus' => $membership['aiPlusPlusTotal']]) }}</div>
                            @endforeach
                        @endforeach

                        {{-- VS Code on the server (plugged-host-devbox), while devboxes are on. --}}
                        @if ($membership['devbox'])
                            <div class="ph-plus-rowhead" role="rowheader">{{ trans('plugged-host-economy::strings.plus_page.row_devbox') }}</div>
                            <div role="cell">{{ trans('plugged-host-economy::strings.plus_page.cell_devbox_free') }}</div>
                            <div role="cell">{{ trans('plugged-host-economy::strings.plus_page.cell_devbox_tier', $membership['devbox']['plus']) }}</div>
                            <div role="cell">{{ trans('plugged-host-economy::strings.plus_page.cell_devbox_tier', $membership['devbox']['plusplus']) }}</div>
                        @endif
                    </div>
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <h3 class="ph-eco-heading">{{ trans('plugged-host-economy::strings.plus_page.faq_heading') }}</h3>

                <div class="ph-eco-list ph-plus-faq">
                    @foreach (['once', 'expiry', 'tokens', 'credits'] as $question)
                        <details>
                            <summary>{{ trans("plugged-host-economy::strings.plus_page.faq_{$question}_q") }}</summary>
                            <p class="ph-eco-text">{{ trans("plugged-host-economy::strings.plus_page.faq_{$question}_a") }}</p>
                        </details>
                    @endforeach
                </div>
            </section>
        </div>
    @endif
</x-filament-panels::page>
