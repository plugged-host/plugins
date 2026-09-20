{{--
    Where tokens are earned. The balance is printed here as context, not as a feature
    of this page — plugged-host-economy owns the ledger and the queue page shows the
    same number next to the thing tokens actually buy.

    See PluggedHost\Economy\Filament\App\Pages\EarnTokensPage for why a return trip is
    verified inline rather than queued, and why the client is trusted with nothing
    but a nonce.
--}}
<x-filament-panels::page>
    <div class="flex flex-col gap-6">
        <x-filament::section class="rounded-2xl">
            <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-5">
                    <div class="flex size-14 shrink-0 items-center justify-center rounded-full bg-gray-100 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                        <x-filament::icon icon="tabler-bolt" class="size-6 text-primary-600 dark:text-primary-400" />
                    </div>

                    <div class="flex min-w-0 flex-col gap-1.5">
                        <span class="text-lg font-semibold tracking-tight text-gray-950 dark:text-white">
                            {{ trans('plugged-host-economy::strings.tokens_balance', ['count' => $balance]) }}
                        </span>
                        <p class="text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                            {{ trans('plugged-host-economy::strings.tokens_explainer', [
                                'cost' => $priorityCost,
                                'days' => $priorityDays,
                            ]) }}
                        </p>
                    </div>
                </div>
            </div>
        </x-filament::section>

        @if ($providers === [])
            <x-filament::section class="rounded-2xl">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ trans('plugged-host-economy::strings.tokens_none') }}
                </p>
            </x-filament::section>
        @else
            {{--
                Linkvertise is the only provider wave 5 ships, and a two-column grid
                holding one card leaves half a row of empty space beside it. So the
                split only happens once there is a second card to put there, and a lone
                card stretches its button across the full width instead of parking a
                small one at the bottom-left of a wide box.
            --}}
            @php($single = count($providers) === 1)

            <div @class(['grid gap-4', 'sm:grid-cols-2' => !$single])>
                @foreach ($providers as $provider)
                    <x-filament::section class="rounded-2xl">
                        <div class="flex h-full flex-col gap-4">
                            <div class="flex flex-col gap-1.5">
                                <span class="text-base font-semibold text-gray-950 dark:text-white">
                                    {{ $provider['label'] }}
                                </span>
                                <span class="text-sm text-gray-500 dark:text-gray-400">
                                    {{ trans('plugged-host-economy::strings.tokens_reward', ['count' => $provider['reward']]) }}
                                </span>
                                @if ($provider['cap'] > 0)
                                    <span class="text-xs tabular-nums text-gray-400 dark:text-gray-500">
                                        {{ trans('plugged-host-economy::strings.tokens_used', [
                                            'used' => $provider['used'],
                                            'cap' => $provider['cap'],
                                        ]) }}
                                    </span>
                                @endif
                            </div>

                            <div class="mt-auto">
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
                                            already fired by the time this card
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
                                            icon="tabler-external-link"
                                            :class="$single ? 'w-full justify-center' : ''">
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
                                        icon="tabler-external-link"
                                        :class="$single ? 'w-full justify-center' : ''">
                                        {{ trans('plugged-host-economy::strings.tokens_earn_with', ['provider' => $provider['label']]) }}
                                    </x-filament::button>
                                @endif
                            </div>
                        </div>
                    </x-filament::section>
                @endforeach
            </div>
        @endif

        @if ($transactions !== [])
            <x-filament::section class="rounded-2xl" :heading="trans('plugged-host-economy::strings.tokens_history')">
                <div class="divide-y divide-gray-950/5 dark:divide-white/10">
                    @foreach ($transactions as $transaction)
                        <div class="flex items-center justify-between gap-4 py-2.5 first:pt-0 last:pb-0">
                            <div class="flex min-w-0 flex-col">
                                <span class="truncate text-sm text-gray-950 dark:text-white">
                                    {{ $transaction->description }}
                                </span>
                                <span class="text-xs text-gray-400 dark:text-gray-500">
                                    {{ $transaction->created_at?->diffForHumans() }}
                                </span>
                            </div>
                            <span @class([
                                'shrink-0 text-sm font-semibold tabular-nums',
                                'text-success-600 dark:text-success-400' => $transaction->amount > 0,
                                'text-gray-500 dark:text-gray-400' => $transaction->amount <= 0,
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
