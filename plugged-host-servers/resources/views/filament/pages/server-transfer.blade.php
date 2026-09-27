{{--
    Transfer page (ServerTransferPage). Two faces: while a move runs, a progress card (route,
    bar, stepper, live tiles, timeline) that polls every two seconds; otherwise the current
    location, one full-width row per location to pick from, and the Move button. Scoped plain
    CSS on neutral colours, so it needs no asset build.
--}}
@php
    $t = fn (string $key, array $replace = []) => trans('plugged-host-servers::strings.transfers.' . $key, $replace);
    $icon = fn (string $name, string $class = 'pht-i') => svg($name, $class)->toHtml();
    $svc = $this->service();
    $levelIcon = fn (string $level) => match ($level) {
        'success' => 'tabler-circle-check',
        'warning' => 'tabler-alert-triangle',
        'danger' => 'tabler-circle-x',
        default => 'tabler-point',
    };
@endphp
<x-filament-panels::page>
    <style>
        .pht { display: flex; flex-direction: column; gap: 1rem; }
        .pht-i { width: 1rem; height: 1rem; flex: none; }
        .pht-card { border-radius: 1rem; border: 1px solid rgba(127,127,127,.2); background: rgba(127,127,127,.05); padding: 1.1rem 1.25rem; }
        .pht-hero { border-color: rgba(59,130,246,.4); background: linear-gradient(135deg, rgba(59,130,246,.08), rgba(14,165,233,.04)); display: flex; flex-direction: column; gap: 1rem; }
        .pht-route { display: grid; grid-template-columns: minmax(0,1fr) auto minmax(0,1fr); align-items: center; gap: .75rem; }
        .pht-place { display: flex; align-items: center; gap: .65rem; min-width: 0; }
        .pht-place:last-child { justify-content: flex-end; text-align: right; }
        .pht-place small { display: block; font-size: .68rem; opacity: .6; text-transform: uppercase; letter-spacing: .04em; }
        .pht-place strong { display: block; font-size: 1rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .pht-flag { width: 2.25rem; height: 1.7rem; flex: none; border-radius: .35rem; overflow: hidden; display: grid; place-items: center; background: rgba(127,127,127,.18); box-shadow: 0 1px 3px rgba(0,0,0,.25); }
        .pht-flag svg { width: 100%; height: 100%; display: block; }
        .pht-arrow { width: 2.6rem; height: 2.6rem; border-radius: 9999px; display: grid; place-items: center; background: rgba(59,130,246,.16); color: #60a5fa; }
        .pht-arrow .pht-i { width: 1.35rem; height: 1.35rem; }
        .pht-moving .pht-arrow .pht-i { animation: pht-drive 1.4s ease-in-out infinite; }
        @keyframes pht-drive { 0%,100% { transform: translateX(-2px); } 50% { transform: translateX(3px); } }
        .pht-status { display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; }
        .pht-headline { display: flex; align-items: center; gap: .5rem; font-weight: 700; font-size: 1.05rem; }
        .pht-spin { animation: pht-spin 1s linear infinite; }
        @keyframes pht-spin { to { transform: rotate(360deg); } }
        .pht-pct { font-family: 'JetBrains Mono', ui-monospace, monospace; font-weight: 700; font-size: 1.6rem; line-height: 1; }
        .pht-bar { height: .6rem; border-radius: 9999px; background: rgba(127,127,127,.2); overflow: hidden; }
        .pht-bar > i { display: block; height: 100%; border-radius: 9999px; background: linear-gradient(90deg, #3b82f6, #0ea5e9); transition: width .6s ease; }
        .pht-moving .pht-bar > i { background-size: 200% 100%; background-image: linear-gradient(90deg, #3b82f6, #0ea5e9, #3b82f6); animation: pht-flow 2s linear infinite; }
        @keyframes pht-flow { to { background-position: -200% 0; } }
        .pht-steps { display: grid; grid-auto-flow: column; grid-auto-columns: minmax(0,1fr); gap: .35rem; }
        .pht-step { display: flex; flex-direction: column; align-items: center; gap: .3rem; font-size: .68rem; text-align: center; opacity: .45; }
        .pht-step span { width: 2rem; height: 2rem; border-radius: 9999px; display: grid; place-items: center; background: rgba(127,127,127,.16); }
        .pht-step-done { opacity: .9; }
        .pht-step-done span { background: rgba(34,197,94,.16); color: #22c55e; }
        .pht-step-current { opacity: 1; font-weight: 600; }
        .pht-step-current span { background: #3b82f6; color: #fff; box-shadow: 0 0 0 4px rgba(59,130,246,.2); }
        .pht-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(8.5rem, 1fr)); gap: .55rem; }
        .pht-tile { display: flex; align-items: center; gap: .6rem; padding: .6rem .75rem; border-radius: .8rem; background: rgba(127,127,127,.08); border: 1px solid rgba(127,127,127,.12); min-width: 0; }
        .pht-tile > .pht-i { width: 1.15rem; height: 1.15rem; opacity: .7; }
        .pht-tile div { min-width: 0; }
        .pht-tile small { display: block; font-size: .66rem; opacity: .6; }
        .pht-tile strong { display: block; font-size: .85rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .pht-mono { font-family: 'JetBrains Mono', ui-monospace, monospace; }
        .pht-foot { display: flex; align-items: center; justify-content: space-between; gap: .75rem; flex-wrap: wrap; }
        .pht-safe { display: flex; align-items: flex-start; gap: .5rem; font-size: .78rem; opacity: .75; flex: 1 1 18rem; }
        .pht-safe .pht-i { color: #22c55e; margin-top: .1rem; }
        .pht-h { display: flex; align-items: center; gap: .45rem; font-size: .8rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; opacity: .7; margin-bottom: .55rem; }
        .pht-list { display: flex; flex-direction: column; gap: .5rem; }
        .pht-row { display: flex; align-items: center; gap: .85rem; width: 100%; padding: .8rem 1rem; border-radius: .9rem; border: 1px solid rgba(127,127,127,.18); background: rgba(127,127,127,.04); text-align: left; transition: border-color .15s, background .15s; }
        .pht-row:not(:disabled):hover { border-color: rgba(59,130,246,.5); background: rgba(59,130,246,.06); }
        .pht-row.is-selected { border-color: #3b82f6; background: rgba(59,130,246,.1); box-shadow: 0 0 0 3px rgba(59,130,246,.18); }
        .pht-row:disabled { opacity: .5; cursor: not-allowed; }
        .pht-row-main { flex: 1; min-width: 0; display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
        .pht-row-main strong { font-size: .95rem; }
        .pht-chip { display: inline-flex; align-items: center; gap: .25rem; font-size: .68rem; font-weight: 600; padding: .1rem .5rem; border-radius: 9999px; background: rgba(127,127,127,.15); white-space: nowrap; }
        .pht-chip .pht-i { width: .8rem; height: .8rem; }
        .pht-chip-plus { background: rgba(14,165,233,.15); color: #38bdf8; }
        .pht-chip-premium { background: rgba(234,179,8,.16); color: #d4a106; }
        .pht-chip-current { background: rgba(59,130,246,.16); color: #60a5fa; }
        .pht-chip-off { background: rgba(239,68,68,.13); color: #f87171; }
        .pht-radio { width: 1.35rem; height: 1.35rem; flex: none; border-radius: 9999px; border: 2px solid rgba(127,127,127,.4); display: grid; place-items: center; }
        .pht-row.is-selected .pht-radio { border-color: #3b82f6; background: #3b82f6; color: #fff; }
        .pht-radio .pht-i { width: .85rem; height: .85rem; }
        .pht-now { display: grid; grid-template-columns: auto minmax(0,1fr); gap: .9rem 1rem; align-items: center; }
        .pht-now .pht-flag { width: 3rem; height: 2.25rem; border-radius: .5rem; }
        .pht-now-name small { display: block; font-size: .68rem; opacity: .6; text-transform: uppercase; letter-spacing: .04em; }
        .pht-now-name strong { font-size: 1.2rem; }
        .pht-now .pht-tiles { grid-column: 1 / -1; }
        .pht-note { display: flex; align-items: center; gap: .6rem; padding: .75rem 1rem; border-radius: .8rem; border: 1px solid rgba(234,179,8,.35); background: rgba(234,179,8,.08); font-size: .85rem; }
        .pht-note .pht-i { color: #eab308; }
        .pht-cta { display: flex; }
        .pht-cta > * { flex: 1; }
        .pht-cta .pht-move { width: 100%; justify-content: center; }
        .pht-help { display: grid; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); gap: .75rem; }
        .pht-help-card { display: flex; gap: .75rem; padding: .9rem 1rem; border-radius: .9rem; border: 1px solid rgba(127,127,127,.15); background: rgba(127,127,127,.04); font-size: .78rem; line-height: 1.45; }
        .pht-help-card strong { display: block; font-size: .85rem; margin-bottom: .15rem; }
        .pht-help-card p { opacity: .7; }
        .pht-help-icon { width: 2.1rem; height: 2.1rem; flex: none; border-radius: .65rem; display: grid; place-items: center; background: rgba(59,130,246,.12); color: #60a5fa; }
        .pht-log { display: flex; flex-direction: column; gap: .3rem; max-height: 18rem; overflow-y: auto; }
        .pht-log-row { display: flex; align-items: center; gap: .55rem; padding: .4rem .65rem; border-radius: .6rem; background: rgba(127,127,127,.06); font-size: .78rem; }
        .pht-log-row span { flex: 1; min-width: 0; }
        .pht-log-row time { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: .7rem; opacity: .55; }
        .pht-lv-success .pht-i { color: #22c55e; }
        .pht-lv-warning .pht-i { color: #eab308; }
        .pht-lv-danger .pht-i { color: #ef4444; }
        .pht-lv-info .pht-i { opacity: .5; }
        .pht-result { display: flex; align-items: center; gap: .9rem; }
        .pht-result-icon { width: 2.75rem; height: 2.75rem; flex: none; border-radius: .9rem; display: grid; place-items: center; }
        .pht-result-icon .pht-i { width: 1.5rem; height: 1.5rem; }
        .pht-ok .pht-result-icon { background: rgba(34,197,94,.15); color: #22c55e; }
        .pht-ok { border-color: rgba(34,197,94,.35); }
        .pht-bad .pht-result-icon { background: rgba(234,179,8,.15); color: #eab308; }
        .pht-bad { border-color: rgba(234,179,8,.35); }
        .pht-result div { min-width: 0; flex: 1; }
        .pht-result strong { display: block; font-size: 1rem; }
        .pht-result p { font-size: .8rem; opacity: .75; }
        .pht-addr { display: inline-flex; align-items: center; gap: .35rem; margin-top: .35rem; font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: .85rem; font-weight: 600; padding: .2rem .55rem; border-radius: .45rem; background: rgba(127,127,127,.14); }
        .pht-hist { display: flex; align-items: center; gap: .6rem; padding: .55rem .8rem; border-radius: .75rem; background: rgba(127,127,127,.05); border: 1px solid rgba(127,127,127,.12); font-size: .8rem; }
        .pht-hist .pht-flag { width: 1.6rem; height: 1.2rem; border-radius: .25rem; }
        .pht-hist time { margin-left: auto; font-size: .72rem; opacity: .6; white-space: nowrap; }
        @media (max-width: 640px) {
            .pht-card { padding: .95rem; }
            .pht-place small { display: none; }
            .pht-place strong { font-size: .88rem; }
            .pht-flag { width: 1.9rem; height: 1.45rem; }
            .pht-step small { display: none; }
            .pht-pct { font-size: 1.35rem; }
            .pht-headline { font-size: .95rem; }
        }
    </style>

    <div class="pht" @if ($active) wire:poll.2s @endif>
        @if ($active)
            @php
                $from = $active->info('from_location');
                $to = $svc->targetLocation($active);
                $pct = $active->percent();
                $eta = $active->etaSeconds();
                $moving = in_array($active->state, [\PluggedHost\Servers\Models\ServerRelocation::COPYING, \PluggedHost\Servers\Models\ServerRelocation::FINALIZING], true);
                $fraction = $active->copyFraction();
            @endphp

            <section class="pht-card pht-hero {{ $moving ? 'pht-moving' : '' }}">
                <div class="pht-route">
                    <div class="pht-place">
                        <span class="pht-flag">{!! $this->flag($from) !!}</span>
                        <div><small>{{ $t('from') }}</small><strong>{{ $svc->locationName($from) }}</strong></div>
                    </div>
                    <div class="pht-arrow">{!! $icon('tabler-truck-delivery') !!}</div>
                    <div class="pht-place">
                        <div><small>{{ $t('to') }}</small><strong>{{ $svc->locationName($to) }}</strong></div>
                        <span class="pht-flag">{!! $this->flag($to) !!}</span>
                    </div>
                </div>

                <div class="pht-status">
                    <div class="pht-headline">{!! $icon('tabler-loader-2', 'pht-i pht-spin') !!} {{ $this->headline($active) }}</div>
                    <div class="pht-pct">{{ $pct }}%</div>
                </div>
                <div class="pht-bar"><i style="width: {{ $pct }}%"></i></div>

                <div class="pht-steps">
                    @foreach ($this->steps($active) as $step)
                        <div class="pht-step pht-step-{{ $step['status'] }}">
                            <span>{!! $icon($step['status'] === 'done' ? 'tabler-check' : $step['icon']) !!}</span>
                            <small>{{ $t('steps.' . $step['key']) }}</small>
                        </div>
                    @endforeach
                </div>

                <div class="pht-tiles">
                    @if ($active->bytes_total > 0)
                        <div class="pht-tile">{!! $icon('tabler-database') !!}<div><small>{{ $t('data') }}</small><strong class="pht-mono">{{ $this->bytes(min($active->bytes_done, $active->bytes_total)) }} / {{ $this->bytes($active->bytes_total) }}</strong></div></div>
                    @endif
                    @if ($active->rate > 0 && $active->state === \PluggedHost\Servers\Models\ServerRelocation::COPYING)
                        <div class="pht-tile">{!! $icon('tabler-gauge') !!}<div><small>{{ $t('speed') }}</small><strong class="pht-mono">{{ $this->bytes($active->rate) }}/s</strong></div></div>
                    @endif
                    @if ($eta !== null)
                        <div class="pht-tile">{!! $icon('tabler-clock') !!}<div><small>{{ $t('eta') }}</small><strong>~{{ $this->duration($eta) }}</strong></div></div>
                    @endif
                    @if ($active->backups_total > 0)
                        <div class="pht-tile">{!! $icon('tabler-archive') !!}<div><small>{{ $t('backups') }}</small><strong class="pht-mono">{{ $active->backups_done }} / {{ $active->backups_total }}</strong></div></div>
                    @endif
                    @if ($active->attempt > 1)
                        <div class="pht-tile">{!! $icon('tabler-refresh') !!}<div><small>{{ $t('attempt') }}</small><strong>{{ $t('attempt_value', ['attempt' => $active->attempt, 'max' => $active->max_attempts]) }}</strong></div></div>
                    @endif
                </div>

                <div class="pht-foot">
                    <p class="pht-safe">{!! $icon('tabler-shield-check') !!}<span>{{ $t('safe') }}</span></p>
                    {{ $this->cancelAction }}
                </div>
            </section>

            <section>
                <h3 class="pht-h">{!! $icon('tabler-list-details') !!} {{ $t('timeline') }}</h3>
                <div class="pht-log">
                    @foreach ($this->events($active) as $event)
                        <div class="pht-log-row pht-lv-{{ $event['level'] }}">{!! $icon($levelIcon($event['level'])) !!}<span>{{ $event['text'] }}</span><time>{{ $event['at'] }}</time></div>
                    @endforeach
                </div>
            </section>
        @else
            @if ($recent)
                @php $ok = $recent->state === \PluggedHost\Servers\Models\ServerRelocation::COMPLETED; @endphp
                <section class="pht-card pht-result {{ $ok ? 'pht-ok' : 'pht-bad' }}">
                    <span class="pht-result-icon">{!! $icon($ok ? 'tabler-circle-check' : ($recent->state === 'cancelled' ? 'tabler-circle-x' : 'tabler-alert-triangle')) !!}</span>
                    <div>
                        <strong>{{ $this->headline($recent) }}</strong>
                        <p>{{ $t('state_hint.' . $recent->state) }}@if (!$ok && $recent->error && $recent->state !== 'cancelled') ({{ $t('reasons.' . $recent->error) }})@endif</p>
                        @if ($ok && $recent->info('address'))
                            <span class="pht-addr">{!! $icon('tabler-plug-connected') !!} {{ $recent->info('address') }}</span>
                        @endif
                    </div>
                </section>
            @endif

            <section class="pht-card pht-now">
                <span class="pht-flag">{!! $this->flag($currentCode) !!}</span>
                <div class="pht-now-name">
                    <small>{{ $t('current_location') }}</small>
                    <strong>{{ $svc->locationName($currentCode) }}</strong>
                </div>
                <div class="pht-tiles">
                    @if ($address)
                        <div class="pht-tile">{!! $icon('tabler-plug-connected') !!}<div><small>{{ $t('address') }}</small><strong class="pht-mono">{{ $address }}</strong></div></div>
                    @endif
                    <div class="pht-tile">{!! $icon('tabler-database') !!}<div><small>{{ $t('data') }}</small><strong class="pht-mono">{{ $dataBytes > 0 ? $this->bytes($dataBytes) : '—' }}</strong></div></div>
                    <div class="pht-tile">{!! $icon('tabler-archive') !!}<div><small>{{ $t('backups') }}</small><strong>{{ $backups['count'] > 0 ? $t('backups_value', ['count' => $backups['count'], 'size' => $this->bytes($backups['bytes'])]) : $t('none') }}</strong></div></div>
                    <div class="pht-tile">{!! $icon('tabler-clock') !!}<div><small>{{ $t('downtime') }}</small><strong>~{{ $this->duration($estimate) }}</strong></div></div>
                </div>
            </section>

            @if ($blocked)
                <div class="pht-note">{!! $icon('tabler-info-circle') !!}<span>{{ $t('blocked.' . $blocked[0], $blocked[1]) }}</span></div>
            @endif

            <section>
                <h3 class="pht-h">{!! $icon('tabler-map-pin') !!} {{ $t('choose') }}</h3>
                <div class="pht-list">
                    @foreach ($locations as $loc)
                        @php
                            $can = $this->pickable($loc) && !$blocked;
                            $selected = $location === $loc['code'];
                        @endphp
                        <button type="button"
                                class="pht-row {{ $selected ? 'is-selected' : '' }}"
                                wire:key="pht-loc-{{ $loc['code'] }}"
                                @if ($can) wire:click="pick(@js($loc['code']))" @else disabled @endif>
                            <span class="pht-flag">{!! $loc['svg'] !!}</span>
                            <span class="pht-row-main">
                                <strong>{{ $loc['name'] }}</strong>
                                @if ($loc['tier'] !== 'free')
                                    <span class="pht-chip pht-chip-{{ $loc['tier'] }}">{!! $icon('tabler-star') !!} {{ ucfirst($loc['tier']) }}</span>
                                @endif
                                @if ($loc['current'])
                                    <span class="pht-chip pht-chip-current">{!! $icon('tabler-map-pin') !!} {{ $loc['available'] ? $t('another_node') : $t('current') }}</span>
                                @endif
                                @if (!($loc['unlocked'] ?? true))
                                    <span class="pht-chip pht-chip-off">{!! $icon('tabler-lock') !!} {{ $t('locked') }}</span>
                                @elseif (!$loc['available'] && !$loc['current'])
                                    <span class="pht-chip pht-chip-off">{!! $icon('tabler-server-off') !!} {{ $t('full') }}</span>
                                @endif
                                @if ($loc['latency'] !== null)
                                    <span class="pht-chip">{!! $icon('tabler-activity') !!} {{ $t('ms', ['ms' => $loc['latency']]) }}</span>
                                @endif
                            </span>
                            <span class="pht-radio">@if ($selected){!! $icon('tabler-check') !!}@endif</span>
                        </button>
                    @endforeach
                </div>
            </section>

            <div class="pht-cta">{{ $this->moveAction }}</div>

            <div class="pht-help">
                <div class="pht-help-card"><span class="pht-help-icon">{!! $icon('tabler-shield-check') !!}</span><div><strong>{{ $t('help.safe_title') }}</strong><p>{{ $t('help.safe_body') }}</p></div></div>
                <div class="pht-help-card"><span class="pht-help-icon">{!! $icon('tabler-refresh') !!}</span><div><strong>{{ $t('help.retry_title') }}</strong><p>{{ $t('help.retry_body') }}</p></div></div>
                <div class="pht-help-card"><span class="pht-help-icon">{!! $icon('tabler-plug-connected') !!}</span><div><strong>{{ $t('help.address_title') }}</strong><p>{{ $t('help.address_body') }}</p></div></div>
            </div>

            @if ($recent)
                <section>
                    <h3 class="pht-h">{!! $icon('tabler-list-details') !!} {{ $t('timeline') }}</h3>
                    <div class="pht-log">
                        @foreach ($this->events($recent) as $event)
                            <div class="pht-log-row pht-lv-{{ $event['level'] }}">{!! $icon($levelIcon($event['level'])) !!}<span>{{ $event['text'] }}</span><time>{{ $event['at'] }}</time></div>
                        @endforeach
                    </div>
                </section>
            @endif
        @endif

        @if ($history->isNotEmpty())
            <section>
                <h3 class="pht-h">{!! $icon('tabler-history') !!} {{ $t('history') }}</h3>
                <div class="pht-list">
                    @foreach ($history as $past)
                        @php $pastTo = $svc->targetLocation($past); @endphp
                        <div class="pht-hist">
                            <span class="pht-flag">{!! $this->flag($past->info('from_location')) !!}</span>
                            {!! $icon('tabler-arrow-right') !!}
                            <span class="pht-flag">{!! $this->flag($pastTo) !!}</span>
                            <strong>{{ $svc->locationName($pastTo) }}</strong>
                            <span class="pht-chip {{ $past->state === 'completed' ? 'pht-chip-current' : 'pht-chip-off' }}">{{ $t('history_state.' . $past->state) }}</span>
                            <time>{{ ($past->finished_at ?? $past->updated_at)?->setTimezone(user()?->timezone ?: config('app.timezone'))->isoFormat('lll') }}</time>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-filament-panels::page>
