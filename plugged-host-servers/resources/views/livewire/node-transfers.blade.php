{{--
    Transfers tab on the admin node page (NodeTransfers). Polls every two seconds while anything
    moves or waits. Same look as the server page's Transfers tab (shared styles partial): blocks
    split by a hairline, flat bars, Filament buttons and badges.
--}}
@php
    $t = fn (string $key, array $replace = []) => trans('plugged-host-servers::strings.transfers.' . $key, $replace);
    $node = fn (?int $id) => $id ? ($nodes[$id] ?? "#$id") : '—';
    $serverUrl = fn (\PluggedHost\Servers\Models\ServerRelocation $r) => \App\Filament\Admin\Resources\Servers\Pages\EditServer::getUrl(['record' => $r->server_id], panel: 'admin') . '?tab=ph-transfers';
    $serverName = fn (\PluggedHost\Servers\Models\ServerRelocation $r) => $r->server?->name ?? "#{$r->server_id}";
    $duration = fn (int $s) => \Carbon\CarbonInterval::seconds(max(1, $s))->cascade()->forHumans(['short' => true, 'parts' => 2]);
    $tz = auth()->user()?->timezone ?: config('app.timezone');
    $tone = fn (string $state) => match ($state) {
        'completed' => 'success', 'failed' => 'danger', 'stuck' => 'warning', 'cancelled' => 'gray', default => 'info',
    };
@endphp
<div class="pha" @if ($moving->isNotEmpty() || $waiting->isNotEmpty()) wire:poll.2s @endif>
    @include('plugged-host-servers::livewire.partials.transfer-admin-styles')

    @if (!$allowed)
        <p class="pha-empty">{{ $t('blocked.permission') }}</p>
    @else
        <div class="pha-block">
            <div class="pha-title">{{ $t('lighten.moving') }} @if ($moving->isNotEmpty())<span class="pha-pct font-mono">{{ $moving->count() }}</span>@endif</div>
            @if ($moving->isEmpty())
                <p class="pha-empty">{{ $t('lighten.nothing_moving') }}</p>
            @else
                <div class="pha-moves">
                    @foreach ($moving as $move)
                        @php $pct = $move->percent(); @endphp
                        <div class="pha-move" wire:key="phn-m-{{ $move->getKey() }}">
                            <div class="pha-head">
                                <a href="{{ $serverUrl($move) }}">{{ $serverName($move) }}</a>
                                <div class="pha-route">
                                    <span class="font-mono">{{ $node($move->from_node_id) }}</span>
                                    <span class="pha-arrow" aria-hidden="true">→</span>
                                    <span class="font-mono">{{ $node($move->to_node_id) }}</span>
                                    <span>· {{ $t('state.' . $move->headlineKey()) }}</span>
                                    <span class="pha-pct font-mono">{{ $pct }}%</span>
                                </div>
                            </div>
                            <div class="pha-bar"><i style="width: {{ $pct }}%"></i></div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        @if ($waiting->isNotEmpty())
            <div class="pha-block">
                <div class="pha-head">
                    <div class="pha-title">{{ $t('lighten.waiting') }} <span class="pha-pct font-mono">{{ $waiting->count() }}</span></div>
                    <x-filament::button color="gray" size="sm" wire:click="withdrawWaiting" wire:confirm="{{ $t('lighten.withdraw_confirm') }}">{{ $t('lighten.withdraw') }}</x-filament::button>
                </div>
                <p class="pha-note">{{ $t('lighten.waiting_hint', ['parallel' => $parallel]) }}</p>
                <table class="pha-table">
                    <thead>
                        <tr>
                            <th>{{ $t('lighten.server') }}</th>
                            <th>{{ $t('to') }}</th>
                            <th class="pha-hide-sm">{{ $t('lighten.queued_at') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($waiting->take(15) as $wait)
                            <tr wire:key="phn-w-{{ $wait->getKey() }}">
                                <td><a href="{{ $serverUrl($wait) }}">{{ $serverName($wait) }}</a></td>
                                <td class="font-mono">{{ $node(((array) $wait->info('only', []))[0] ?? null) }}</td>
                                <td class="pha-hide-sm">{{ $wait->created_at?->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if ($waiting->count() > 15)
                    <p class="pha-note">{{ $t('lighten.more_waiting', ['count' => $waiting->count() - 15]) }}</p>
                @endif
            </div>
        @endif

        <div class="pha-block">
            <div class="pha-title">{{ $t('lighten.recent') }}</div>
            @if ($recent->isEmpty())
                <p class="pha-empty">{{ $t('lighten.no_recent') }}</p>
            @else
                <table class="pha-table">
                    <thead>
                        <tr>
                            <th>{{ $t('admin.when') }}</th>
                            <th>{{ $t('lighten.server') }}</th>
                            <th class="pha-hide-sm">{{ $t('admin.route') }}</th>
                            <th>{{ $t('admin.result') }}</th>
                            <th class="pha-hide-sm">{{ $t('admin.took') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recent as $past)
                            @php
                                $skipped = $past->info('skipped');
                                $began = (int) ($past->info('turn_at') ?? $past->created_at?->getTimestamp());
                            @endphp
                            <tr wire:key="phn-r-{{ $past->getKey() }}">
                                <td>{{ $past->finished_at?->setTimezone($tz)->isoFormat('lll') }}</td>
                                <td><a href="{{ $serverUrl($past) }}">{{ $serverName($past) }}</a></td>
                                <td class="pha-hide-sm">{{ $node($past->from_node_id) }} → {{ $node($past->to_node_id ?? (((array) $past->info('only', []))[0] ?? null)) }}</td>
                                <td>
                                    <x-filament::badge :color="$skipped ? 'gray' : $tone($past->state)" size="sm">{{ $skipped ? $t('history_state.skipped') : $t('history_state.' . $past->state) }}</x-filament::badge>
                                    @if ($skipped)
                                        <div class="pha-empty">{{ $t('reasons.skipped_' . $skipped) }}</div>
                                    @elseif ($past->error && $past->state !== 'completed' && $past->state !== 'cancelled')
                                        <div class="pha-empty">{{ $t('reasons.' . $past->error) }}</div>
                                    @endif
                                </td>
                                <td class="pha-hide-sm">{{ $past->finished_at && !$skipped ? $duration($past->finished_at->getTimestamp() - $began) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif
</div>
