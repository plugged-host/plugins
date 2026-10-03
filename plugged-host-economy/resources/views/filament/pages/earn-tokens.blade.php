{{--
    Tokens: the balance, the ways to earn more, and the history. Nothing is sold here;
    tokens are spent on the plugged.host Plus page and on servers.

    See PluggedHost\Economy\Filament\App\Pages\EarnTokensPage for why a return trip is
    verified inline rather than queued, and why the client is trusted with nothing
    but a nonce.

    One section per job (balance, ways to earn, history). Ways to earn are full-width
    rows; the first provider's button is the page's one filled button, any further
    provider gets a gray one.
--}}
<x-filament-panels::page>
    <style>
        .ph-earn-value { font-size: 1.5rem; font-weight: 600; line-height: 2rem; font-variant-numeric: tabular-nums; color: var(--phv2-accent-bright); }
        .ph-earn-title { font-size: 0.875rem; font-weight: 600; line-height: 1.25rem; color: var(--phv2-text); }
        .ph-earn-text { font-size: 0.875rem; line-height: 1.25rem; color: var(--phv2-text); }
        .ph-earn-muted { font-size: 0.8125rem; line-height: 1.25rem; color: var(--phv2-text-muted); }
        .ph-earn-small { font-size: 0.75rem; line-height: 1.125rem; font-variant-numeric: tabular-nums; color: var(--phv2-text-faint); }
        .ph-earn-rows > * + * { border-top: 1px solid var(--phv2-hairline); }
        .ph-earn-row { display: flex; flex-direction: column; gap: 0.75rem; padding-block: 1rem; }
        .ph-earn-row:first-child { padding-top: 0; }
        .ph-earn-row:last-child { padding-bottom: 0; }
        .ph-earn-action { display: flex; width: 100%; }
        @media (min-width: 640px) {
            .ph-earn-row { flex-direction: row; align-items: center; justify-content: space-between; gap: 1.5rem; }
            .ph-earn-action { width: 15rem; flex-shrink: 0; justify-content: flex-end; }
        }
        .ph-earn-tx { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding-block: 0.625rem; }
        .ph-earn-tx:first-child { padding-top: 0; }
        .ph-earn-tx:last-child { padding-bottom: 0; }
        .ph-earn-amount { flex-shrink: 0; font-size: 0.875rem; font-weight: 600; font-variant-numeric: tabular-nums; }
        .ph-earn-amount-in { color: var(--success-400); }
        .ph-earn-amount-out { color: var(--phv2-text-muted); }
    </style>

    <div class="flex flex-col gap-6">
        <x-filament::section>
            <div class="flex flex-col gap-1">
                <span class="ph-earn-value">
                    {{ trans('plugged-host-economy::strings.tokens_balance', ['count' => $balance]) }}
                </span>
                <p class="ph-earn-muted">{{ trans('plugged-host-economy::strings.tokens_explainer') }}</p>
            </div>
        </x-filament::section>

        <x-filament::section>
            @if ($providers === [])
                <p class="ph-earn-muted">{{ trans('plugged-host-economy::strings.tokens_none') }}</p>
            @else
                <div class="ph-earn-rows flex flex-col">
                    @foreach ($providers as $provider)
                        <div class="ph-earn-row">
                            <div class="flex min-w-0 flex-col gap-0.5">
                                <span class="ph-earn-title">{{ $provider['label'] }}</span>
                                <span class="ph-earn-muted">
                                    {{ trans('plugged-host-economy::strings.tokens_reward', ['count' => $provider['reward']]) }}
                                </span>
                                @if ($provider['cap'] > 0)
                                    <span class="ph-earn-small">
                                        {{ trans('plugged-host-economy::strings.tokens_used', [
                                            'used' => $provider['used'],
                                            'cap' => $provider['cap'],
                                        ]) }}
                                    </span>
                                @endif
                            </div>

                            <div class="ph-earn-action">
                                @if ($provider['capped'])
                                    <x-filament::badge color="gray" size="sm">
                                        {{ trans('plugged-host-economy::strings.tokens_capped') }}
                                    </x-filament::badge>
                                @elseif ($provider['fullscript'])
                                    @if ($provider['url'] !== null)
                                        {{--
                                            A real anchor, and deliberately not a
                                            wire:click button. linkvertise.js rewrites
                                            anchors once, at DOMContentLoaded, so the
                                            href has to be in the HTML this response
                                            ships — a URL produced by a Livewire round
                                            trip would be built long after the script
                                            had finished and would never be rewritten.

                                            spa-mode off so the *outbound* click is a
                                            full load: the destination is link-to.net
                                            once the script has rewritten it, and
                                            wire:navigate on a cross-origin href is
                                            wrong anyway.

                                            Entry to this page is a different matter,
                                            and it is not always a full load — the
                                            sidebar link is wire:navigate, because
                                            upstream turns SPA mode on for the whole
                                            panel. DOMContentLoaded has therefore
                                            already fired by the time this row
                                            arrives, which is why
                                            linkvertise-fullscript.blade.php arms the
                                            vendor's handler itself instead of trusting
                                            the event.

                                            It is also the only anchor on the page the
                                            whitelist matches — see
                                            EarnTokensPage::getHeaderActions().
                                        --}}
                                        <x-filament::button
                                            tag="a"
                                            :href="$provider['url']"
                                            :spa-mode="false"
                                            :color="$loop->first ? 'primary' : 'gray'"
                                            class="w-full justify-center">
                                            {{ trans('plugged-host-economy::strings.tokens_earn_with', ['provider' => $provider['label']]) }}
                                        </x-filament::button>
                                    @else
                                        <x-filament::badge color="gray" size="sm">
                                            {{ trans('plugged-host-economy::strings.tokens_link_unavailable') }}
                                        </x-filament::badge>
                                    @endif
                                @else
                                    <x-filament::button
                                        wire:click="earn(@js($provider['key']))"
                                        wire:loading.attr="disabled"
                                        :color="$loop->first ? 'primary' : 'gray'"
                                        class="w-full justify-center">
                                        {{ trans('plugged-host-economy::strings.tokens_earn_with', ['provider' => $provider['label']]) }}
                                    </x-filament::button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        @if ($transactions !== [])
            <x-filament::section :heading="trans('plugged-host-economy::strings.tokens_history')">
                <div class="ph-earn-rows flex flex-col">
                    @foreach ($transactions as $transaction)
                        <div class="ph-earn-tx">
                            <div class="flex min-w-0 flex-col">
                                <span class="ph-earn-text truncate">{{ $transaction->description }}</span>
                                <span class="ph-earn-small">{{ $transaction->created_at?->diffForHumans() }}</span>
                            </div>
                            <span @class([
                                'ph-earn-amount',
                                'ph-earn-amount-in' => $transaction->amount > 0,
                                'ph-earn-amount-out' => $transaction->amount <= 0,
                            ])>
                                {{ $transaction->amount > 0 ? '+' : '' }}{{ $transaction->amount }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
