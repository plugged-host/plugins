{{--
    Discord servers, a tab of the profile page. Built from the stores' shared classes
    (plugged-host-economy's store-styles), like the Discord rewards tab: full-width rows, what a
    server adds on the right, its Join button there while the account is not in it.

    At most one filled button: "Link Discord on noprofit.space" while there is no link, else the
    Join button of the first server the account is not in. Every other action is gray.
--}}
<div class="flex flex-col gap-6">
    @includeIf('plugged-host-economy::filament.partials.store-styles')

    @if ($status === null)
        <div class="ph-eco-card">
            <div class="flex flex-col gap-1">
                <h3 class="ph-eco-heading">{{ trans('plugged-host-discord::strings.servers.heading') }}</h3>
                <p class="ph-eco-text max-w-2xl">{{ trans('plugged-host-discord::strings.servers.unavailable') }}</p>
            </div>
        </div>
    @elseif (! $status['linked'])
        <div class="ph-eco-card">
            <div class="flex flex-col gap-1">
                <h3 class="ph-eco-heading">{{ trans('plugged-host-discord::strings.servers.heading') }}</h3>
                <p class="ph-eco-text max-w-2xl">{{ trans('plugged-host-discord::strings.servers.unlinked_body', ['per_server' => $perServer, 'all' => $allServers]) }}</p>
            </div>

            @if ($linkUrl)
                <div class="flex flex-wrap gap-3">
                    <x-filament::button :href="$linkUrl" tag="a">
                        {{ trans('plugged-host-discord::strings.rewards.link_on_noprofit') }}
                    </x-filament::button>
                </div>
            @endif
        </div>
    @else
        <div class="ph-eco-card">
            <div class="flex flex-col gap-1">
                <span class="ph-eco-heading">{{ trans('plugged-host-discord::strings.servers.bonus') }}</span>
                <span class="ph-eco-value">+{{ $bonus }}</span>
                <p class="ph-eco-muted">{{ trans('plugged-host-discord::strings.servers.bonus_of', ['max' => $maxBonus, 'joined' => $joined, 'total' => count($servers)]) }}</p>
            </div>

            <div class="flex flex-wrap gap-3">
                <x-filament::button wire:click="checkNow" wire:loading.attr="disabled" wire:target="checkNow" color="gray">
                    {{ trans('plugged-host-discord::strings.servers.check') }}
                </x-filament::button>
            </div>
        </div>

        <section class="flex flex-col gap-3">
            <div class="flex flex-col gap-1">
                <h3 class="ph-eco-heading">{{ trans('plugged-host-discord::strings.servers.list_heading') }}</h3>
                <p class="ph-eco-muted max-w-2xl">{{ trans('plugged-host-discord::strings.servers.rule', ['per_server' => $perServer, 'all' => $allServers]) }}</p>
            </div>

            @php($primaryUsed = false)
            <div class="ph-eco-list">
                @foreach ($servers as $server)
                    <div class="ph-eco-tx">
                        <div class="flex min-w-0 flex-col">
                            <span class="ph-eco-text">{{ $server['name'] }}</span>
                            <span class="ph-eco-small">
                                {{ trans($server['joined'] ? 'plugged-host-discord::strings.servers.joined' : 'plugged-host-discord::strings.servers.not_joined') }}
                                @if ($server['boosts'] > 0)
                                    · {{ trans_choice('plugged-host-discord::strings.servers.boosting', $server['boosts'], ['count' => $server['boosts']]) }}
                                @endif
                            </span>
                        </div>

                        @if ($server['joined'])
                            <span class="ph-eco-amount ph-eco-amount-in">+{{ $server['gain'] }}</span>
                        @elseif ($server['invite_url'] !== '')
                            <x-filament::button :href="$server['invite_url']" tag="a" target="_blank" rel="noopener" size="sm" :color="$primaryUsed ? 'gray' : 'primary'">
                                {{ trans('plugged-host-discord::strings.servers.join') }}
                            </x-filament::button>
                            @php($primaryUsed = true)
                        @else
                            <span class="ph-eco-amount ph-eco-amount-out">+{{ $server['gain'] }}</span>
                        @endif
                    </div>
                @endforeach
            </div>

            <p class="ph-eco-small max-w-2xl">{{ trans('plugged-host-discord::strings.servers.how') }}</p>
        </section>
    @endif
</div>
