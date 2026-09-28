{{--
    Earning tokens, as it appears on plugged-host-economy's Token Store.

    Rendered into the `plugged-host-economy::store.after-tokens` hook from
    AdsPluginServiceProvider, directly under the token wallet card, so that the balance,
    what it buys and how to get more all read as one page. Economy knows nothing about
    this: the hook name is a literal on both sides, and with ads uninstalled the hook
    renders nothing.

    **There is no sponsored anchor here, and there must not be one.** In Full Script mode
    a Linkvertise link only pays if linkvertise.js rewrote it in the browser, and that
    script is loaded on EarnTokensPage alone — deliberately, because it rewrites every
    anchor its whitelist matches and the Token Store is a page people spend on.
    An anchor rendered here would look right, open, and earn nothing. So every row links
    to EarnTokensPage, which is also the only URL that can settle a return trip.

    A plain view rather than a Livewire component, which is the opposite of the topbar
    chips: this is rendered *inside* TokenStorePage's own component, so it is re-rendered
    with the page on every round trip and is never stale. A nested component would be
    isolated from those updates and would still be showing yesterday's cap after a
    purchase re-rendered everything around it.

    One card, one full-width row per provider (no card inside the card). Buttons are
    gray: the Token Store keeps its single filled button for the priority offer.
--}}
<style>
    .ph-earn-card { display: flex; flex-direction: column; gap: 0.5rem; padding: 1rem; border-radius: 1rem; background-color: var(--phv2-raise); }
    .ph-earn-heading { font-size: 1rem; font-weight: 600; line-height: 1.5rem; color: var(--phv2-text); }
    .ph-earn-title { font-size: 0.875rem; font-weight: 600; line-height: 1.25rem; color: var(--phv2-text); }
    .ph-earn-muted { font-size: 0.8125rem; line-height: 1.25rem; color: var(--phv2-text-muted); }
    .ph-earn-small { font-size: 0.75rem; line-height: 1.125rem; font-variant-numeric: tabular-nums; color: var(--phv2-text-faint); }
    .ph-earn-row { display: flex; flex-direction: column; gap: 0.75rem; padding-block: 1rem; }
    .ph-earn-row:last-child { padding-bottom: 0; }
    .ph-earn-row + .ph-earn-row { border-top: 1px solid var(--phv2-hairline); }
    .ph-earn-action { display: flex; width: 100%; }
    @media (min-width: 640px) {
        .ph-earn-card { padding: 1.5rem; }
        .ph-earn-row { flex-direction: row; align-items: center; justify-content: space-between; gap: 1.5rem; }
        .ph-earn-action { width: 15rem; flex-shrink: 0; justify-content: flex-end; }
    }
</style>

<div class="ph-earn-card">
    <div class="flex flex-col gap-1">
        <h2 class="ph-earn-heading">{{ trans('plugged-host-economy::strings.tokens_store_heading') }}</h2>
        <p class="ph-earn-muted">{{ trans('plugged-host-economy::strings.tokens_store_hint') }}</p>
    </div>

    @if ($providers === [])
        <p class="ph-earn-muted">{{ trans('plugged-host-economy::strings.tokens_none') }}</p>
    @else
        <div class="flex flex-col">
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
                                color="gray"
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
