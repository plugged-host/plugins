{{--
    The Resource Store: the credit balance, then one row per resource with its meter and what
    can be bought, then the history.

    The meter is one bar for three numbers (store-styles): solid is what the servers use,
    tinted is the rest of the limit, the empty track runs up to the most that can be bought.

    No filled button on this page: every purchase is a gray (secondary) button with its price.
--}}
<x-filament-panels::page>
    @include('plugged-host-economy::filament.partials.store-styles')

    <div class="flex flex-col gap-6">
        <div class="ph-eco-card">
            <div class="flex flex-col gap-1">
                <span class="ph-eco-heading">{{ trans('plugged-host-economy::strings.credits.credits') }}</span>
                <span class="ph-eco-value">{{ number_format($credits) }}</span>
            </div>

            <div class="flex flex-col gap-1">
                <p class="ph-eco-text">{{ trans('plugged-host-economy::strings.resource_store.what_credits_buy') }}</p>
                <p class="ph-eco-muted">{{ trans('plugged-host-economy::strings.resource_store.balance_hint') }}</p>
            </div>

            @if ($rewardsUrl)
                <div>
                    <x-filament::button :href="$rewardsUrl" tag="a" color="gray">
                        {{ trans('plugged-host-economy::strings.credits.earn_discord_rewards') }}
                    </x-filament::button>
                </div>
            @endif
        </div>

        @if (filled($resources))
            <section class="flex flex-col gap-4">
                <div class="flex flex-col gap-1">
                    <h3 class="ph-eco-heading">{{ trans('plugged-host-economy::strings.resource_store.resources_heading') }}</h3>
                    <p class="ph-eco-muted max-w-2xl">{{ trans('plugged-host-economy::strings.resource_store.resources_hint') }}</p>
                </div>

                <div class="ph-eco-list">
                    @foreach ($resources as $resource)
                        <div class="ph-eco-row">
                            <div class="flex min-w-0 flex-1 flex-col gap-2">
                                <div class="flex items-baseline justify-between gap-3">
                                    <h4 class="ph-eco-title">{{ $resource['label'] }}</h4>
                                    <span class="ph-eco-small tabular-nums">
                                        <span @class(['ph-eco-num', 'ph-eco-danger' => $resource['over'] !== null])>{{ $resource['used'] }}</span>
                                        {{ trans('plugged-host-economy::strings.resource_store.of_limit', ['limit' => $resource['limit']]) }}
                                    </span>
                                </div>

                                <div
                                    class="ph-eco-meter"
                                    role="img"
                                    aria-label="{{ trans('plugged-host-economy::strings.resource_store.meter_label', ['used' => $resource['used'], 'limit' => $resource['limit']]) }}"
                                >
                                    <span class="ph-eco-meter-limit" style="width: {{ $resource['meter']['limit'] }}%"></span>
                                    <span
                                        @class(['ph-eco-meter-used' => !$resource['meter']['over'], 'ph-eco-meter-over' => $resource['meter']['over']])
                                        style="width: {{ $resource['meter']['used'] }}%"
                                    ></span>
                                </div>

                                <p class="ph-eco-small">
                                    @if ($resource['over'] !== null)
                                        <span class="ph-eco-danger">{{ trans('plugged-host-economy::strings.resource_store.over', ['amount' => $resource['over']]) }}</span>
                                    @elseif ($resource['at_cap'])
                                        {{ trans('plugged-host-economy::strings.resource_store.at_cap') }}
                                    @else
                                        {{ trans('plugged-host-economy::strings.resource_store.up_to', ['cap' => $resource['cap']]) }}
                                    @endif
                                </p>
                            </div>

                            @if (filled($resource['offers']) && !$resource['at_cap'])
                                <div class="ph-eco-row-action">
                                    @foreach ($resource['offers'] as $offer)
                                        <x-filament::button
                                            wire:click="{{ $offer['method'] }}({{ $offer['amount'] }})"
                                            wire:loading.attr="disabled"
                                            wire:confirm="{{ trans('plugged-host-economy::strings.credits.confirm_purchase', ['cost' => number_format($offer['price']), 'item' => $offer['label'] . ' ' . $resource['label']]) }}"
                                            :disabled="$offer['disabled']"
                                            color="gray"
                                            class="w-full"
                                        >
                                            <span class="flex w-full items-center justify-between gap-3">
                                                <span class="font-semibold">{{ $offer['label'] }}</span>
                                                <span class="tabular-nums">{{ number_format($offer['price']) }} {{ trans('plugged-host-economy::strings.credits.credits_short') }}</span>
                                            </span>
                                        </x-filament::button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        @include('plugged-host-economy::filament.partials.ledger', [
            'heading' => trans('plugged-host-economy::strings.resource_store.history'),
            'transactions' => $transactions,
            'empty' => trans('plugged-host-economy::strings.credits.no_transactions'),
        ])

        {{-- A leaderboard under the history. Renders nothing (no reserved space) unless the
             slot is granted. --}}
        <x-plugged-host-ads::slot name="store-under-tx" />
    </div>
</x-filament-panels::page>
