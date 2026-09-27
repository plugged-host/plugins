{{--
    The progress strip on every server page (TransferStrip). Polls while the move runs, then
    shows the outcome for ten minutes. Scoped plain CSS, no asset build.
--}}
<div @if ($relocation?->isActive()) wire:poll.3s @endif>
    @if ($relocation)
        @php
            $active = $relocation->isActive();
            $ok = $relocation->state === \PluggedHost\Servers\Models\ServerRelocation::COMPLETED;
            $pct = $active || $ok ? $relocation->percent() : 100;
            $tone = $active ? 'run' : ($ok ? 'ok' : 'bad');
        @endphp
        <style>
            .phts { display: flex; align-items: center; gap: .75rem; padding: .6rem .85rem; margin-bottom: 1rem; border-radius: .85rem; border: 1px solid rgba(59,130,246,.35); background: rgba(59,130,246,.07); font-size: .82rem; }
            .phts:hover { background: rgba(59,130,246,.12); }
            .phts-ok { border-color: rgba(34,197,94,.35); background: rgba(34,197,94,.07); }
            .phts-bad { border-color: rgba(234,179,8,.35); background: rgba(234,179,8,.07); }
            .phts-icon { width: 1.9rem; height: 1.9rem; flex: none; border-radius: 9999px; display: grid; place-items: center; background: rgba(59,130,246,.16); color: #60a5fa; }
            .phts-ok .phts-icon { background: rgba(34,197,94,.16); color: #22c55e; }
            .phts-bad .phts-icon { background: rgba(234,179,8,.16); color: #eab308; }
            .phts-icon svg { width: 1.1rem; height: 1.1rem; }
            .phts-text { min-width: 0; display: flex; flex-direction: column; }
            .phts-text strong { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .phts-text small { opacity: .65; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .phts-bar { flex: 1; min-width: 3rem; height: .4rem; border-radius: 9999px; background: rgba(127,127,127,.2); overflow: hidden; }
            .phts-bar i { display: block; height: 100%; background: linear-gradient(90deg, #3b82f6, #0ea5e9); transition: width .6s ease; }
            .phts-ok .phts-bar i { background: #22c55e; }
            .phts-bad .phts-bar { display: none; }
            .phts-pct { font-family: 'JetBrains Mono', ui-monospace, monospace; font-weight: 700; }
            .phts-open { display: inline-flex; align-items: center; gap: .2rem; opacity: .75; white-space: nowrap; }
            .phts-open svg { width: .9rem; height: .9rem; }
            @media (max-width: 640px) { .phts-open span, .phts-text small { display: none; } }
        </style>
        <a href="{{ $url }}" class="phts phts-{{ $tone }}">
            <span class="phts-icon">{!! svg($active ? 'tabler-truck-delivery' : ($ok ? 'tabler-circle-check' : 'tabler-alert-triangle'))->toHtml() !!}</span>
            <span class="phts-text">
                <strong>{{ trans('plugged-host-servers::strings.transfers.state.' . $relocation->headlineKey()) }}</strong>
                <small>{{ $service->locationName($service->targetLocation($relocation)) }}</small>
            </span>
            <span class="phts-bar"><i style="width: {{ $pct }}%"></i></span>
            @if ($active)
                <span class="phts-pct">{{ $pct }}%</span>
            @endif
            <span class="phts-open"><span>{{ trans('plugged-host-servers::strings.transfers.strip.open') }}</span>{!! svg('tabler-chevron-right')->toHtml() !!}</span>
        </a>
    @endif
</div>
