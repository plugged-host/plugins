{{--
    Transfers tab on the admin server page (AdminTransfers). Polls every two seconds while a
    move runs. Scoped plain CSS on neutral colours, so it needs no asset build.
--}}
@php
    $t = fn (string $key, array $replace = []) => trans('plugged-host-servers::strings.transfers.' . $key, $replace);
    $icon = fn (string $name, string $class = 'pha-i') => svg($name, $class)->toHtml();
    $node = fn (?int $id) => $id ? ($nodes[$id] ?? "#$id") : '—';
    $bytes = function (int|float $b): string {
        $u = ['B', 'KB', 'MB', 'GB', 'TB']; $i = 0; $b = max(0, (float) $b);
        while ($b >= 1000 && $i < 4) { $b /= 1024; $i++; }
        return ($i === 0 || $b >= 100 ? number_format($b) : number_format($b, 1)) . ' ' . $u[$i];
    };
    $duration = fn (int $s) => \Carbon\CarbonInterval::seconds(max(1, $s))->cascade()->forHumans(['short' => true, 'parts' => 2]);
    $tz = auth()->user()?->timezone ?: config('app.timezone');
    $tone = fn (string $state) => match ($state) {
        'completed' => 'ok', 'failed', 'stuck' => 'bad', 'cancelled' => 'mute', default => 'run',
    };
@endphp
<div class="pha" @if ($active) wire:poll.2s @endif>
    <style>
        .pha { display: flex; flex-direction: column; gap: 1rem; font-size: .85rem; }
        .pha-i { width: 1rem; height: 1rem; flex: none; }
        .pha-card { border-radius: .75rem; border: 1px solid rgba(127,127,127,.2); background: rgba(127,127,127,.04); padding: 1rem; display: flex; flex-direction: column; gap: .75rem; }
        .pha-head { display: flex; align-items: center; justify-content: space-between; gap: .75rem; flex-wrap: wrap; }
        .pha-title { display: flex; align-items: center; gap: .5rem; font-weight: 700; font-size: .95rem; }
        .pha-route { display: flex; align-items: center; gap: .4rem; flex-wrap: wrap; font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: .8rem; }
        .pha-bar { height: .5rem; border-radius: 9999px; background: rgba(127,127,127,.2); overflow: hidden; }
        .pha-bar > i { display: block; height: 100%; background: linear-gradient(90deg, #3b82f6, #0ea5e9); transition: width .6s ease; }
        .pha-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: .5rem; }
        .pha-kv { padding: .5rem .65rem; border-radius: .6rem; background: rgba(127,127,127,.07); min-width: 0; }
        .pha-kv small { display: block; font-size: .68rem; opacity: .6; }
        .pha-kv strong { display: block; font-size: .82rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pha-err { padding: .55rem .7rem; border-radius: .6rem; border: 1px solid rgba(239,68,68,.3); background: rgba(239,68,68,.06); font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: .75rem; word-break: break-word; }
        .pha-btns { display: flex; gap: .5rem; flex-wrap: wrap; }
        .pha-btn { display: inline-flex; align-items: center; gap: .35rem; padding: .4rem .8rem; border-radius: .5rem; font-weight: 600; font-size: .8rem; border: 1px solid rgba(127,127,127,.3); }
        .pha-btn:hover { background: rgba(127,127,127,.1); }
        .pha-btn-danger { border-color: rgba(239,68,68,.45); color: #ef4444; }
        .pha-log { display: flex; flex-direction: column; gap: .2rem; max-height: 14rem; overflow-y: auto; }
        .pha-log div { display: flex; gap: .6rem; font-size: .78rem; }
        .pha-log time { font-family: 'JetBrains Mono', ui-monospace, monospace; opacity: .55; flex: none; }
        .pha-lv-warning { color: #d4a106; } .pha-lv-danger { color: #ef4444; } .pha-lv-success { color: #22c55e; }
        .pha-chip { display: inline-flex; align-items: center; padding: .08rem .5rem; border-radius: 9999px; font-size: .7rem; font-weight: 600; background: rgba(127,127,127,.15); white-space: nowrap; }
        .pha-ok { background: rgba(34,197,94,.15); color: #22c55e; }
        .pha-bad { background: rgba(239,68,68,.13); color: #f87171; }
        .pha-run { background: rgba(59,130,246,.15); color: #60a5fa; }
        .pha-table { width: 100%; border-collapse: collapse; }
        .pha-table th { text-align: left; font-size: .7rem; font-weight: 600; opacity: .6; padding: .35rem .5rem; }
        .pha-table td { padding: .45rem .5rem; border-top: 1px solid rgba(127,127,127,.15); vertical-align: top; }
        .pha-empty { opacity: .6; }
        @media (max-width: 700px) { .pha-hide-sm { display: none; } }
    </style>

    @if (!$allowed)
        <p class="pha-empty">{{ $t('blocked.permission') }}</p>
    @else
        @if ($active)
            @php
                $pct = $active->percent();
                $eta = $active->etaSeconds();
                $tried = array_map($node, (array) $active->info('tried', []));
            @endphp
            <div class="pha-card">
                <div class="pha-head">
                    <div class="pha-title">{!! $icon('tabler-loader-2') !!} {{ $t('state.' . $active->headlineKey()) }} <span class="pha-chip pha-run">{{ $pct }}%</span></div>
                    <div class="pha-route">
                        {{ $node($active->from_node_id) }} {!! $icon('tabler-arrow-right') !!} {{ $node($active->to_node_id) }}
                        <span class="pha-chip">{{ $service->locationName($service->targetLocation($active)) }}</span>
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
                    <div class="pha-err">{{ $active->info('last_error') ?? $active->info('last_exception') }}</div>
                @endif
                <div class="pha-btns">
                    @if ($active->isCancellable())
                        <button type="button" class="pha-btn pha-btn-danger" wire:click="cancel" wire:confirm="{{ $t('cancel_heading') }} {{ $t('cancel_body') }}">{!! $icon('tabler-x') !!} {{ $t('cancel') }}</button>
                    @endif
                </div>
                <div class="pha-log">
                    @foreach ($service->timeline($active, $tz) as $event)
                        <div class="pha-lv-{{ $event['level'] }}"><time>{{ $event['at'] }}</time><span>{{ $event['text'] }}</span></div>
                    @endforeach
                </div>
            </div>
        @endif

        @foreach ($history->where('state', 'stuck') as $stuck)
            <div class="pha-card">
                <div class="pha-title">{!! $icon('tabler-alert-triangle') !!} {{ $t('admin.stuck_title', ['from' => $node($stuck->from_node_id), 'to' => $node($stuck->to_node_id)]) }}</div>
                <p>{{ $t('admin.stuck_body') }}</p>
                @if ($stuck->info('last_error'))
                    <div class="pha-err">{{ $stuck->info('last_error') }}</div>
                @endif
                <div class="pha-btns">
                    <button type="button" class="pha-btn pha-btn-danger" wire:click="release({{ $stuck->getKey() }})" wire:confirm="{{ $t('admin.release_confirm') }}">{!! $icon('tabler-arrow-back-up') !!} {{ $t('admin.release') }}</button>
                </div>
            </div>
        @endforeach

        <div class="pha-card">
            <div class="pha-title">{!! $icon('tabler-history') !!} {{ $t('history') }}</div>
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
                                    <span class="pha-chip pha-{{ $tone($past->state) }}">{{ $t('history_state.' . $past->state) }}</span>
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
