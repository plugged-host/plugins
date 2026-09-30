{{--
    The progress strip on every server page (TransferStrip). Polls fast while the move runs,
    then keeps a slow poll going while it shows the outcome so the strip clears itself once the
    window passes (OUTCOME_MINUTES). One flat row on the theme tokens: a status dot, the state,
    a flat progress bar. Scoped plain CSS, no asset build.
--}}
<div @if ($relocation?->isActive()) wire:poll.3s @elseif ($relocation) wire:poll.15s @endif>
    @if ($relocation)
        @php
            $active = $relocation->isActive();
            $ok = $relocation->state === \PluggedHost\Servers\Models\ServerRelocation::COMPLETED;
            $pct = $active || $ok ? $relocation->percent() : 100;
            $tone = $active ? 'run' : ($ok ? 'ok' : 'bad');
        @endphp
        <style>
            .phts { display: flex; align-items: center; gap: .75rem; padding: .75rem 1rem; margin-bottom: 1rem; border-radius: .75rem; background: var(--phv2-raise); font-size: .8125rem; color: var(--phv2-text); transition: background-color 150ms ease; }
            .phts:hover { background: var(--phv2-raise-2); }
            .phts-dot { width: .5rem; height: .5rem; flex: none; border-radius: 9999px; background: var(--phv2-accent-bright); }
            .phts-ok .phts-dot { background: var(--success-500); }
            .phts-bad .phts-dot { background: var(--warning-500); }
            .phts-text { min-width: 0; display: flex; flex-direction: column; }
            .phts-text strong { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .phts-text small { font-size: .75rem; color: var(--phv2-text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .phts-bar { flex: 1; min-width: 3rem; height: 4px; border-radius: 9999px; background: var(--phv2-raise); overflow: hidden; }
            .phts-bar i { display: block; height: 100%; border-radius: 9999px; background: var(--phv2-accent-bright); transition: width .6s ease; }
            .phts-ok .phts-bar i { background: var(--success-500); }
            .phts-bad .phts-bar { display: none; }
            .phts-bad .phts-text { flex: 1; }
            .phts-pct { font-weight: 600; font-variant-numeric: tabular-nums; }
            .phts-open { flex: none; font-weight: 500; color: var(--phv2-accent-bright); white-space: nowrap; }
            .phts:hover .phts-open { color: var(--phv2-accent-glow); }
            @media (max-width: 640px) { .phts-text small, .phts-bar { display: none; } .phts-text { flex: 1; } }
        </style>
        <a href="{{ $url }}" class="phts phts-{{ $tone }}">
            <span class="phts-dot" aria-hidden="true"></span>
            <span class="phts-text">
                <strong>{{ trans('plugged-host-servers::strings.transfers.state.' . $relocation->headlineKey()) }}</strong>
                <small>{{ $service->locationName($service->targetLocation($relocation)) }}</small>
            </span>
            <span class="phts-bar"><i style="width: {{ $pct }}%"></i></span>
            @if ($active)
                <span class="phts-pct font-mono">{{ $pct }}%</span>
            @endif
            <span class="phts-open">{{ trans('plugged-host-servers::strings.transfers.strip.open') }}</span>
        </a>
    @endif
</div>
