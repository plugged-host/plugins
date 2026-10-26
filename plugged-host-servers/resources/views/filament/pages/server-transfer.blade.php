{{--
    Transfer page (ServerTransferPage). Two faces: while a move runs, a progress card (route,
    bar, steps, facts) and the activity list, polling every two seconds; otherwise the current
    location, one full-width row per location to pick from, and the Move button. Flat cards on
    the theme tokens in scoped plain CSS, so it needs no asset build.
--}}
@php
    $t = fn (string $key, array $replace = []) => trans('plugged-host-servers::strings.transfers.' . $key, $replace);
    $icon = fn (string $name, string $class = 'pht-i') => svg($name, $class)->toHtml();
    $svc = $this->service();
    $historyColor = fn (string $state) => match ($state) {
        'completed' => 'success',
        'failed' => 'danger',
        'stuck' => 'warning',
        default => 'gray',
    };
@endphp
<x-filament-panels::page>
    <style>
        .pht { display: flex; flex-direction: column; gap: 1.5rem; }
        .pht-i { width: 1rem; height: 1rem; flex: none; }
        .pht-card { display: flex; flex-direction: column; gap: 1.25rem; padding: 1.5rem; border-radius: 1rem; background: var(--phv2-raise); color: var(--phv2-text); }
        .pht-h { font-size: 1rem; font-weight: 600; line-height: 1.5rem; color: var(--phv2-text); }
        .pht-caption { display: block; font-size: .75rem; color: var(--phv2-text-muted); }
        .pht-flag { width: 1.75rem; height: 1.3125rem; flex: none; border-radius: .25rem; overflow: hidden; display: grid; place-items: center; background: var(--phv2-raise-2); outline: 1px solid var(--phv2-hairline); outline-offset: -1px; }
        .pht-flag svg { width: 100%; height: 100%; display: block; }
        .pht-route { display: grid; grid-template-columns: minmax(0,1fr) auto minmax(0,1fr); align-items: center; gap: .75rem; }
        .pht-place { display: flex; align-items: center; gap: .625rem; min-width: 0; }
        .pht-place-to { justify-content: flex-end; text-align: right; }
        .pht-place strong { display: block; font-size: 1rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .pht-arrow { font-size: 1.125rem; color: var(--phv2-text-faint); }
        .pht-status { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: .625rem; }
        .pht-headline { display: flex; align-items: center; gap: .5rem; min-width: 0; font-size: 1rem; font-weight: 600; }
        .pht-spinner { width: 1rem; height: 1rem; flex: none; color: var(--phv2-accent-bright); }
        .pht-pct { font-size: 1.25rem; font-weight: 600; line-height: 1; font-variant-numeric: tabular-nums; }
        .pht-bar { height: 6px; border-radius: 9999px; background: var(--phv2-raise); overflow: hidden; }
        .pht-bar > i { display: block; height: 100%; border-radius: 9999px; background: var(--phv2-accent-bright); transition: width .6s ease; }
        .pht-steps { display: grid; grid-auto-flow: column; grid-auto-columns: minmax(0,1fr); gap: .5rem; }
        .pht-step { display: flex; flex-direction: column; align-items: center; gap: .375rem; font-size: .75rem; text-align: center; color: var(--phv2-text-faint); }
        .pht-step span { width: 1.75rem; height: 1.75rem; border-radius: 9999px; display: grid; place-items: center; background: var(--phv2-raise-2); font-size: .75rem; font-weight: 600; color: var(--phv2-text-muted); font-variant-numeric: tabular-nums; }
        .pht-step-done { color: var(--phv2-text-muted); }
        .pht-step-done span { background: color-mix(in oklab, var(--success-500) 15%, transparent); color: var(--success-400); }
        .pht-step-current { color: var(--phv2-text); font-weight: 600; }
        .pht-step-current span { background: var(--phv2-accent); color: #fff; }
        .pht-facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(8rem, 1fr)); gap: 1rem; }
        .pht-fact { min-width: 0; }
        .pht-fact strong { display: block; margin-top: .125rem; font-size: .875rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-variant-numeric: tabular-nums; }
        .pht-foot { display: flex; align-items: center; justify-content: space-between; gap: .75rem 1.5rem; flex-wrap: wrap; }
        .pht-safe { flex: 1 1 18rem; font-size: .8125rem; line-height: 1.25rem; color: var(--phv2-text-muted); }
        .pht-log { display: flex; flex-direction: column; max-height: 18rem; overflow-y: auto; margin-top: -.5rem; }
        .pht-log-row { display: flex; align-items: center; gap: .75rem; padding: .5rem 0; font-size: .8125rem; color: var(--phv2-text-nav); }
        .pht-log-row + .pht-log-row { border-top: 1px solid var(--phv2-hairline); }
        .pht-log-row span { flex: 1; min-width: 0; }
        .pht-log-row time { flex: none; font-size: .75rem; color: var(--phv2-text-faint); }
        .pht-dot { width: .5rem; height: .5rem; flex: none; border-radius: 9999px; background: var(--phv2-text-faint); }
        .pht-lv-success .pht-dot { background: var(--success-500); }
        .pht-lv-warning .pht-dot { background: var(--warning-500); }
        .pht-lv-danger .pht-dot { background: var(--danger-500); }
        .pht-now { display: flex; align-items: center; gap: .875rem; }
        .pht-now .pht-flag { width: 2.5rem; height: 1.875rem; border-radius: .375rem; }
        .pht-now strong { display: block; font-size: 1.125rem; font-weight: 600; }
        .pht-pick { display: flex; flex-direction: column; gap: .75rem; }
        .pht-blocked { font-size: .8125rem; line-height: 1.25rem; color: var(--warning-400); }
        .pht-list { display: flex; flex-direction: column; gap: .5rem; }
        .pht-row { display: flex; align-items: center; gap: .75rem; width: 100%; padding: .75rem 1rem; border-radius: .75rem; border: 1px solid transparent; background: var(--phv2-raise); color: var(--phv2-text); text-align: left; transition: border-color 150ms ease, background-color 150ms ease; }
        .pht-row:not(:disabled):hover { background: var(--phv2-raise-2); }
        .pht-row.is-selected, .pht-row.is-selected:not(:disabled):hover { border-color: var(--phv2-accent-bright); background: var(--phv2-accent-soft); }
        .pht-row:disabled { opacity: .5; cursor: not-allowed; }
        .pht-row-main { flex: 1; min-width: 0; display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
        .pht-row-main strong { font-size: .875rem; font-weight: 600; }
        .pht-ms { flex: none; font-size: .75rem; color: var(--phv2-text-muted); font-variant-numeric: tabular-nums; }
        .pht-radio { width: 1.125rem; height: 1.125rem; flex: none; border-radius: 9999px; border: 1px solid var(--phv2-raise-3); }
        .pht-row.is-selected .pht-radio { border: .3125rem solid var(--phv2-accent-bright); }
        .pht-cta { display: flex; justify-content: flex-end; }
        .pht-help { display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 1rem 1.5rem; }
        .pht-help strong { display: block; font-size: .8125rem; font-weight: 600; color: var(--phv2-text-nav); }
        .pht-help p { margin-top: .125rem; font-size: .8125rem; line-height: 1.25rem; color: var(--phv2-text-muted); }
        .pht-addr { font-size: .875rem; font-weight: 600; color: var(--phv2-text); }
        .pht-hist { display: flex; flex-direction: column; margin-top: -.5rem; }
        .pht-hist-row { display: flex; align-items: center; gap: .625rem; padding: .625rem 0; font-size: .8125rem; }
        .pht-hist-row + .pht-hist-row { border-top: 1px solid var(--phv2-hairline); }
        .pht-hist-row .pht-flag { width: 1.5rem; height: 1.125rem; }
        .pht-hist-row .pht-arrow { font-size: .875rem; }
        .pht-hist-row strong { font-weight: 600; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pht-hist-row time { margin-left: auto; font-size: .75rem; color: var(--phv2-text-muted); white-space: nowrap; }
        @media (max-width: 640px) {
            .pht-card { padding: 1rem; }
            .pht-place small { display: none; }
            .pht-place strong { font-size: .875rem; }
            .pht-step small { display: none; }
            .pht-cta { flex-direction: column; align-items: stretch; }
        }
    </style>

    <div class="pht" @if ($active) wire:poll.2s @endif>
        @if ($active)
            @php
                $from = $active->info('from_location');
                $to = $svc->targetLocation($active);
                $pct = $active->percent();
                $eta = $active->etaSeconds();
                $fraction = $active->copyFraction();
            @endphp

            <section class="pht-card">
                <div class="pht-route">
                    <div class="pht-place">
                        <span class="pht-flag">{!! $this->flag($from) !!}</span>
                        <div><small class="pht-caption">{{ $t('from') }}</small><strong>{{ $svc->locationName($from) }}</strong></div>
                    </div>
                    <span class="pht-arrow" aria-hidden="true">→</span>
                    <div class="pht-place pht-place-to">
                        <div><small class="pht-caption">{{ $t('to') }}</small><strong>{{ $svc->locationName($to) }}</strong></div>
                        <span class="pht-flag">{!! $this->flag($to) !!}</span>
                    </div>
                </div>

                <div>
                    <div class="pht-status">
                        <div class="pht-headline"><x-filament::loading-indicator class="pht-spinner" /> <span>{{ $this->headline($active) }}</span></div>
                        <div class="pht-pct font-mono">{{ $pct }}%</div>
                    </div>
                    <div class="pht-bar"><i style="width: {{ $pct }}%"></i></div>
                </div>

                <div class="pht-steps">
                    @foreach ($this->steps($active) as $step)
                        <div class="pht-step pht-step-{{ $step['status'] }}">
                            <span>
                                @if ($step['status'] === 'done')
                                    {!! $icon('tabler-check') !!}
                                @else
                                    {{ $loop->iteration }}
                                @endif
                            </span>
                            <small>{{ $t('steps.' . $step['key']) }}</small>
                        </div>
                    @endforeach
                </div>

                <div class="pht-facts">
                    @if ($active->bytes_total > 0)
                        <div class="pht-fact"><small class="pht-caption">{{ $t('data') }}</small><strong class="font-mono">{{ $this->bytes(min($active->bytes_done, $active->bytes_total)) }} / {{ $this->bytes($active->bytes_total) }}</strong></div>
                    @endif
                    @if ($active->rate > 0 && $active->state === \PluggedHost\Servers\Models\ServerRelocation::COPYING)
                        <div class="pht-fact"><small class="pht-caption">{{ $t('speed') }}</small><strong class="font-mono">{{ $this->bytes($active->rate) }}/s</strong></div>
                    @endif
                    @if ($eta !== null)
                        <div class="pht-fact"><small class="pht-caption">{{ $t('eta') }}</small><strong>~{{ $this->duration($eta) }}</strong></div>
                    @endif
                    @if ($active->backups_total > 0)
                        <div class="pht-fact"><small class="pht-caption">{{ $t('backups') }}</small><strong class="font-mono">{{ $active->backups_done }} / {{ $active->backups_total }}</strong></div>
                    @endif
                    @if ($active->attempt > 1)
                        <div class="pht-fact"><small class="pht-caption">{{ $t('attempt') }}</small><strong>{{ $t('attempt_value', ['attempt' => $active->attempt, 'max' => $active->max_attempts]) }}</strong></div>
                    @endif
                </div>

                <div class="pht-foot">
                    <p class="pht-safe">{{ $t('safe') }}</p>
                    {{ $this->cancelAction }}
                </div>
            </section>

            <section class="pht-card">
                <h3 class="pht-h">{{ $t('timeline') }}</h3>
                <div class="pht-log">
                    @foreach ($this->events($active) as $event)
                        <div class="pht-log-row pht-lv-{{ $event['level'] }}"><i class="pht-dot" aria-hidden="true"></i><span>{{ $event['text'] }}</span><time class="font-mono">{{ $event['at'] }}</time></div>
                    @endforeach
                </div>
            </section>
        @else
            @if ($recent)
                @php
                    $ok = $recent->state === \PluggedHost\Servers\Models\ServerRelocation::COMPLETED;
                    $hint = $t('state_hint.' . $recent->state)
                        . ((!$ok && $recent->error && $recent->state !== 'cancelled') ? ' (' . $t('reasons.' . $recent->error) . ')' : '');
                @endphp
                <x-filament::callout
                    :color="$ok ? 'success' : ($recent->state === 'cancelled' ? 'gray' : 'warning')"
                    :heading="$this->headline($recent)"
                    :description="$hint"
                >
                    @if ($ok && $recent->info('address'))
                        <x-slot name="footer">
                            <div>
                                <small class="pht-caption">{{ $t('new_address') }}</small>
                                <span class="pht-addr font-mono">{{ $recent->info('address') }}</span>
                            </div>
                        </x-slot>
                    @endif
                </x-filament::callout>
            @endif

            <section class="pht-card">
                <div class="pht-now">
                    <span class="pht-flag">{!! $this->flag($currentCode) !!}</span>
                    <div>
                        <small class="pht-caption">{{ $t('current_location') }}</small>
                        <strong>{{ $svc->locationName($currentCode) }}</strong>
                    </div>
                </div>
                <div class="pht-facts">
                    @if ($address)
                        <div class="pht-fact"><small class="pht-caption">{{ $t('address') }}</small><strong class="font-mono">{{ $address }}</strong></div>
                    @endif
                    <div class="pht-fact"><small class="pht-caption">{{ $t('data') }}</small><strong class="font-mono">{{ $dataBytes > 0 ? $this->bytes($dataBytes) : '—' }}</strong></div>
                    <div class="pht-fact"><small class="pht-caption">{{ $t('backups') }}</small><strong>{{ $backups['count'] > 0 ? $t('backups_value', ['count' => $backups['count'], 'size' => $this->bytes($backups['bytes'])]) : $t('none') }}</strong></div>
                    <div class="pht-fact"><small class="pht-caption">{{ $t('downtime') }}</small><strong>~{{ $this->duration($estimate) }}</strong></div>
                </div>
            </section>

            <section class="pht-pick">
                <h3 class="pht-h">{{ $t('choose') }}</h3>

                @if ($blocked)
                    <p class="pht-blocked">{{ $t('blocked.' . $blocked[0], $blocked[1]) }}</p>
                @endif

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
                                    <x-filament::badge color="gray" size="sm">{{ ucfirst($loc['tier']) }}</x-filament::badge>
                                @endif
                                @if ($loc['current'])
                                    <x-filament::badge color="gray" size="sm">{{ $loc['available'] ? $t('another_node') : $t('current') }}</x-filament::badge>
                                @endif
                                @if (!($loc['unlocked'] ?? true))
                                    <x-filament::badge color="gray" size="sm">{{ $t('locked') }}</x-filament::badge>
                                @elseif (!$loc['available'] && !$loc['current'])
                                    <x-filament::badge color="gray" size="sm">{{ $t('full') }}</x-filament::badge>
                                @endif
                            </span>
                            @if (($loc['probes'] ?? []) !== [])
                                {{-- The visitor's own ping, timed by the browser (window.phLocationPings). --}}
                                <span
                                    wire:ignore
                                    class="pht-ms font-mono"
                                    style="display: none;"
                                    x-data="{ ms: null }"
                                    x-init="window.phLocationPings?.measure(@js($loc['probes'])).then((value) => ms = value)"
                                    x-show="ms !== null"
                                    x-text="@js($t('ms', ['ms' => ':ms'])).replace(':ms', ms)"
                                ></span>
                            @endif
                            <span class="pht-radio" aria-hidden="true"></span>
                        </button>
                    @endforeach
                </div>
            </section>

            <div class="pht-cta">{{ $this->moveAction }}</div>

            <div class="pht-help">
                <div><strong>{{ $t('help.safe_title') }}</strong><p>{{ $t('help.safe_body') }}</p></div>
                <div><strong>{{ $t('help.retry_title') }}</strong><p>{{ $t('help.retry_body') }}</p></div>
                <div><strong>{{ $t('help.address_title') }}</strong><p>{{ $t('help.address_body') }}</p></div>
            </div>

            @if ($recent)
                <section class="pht-card">
                    <h3 class="pht-h">{{ $t('timeline') }}</h3>
                    <div class="pht-log">
                        @foreach ($this->events($recent) as $event)
                            <div class="pht-log-row pht-lv-{{ $event['level'] }}"><i class="pht-dot" aria-hidden="true"></i><span>{{ $event['text'] }}</span><time class="font-mono">{{ $event['at'] }}</time></div>
                        @endforeach
                    </div>
                </section>
            @endif
        @endif

        @if ($history->isNotEmpty())
            <section class="pht-card">
                <h3 class="pht-h">{{ $t('history') }}</h3>
                <div class="pht-hist">
                    @foreach ($history as $past)
                        @php
                            $pastTo = $svc->targetLocation($past);
                        @endphp
                        <div class="pht-hist-row">
                            <span class="pht-flag">{!! $this->flag($past->info('from_location')) !!}</span>
                            <span class="pht-arrow" aria-hidden="true">→</span>
                            <span class="pht-flag">{!! $this->flag($pastTo) !!}</span>
                            <strong>{{ $svc->locationName($pastTo) }}</strong>
                            <x-filament::badge :color="$historyColor($past->state)" size="sm">{{ $t('history_state.' . $past->state) }}</x-filament::badge>
                            <time>{{ ($past->finished_at ?? $past->updated_at)?->setTimezone(user()?->timezone ?: config('app.timezone'))->isoFormat('lll') }}</time>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-filament-panels::page>
