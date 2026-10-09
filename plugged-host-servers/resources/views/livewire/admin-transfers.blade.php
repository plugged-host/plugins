{{--
    Transfers tab on the admin server page (AdminTransfers). Polls every two seconds while a
    move runs. Blocks split by a hairline rather than boxed, flat bar, Filament buttons and
    badges; scoped plain CSS on the theme tokens, so it needs no asset build.
--}}
@php
    $t = fn (string $key, array $replace = []) => trans('plugged-host-servers::strings.transfers.' . $key, $replace);
    $node = fn (?int $id) => $id ? ($nodes[$id] ?? "#$id") : '—';
    $bytes = function (int|float $b): string {
        $u = ['B', 'KB', 'MB', 'GB', 'TB']; $i = 0; $b = max(0, (float) $b);
        while ($b >= 1000 && $i < 4) { $b /= 1024; $i++; }
        return ($i === 0 || $b >= 100 ? number_format($b) : number_format($b, 1)) . ' ' . $u[$i];
    };
    $duration = fn (int $s) => \Carbon\CarbonInterval::seconds(max(1, $s))->cascade()->forHumans(['short' => true, 'parts' => 2]);
    $tz = auth()->user()?->timezone ?: config('app.timezone');
    $tone = fn (string $state) => match ($state) {
        'completed' => 'success', 'failed' => 'danger', 'stuck' => 'warning', 'cancelled' => 'gray', default => 'info',
    };
@endphp
<div class="pha" @if ($active) wire:poll.2s @endif>
    @include('plugged-host-servers::livewire.partials.transfer-admin-styles')

    @if (!$allowed)
        <p class="pha-empty">{{ $t('blocked.permission') }}</p>
    @else
        @if ($active)
            @php
                $pct = $active->percent();
                $eta = $active->etaSeconds();
                $tried = array_map($node, (array) $active->info('tried', []));
            @endphp
            <div class="pha-block">
                <div class="pha-head">
                    <div class="pha-title">{{ $t('state.' . $active->headlineKey()) }} <span class="pha-pct font-mono">{{ $pct }}%</span></div>
                    <div class="pha-route">
                        <span class="font-mono">{{ $node($active->from_node_id) }}</span>
                        <span class="pha-arrow" aria-hidden="true">→</span>
                        <span class="font-mono">{{ $node($active->to_node_id) }}</span>
                        <x-filament::badge color="gray" size="sm">{{ $service->locationName($service->targetLocation($active)) }}</x-filament::badge>
                    </div>
                </div>
                <div class="pha-bar"><i style="width: {{ $pct }}%"></i></div>
                <div class="pha-grid">
                    <div class="pha-kv"><small>{{ $t('admin.origin') }}</small><strong>{{ $t('admin.origins.' . $active->origin) }}{{ $active->user ? ' · ' . $active->user->username : '' }}</strong></div>
                    <div class="pha-kv"><small>{{ $t('attempt') }}</small><strong>{{ $t('attempt_value', ['attempt' => $active->attempt, 'max' => $active->max_attempts]) }}</strong></div>
                    @if ($active->bytes_total > 0)
                        <div class="pha-kv"><small>{{ $t('data') }}</small><strong>{{ $bytes(min($active->bytes_done, $active->bytes_total)) }} / {{ $bytes($active->bytes_total) }}</strong></div>
                    @endif
                    @if ($active->rate > 0)
                        <div class="pha-kv"><small>{{ $t('speed') }}</small><strong>{{ $bytes($active->rate) }}/s</strong></div>
                    @endif
                    @if ($eta !== null)
                        <div class="pha-kv"><small>{{ $t('eta') }}</small><strong>~{{ $duration($eta) }}</strong></div>
                    @endif
                    @if ($active->backups_total > 0)
                        <div class="pha-kv"><small>{{ $t('backups') }}</small><strong>{{ $active->backups_done }} / {{ $active->backups_total }} · {{ $bytes($active->backup_bytes) }}</strong></div>
                    @endif
                    <div class="pha-kv"><small>{{ $t('admin.live') }}</small><strong>{{ $active->socket_at ? $active->socket_at->diffForHumans() : $t('admin.not_connected') }}</strong></div>
                    @if ($tried !== [])
                        <div class="pha-kv"><small>{{ $t('admin.tried') }}</small><strong>{{ implode(', ', $tried) }}</strong></div>
                    @endif
                </div>
                @if ($active->info('last_error') || $active->info('last_exception'))
                    <div class="pha-err font-mono">{{ $active->info('last_error') ?? $active->info('last_exception') }}</div>
                @endif
                @if ($active->isCancellable())
                    <div class="pha-btns">
                        <x-filament::button color="gray" size="sm" wire:click="cancel" wire:confirm="{{ $t('cancel_heading') }} {{ $t('cancel_body') }}">{{ $t('cancel') }}</x-filament::button>
                    </div>
                @endif
                <div class="pha-log">
                    @foreach ($service->timeline($active, $tz) as $event)
                        <div class="pha-lv-{{ $event['level'] }}"><time class="font-mono">{{ $event['at'] }}</time><span>{{ $event['text'] }}</span></div>
                    @endforeach
                </div>
            </div>
        @endif

        @foreach ($history->where('state', 'stuck') as $stuck)
            <div class="pha-block">
                <div class="pha-title pha-title-warning">{{ $t('admin.stuck_title', ['from' => $node($stuck->from_node_id), 'to' => $node($stuck->to_node_id)]) }}</div>
                <p class="pha-note">{{ $t('admin.stuck_body') }}</p>
                @if ($stuck->info('last_error'))
                    <div class="pha-err font-mono">{{ $stuck->info('last_error') }}</div>
                @endif
                <div class="pha-btns">
                    <x-filament::button color="gray" size="sm" wire:click="release({{ $stuck->getKey() }})" wire:confirm="{{ $t('admin.release_confirm') }}">{{ $t('admin.release') }}</x-filament::button>
                </div>
            </div>
        @endforeach

        <div class="pha-block">
            <div class="pha-title">{{ $t('history') }}</div>
            @if ($history->isEmpty())
                <p class="pha-empty">{{ $active ? $t('admin.no_history_yet') : $t('admin.no_history') }}</p>
            @else
                <table class="pha-table">
                    <thead>
                        <tr>
                            <th>{{ $t('admin.when') }}</th>
                            <th>{{ $t('admin.route') }}</th>
                            <th class="pha-hide-sm">{{ $t('admin.origin') }}</th>
                            <th>{{ $t('admin.result') }}</th>
                            <th class="pha-hide-sm">{{ $t('admin.took') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $past)
                            <tr wire:key="pha-h-{{ $past->getKey() }}">
                                <td>{{ $past->created_at?->setTimezone($tz)->isoFormat('lll') }}</td>
                                <td>{{ $node($past->from_node_id) }} → {{ $node($past->to_node_id) }}</td>
                                <td class="pha-hide-sm">{{ $t('admin.origins.' . $past->origin) }}{{ $past->user ? ' · ' . $past->user->username : '' }}</td>
                                <td>
                                    <x-filament::badge :color="$tone($past->state)" size="sm">{{ $t('history_state.' . $past->state) }}</x-filament::badge>
                                    @if ($past->error && $past->state !== 'completed' && $past->state !== 'cancelled')
                                        <div class="pha-empty">{{ $t('reasons.' . $past->error) }}</div>
                                    @endif
                                </td>
                                <td class="pha-hide-sm">{{ $past->finished_at ? $duration($past->finished_at->getTimestamp() - $past->created_at->getTimestamp()) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif
</div>
