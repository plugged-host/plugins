{{--
    Earning tokens, as it appears on plugged-host-economy's Economy page.

    Rendered into the `plugged-host-economy::store.after-tokens` hook from
    AdsPluginServiceProvider, directly under the token wallet card, so that the balance,
    what it buys and how to get more all read as one page. Economy knows nothing about
    this: the hook name is a literal on both sides, and with ads uninstalled the hook
    renders nothing.

    **There is no sponsored anchor here, and there must not be one.** In Full Script mode
    a Linkvertise link only pays if linkvertise.js rewrote it in the browser, and that
    script is loaded on EarnTokensPage alone — deliberately, because it rewrites every
    anchor its whitelist matches and the Economy page is the page people spend money on.
    An anchor rendered here would look right, open, and earn nothing. So every card links
    to EarnTokensPage, which is also the only URL that can settle a return trip.

    A plain view rather than a Livewire component, which is the opposite of the topbar
    chips: this is rendered *inside* ResourceStorePage's own component, so it is re-rendered
    with the page on every round trip and is never stale. A nested component would be
    isolated from those updates and would still be showing yesterday's cap after a
    purchase re-rendered everything around it.
--}}
@php($single = count($providers) === 1)

<div class="flex flex-col gap-5 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 sm:gap-6 sm:p-6 dark:bg-gray-900 dark:ring-white/10">
    <div class="flex flex-col gap-1.5">
        <h2 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
            {{ trans('plugged-host-economy::strings.tokens_store_heading') }}
        </h2>
        <p class="text-sm leading-relaxed text-gray-500 dark:text-gray-400">
            {{ trans('plugged-host-economy::strings.tokens_store_hint') }}
        </p>
    </div>

    @if ($providers === [])
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ trans('plugged-host-economy::strings.tokens_none') }}
        </p>
    @else
        {{--
            One provider is the normal case, and a two-column grid holding a single card
            leaves half a row of empty space beside it — same reasoning as the earn page.
        --}}
        <div @class(['grid grid-cols-1 gap-4', 'sm:grid-cols-2' => !$single])>
            @foreach ($providers as $provider)
                <div class="flex h-full flex-col gap-4 rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                    <div class="flex flex-col gap-1">
                        <span class="text-sm font-semibold text-gray-950 dark:text-white">
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

                    {{-- mt-auto so every card's button sits on one baseline --}}
                    <div class="mt-auto">
                        @if ($provider['capped'])
                            <x-filament::badge color="gray" size="sm">
                                {{ trans('plugged-host-economy::strings.tokens_capped') }}
                            </x-filament::badge>
                        @elseif ($earnUrl === null)
                            <x-filament::badge color="gray" size="sm">
                                {{ trans('plugged-host-economy::strings.tokens_link_unavailable') }}
                            </x-filament::badge>
                        @else
                            {{--
                                Internal link, so wire:navigate is fine and left on: the
                                destination is our own page, and the anchor it renders is
                                armed by linkvertise-fullscript.blade.php rather than by
                                DOMContentLoaded, which has long since fired by then.
                            --}}
                            <x-filament::button
                                tag="a"
                                :href="$earnUrl"
                                icon="tabler-external-link"
                                color="info"
                                class="w-full justify-center"
                            >
                                {{ trans('plugged-host-economy::strings.tokens_earn_with', ['provider' => $provider['label']]) }}
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
