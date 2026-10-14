{{--
    The live player list (PlayersPage). One full-width row per player, polled while the page is
    open. A status line on top says whether the server answered and how full it is; the search
    filters by name, server id or identifier. Scoped plain CSS on theme-v2's --phv2-* tokens.
--}}
@php
    $t = fn (string $key, array $replace = []) => trans('plugged-host-fivem::strings.players.' . $key, $replace);
    $online = (bool) ($this->snapshot['online'] ?? false);
    $players = $this->players();
    $slots = $this->snapshot['slots'] ?? null;
    $clients = (int) ($this->snapshot['clients'] ?? 0);
@endphp
<x-filament-panels::page>
    <style>
        .phf-players { display: flex; flex-direction: column; gap: 1rem; }
        .phf-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem 1rem; }
        .phf-state { display: flex; align-items: center; gap: .5rem; font-size: .875rem; font-weight: 600; color: var(--phv2-text); }
        .phf-dot { width: .5rem; height: .5rem; border-radius: 9999px; background: var(--phv2-text-faint); }
        .phf-dot.phf-on { background: var(--success-400); }
        .phf-count { font-weight: 400; color: var(--phv2-text-muted); font-variant-numeric: tabular-nums; }
        .phf-search { width: 100%; max-width: 20rem; }
        .phf-list { display: flex; flex-direction: column; gap: .5rem; }
        .phf-row { display: grid; grid-template-columns: 3.5rem minmax(0, 1fr) auto; align-items: center; gap: 1rem; padding: .875rem 1.25rem; border-radius: .75rem; background: var(--phv2-raise); }
        .phf-who { min-width: 0; }
        .phf-id { font-family: var(--mono-font-family, 'JetBrains Mono'), ui-monospace, monospace; font-size: .8125rem; color: var(--phv2-text-muted); font-variant-numeric: tabular-nums; }
        .phf-name { font-weight: 600; font-size: .9375rem; color: var(--phv2-text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .phf-ids { display: flex; flex-wrap: wrap; gap: .25rem .75rem; margin-top: .25rem; font-family: var(--mono-font-family, 'JetBrains Mono'), ui-monospace, monospace; font-size: .75rem; color: var(--phv2-text-muted); }
        .phf-ping { font-size: .8125rem; color: var(--phv2-text-muted); font-variant-numeric: tabular-nums; white-space: nowrap; }
        .phf-ping.phf-slow { color: #fbbf24; }
        .phf-empty { padding: 3rem 1rem; text-align: center; border-radius: .75rem; background: var(--phv2-raise); }
        .phf-empty strong { display: block; font-size: .9375rem; color: var(--phv2-text); margin-bottom: .25rem; }
        .phf-empty span { font-size: .875rem; color: var(--phv2-text-muted); }
        @media (max-width: 640px) {
            .phf-row { grid-template-columns: 2.5rem minmax(0, 1fr) auto; gap: .75rem; padding: .75rem 1rem; }
            .phf-search { max-width: none; }
        }
    </style>

    <div class="phf-players" wire:poll.{{ $this->pollSeconds() }}s="refreshPlayers">
        <div class="phf-bar">
            <div class="phf-state">
                <span class="phf-dot {{ $online ? 'phf-on' : '' }}"></span>
                {{ $online ? $t('online') : $t('offline') }}
                @if($online)
                    <span class="phf-count">{{ $slots ? $t('count_slots', ['count' => $clients, 'slots' => $slots]) : trans_choice('plugged-host-fivem::strings.players.count', $clients, ['count' => $clients]) }}</span>
                @endif
            </div>
            @if($online && $clients > 0)
                <div class="phf-search">
                    <x-filament::input.wrapper prefix-icon="tabler-search">
                        <x-filament::input type="search" wire:model.live.debounce.300ms="search" :placeholder="$t('search')" />
                    </x-filament::input.wrapper>
                </div>
            @endif
        </div>

        @if(!$online)
            <div class="phf-empty">
                <strong>{{ $t('unreachable') }}</strong>
                <span>{{ $t('unreachable_body') }}</span>
            </div>
        @elseif($players === [])
            <div class="phf-empty">
                <strong>{{ $this->search !== '' ? $t('no_match') : $t('nobody') }}</strong>
                <span>{{ $this->search !== '' ? $t('no_match_body') : $t('nobody_body') }}</span>
            </div>
        @else
            <div class="phf-list">
                @foreach($players as $player)
                    <div class="phf-row" wire:key="phf-player-{{ $player['id'] }}">
                        <span class="phf-id">#{{ $player['id'] }}</span>
                        <div class="phf-who">
                            <div class="phf-name">{{ $player['name'] !== '' ? $player['name'] : $t('unnamed') }}</div>
                            @if($player['identifiers'] !== [])
                                <div class="phf-ids">
                                    @foreach($player['identifiers'] as $identifier)
                                        <span>{{ $identifier }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <span class="phf-ping {{ $player['ping'] >= 150 ? 'phf-slow' : '' }}">{{ $t('ping', ['ping' => $player['ping']]) }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
