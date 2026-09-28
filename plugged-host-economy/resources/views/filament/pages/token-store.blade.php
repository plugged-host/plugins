{{--
    The Token Store: the balance and how to earn more, then what tokens buy (priority in the
    start queue, the Plus and Premium locations), then the history.

    One filled button on the page: the ad-free priority tier, the thing being sold. Every other
    action is a gray (secondary) button.
--}}
<x-filament-panels::page>
    @include('plugged-host-economy::filament.partials.store-styles')

    <div class="flex flex-col gap-6">
        <div class="ph-eco-card">
            <div class="flex flex-col gap-1">
                <span class="ph-eco-heading">{{ trans('plugged-host-economy::strings.tokens.tokens') }}</span>
                <span class="ph-eco-value">{{ number_format($tokens) }}</span>
            </div>

            <div class="flex flex-col gap-1">
                <p class="ph-eco-text">{{ trans('plugged-host-economy::strings.token_store.what_tokens_buy') }}</p>
                <p class="ph-eco-muted">{{ trans('plugged-host-economy::strings.token_store.balance_hint') }}</p>
            </div>

            @if ($earnTokensUrl)
                <div>
                    <x-filament::button :href="$earnTokensUrl" tag="a" color="gray">
                        {{ trans('plugged-host-economy::strings.tokens.earn_tokens') }}
                    </x-filament::button>
                </div>
            @endif
        </div>

        {{-- Earning (EarnPluginServiceProvider), directly under the balance. Renders nothing
             when no way to earn is set up. --}}
        {{ \Filament\Support\Facades\FilamentView::renderHook(\PluggedHost\Economy\Filament\App\Pages\TokenStorePage::HOOK_AFTER_TOKENS) }}

        @if ($priority)
            <section class="flex flex-col gap-4">
                <div class="flex flex-col gap-1">
                    <h3 class="ph-eco-heading">{{ trans('plugged-host-economy::strings.priority.heading') }}</h3>
                    <p class="ph-eco-muted max-w-2xl">{{ trans('plugged-host-economy::strings.priority.description') }}</p>
                </div>

                {{-- A window that is already running shows even when selling is off: withdrawing
                     an offer does not take back what somebody already paid for. --}}
                @if ($priority['active'])
                    <x-filament::callout
                        color="success"
                        :heading="trans('plugged-host-economy::strings.priority.active_heading')"
                        :description="trans('plugged-host-economy::strings.priority.active_body', ['remaining' => $priority['remaining']])
                            . ($priority['adFree'] ? ' ' . trans('plugged-host-economy::strings.priority.active_ad_free', ['remaining' => $priority['adFreeRemaining']]) : '')"
                    />
                @endif

                @if (filled($priority['tiers']))
                    <div class="ph-eco-list">
                        @foreach ($priority['tiers'] as $tier)
                            @php
                                $adFree = $tier['ad_free'];
                                $perks = $adFree
                                    ? ['perk_queue', 'perk_transfer', 'perk_no_ads']
                                    : ['perk_queue', 'perk_transfer', 'perk_ads_stay'];
                            @endphp

                            <div class="ph-eco-row">
                                <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                                    <div class="flex flex-wrap items-baseline gap-x-2">
                                        <h4 class="ph-eco-title">
                                            {{ trans($adFree ? 'plugged-host-economy::strings.priority.tier_ad_free' : 'plugged-host-economy::strings.priority.tier_standard') }}
                                        </h4>
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
                                                <a href="{{ $earnTokensUrl }}" class="ph-eco-link">{{ trans('plugged-host-economy::strings.priority.earn_link') }}</a>
                                            @endif
                                        </p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <p class="ph-eco-small">{{ trans('plugged-host-economy::strings.priority.extend_hint') }}</p>
                @endif
            </section>
        @endif

        @if ($premium)
            <section class="flex flex-col gap-4">
                <h3 class="ph-eco-heading">{{ trans('plugged-host-economy::strings.token_store.premium_heading') }}</h3>

                <div class="ph-eco-list">
                    <div class="ph-eco-row">
                        <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                            <h4 class="ph-eco-title">{{ trans('plugged-host-economy::strings.token_store.premium_title') }}</h4>
                            <p class="ph-eco-muted">{{ trans('plugged-host-economy::strings.token_store.premium_body') }}</p>
                        </div>

                        <div class="ph-eco-row-action">
                            @if ($premium['unlocked'])
                                <x-filament::badge color="success" class="self-start sm:self-end">
                                    {{ trans('plugged-host-economy::strings.token_store.premium_owned') }}
                                </x-filament::badge>
                            @else
                                <x-filament::button
                                    wire:click="unlockPremium"
                                    wire:loading.attr="disabled"
                                    wire:confirm="{{ trans('plugged-host-economy::strings.token_store.premium_confirm', ['cost' => number_format($premium['cost'])]) }}"
                                    :disabled="$premium['unaffordable']"
                                    color="gray"
                                    class="w-full justify-center"
                                >
                                    {{ trans('plugged-host-economy::strings.priority.cta', ['cost' => number_format($premium['cost'])]) }}
                                </x-filament::button>

                                @if ($premium['unaffordable'])
                                    <p class="ph-eco-small text-center">
                                        {{ trans('plugged-host-economy::strings.priority.balance', ['balance' => number_format($tokens)]) }}
                                        @if ($earnTokensUrl)
                                            <a href="{{ $earnTokensUrl }}" class="ph-eco-link">{{ trans('plugged-host-economy::strings.priority.earn_link') }}</a>
                                        @endif
                                    </p>
                                @endif
                            @endif
                        </div>
                    </div>
                </div>
            </section>
        @endif

        @include('plugged-host-economy::filament.partials.ledger', [
            'heading' => trans('plugged-host-economy::strings.token_store.history'),
            'transactions' => $transactions,
            'empty' => trans('plugged-host-economy::strings.token_store.no_history'),
        ])
    </div>
</x-filament-panels::page>
