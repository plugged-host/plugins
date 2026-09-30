{{--
    One resource's usage: label and percentage, a flat bar, then used / available / total.

    The bar is the chart. It used to sit beside a conic-gradient pie showing the same number,
    which said everything twice; the bar is the theme's own progress shape (raise track, flat
    accent fill, warning/danger fills from the status colours) so it reads like the sidebar's
    resource rows. Styles: `.ph-card`, `.ph-meter-*` in plugged-host.css.
--}}
@php
    $fillColor = $metric['percentage'] >= 90
        ? 'var(--danger-500, #ef4444)'
        : ($metric['percentage'] >= 75 ? 'var(--warning-500, #f59e0b)' : null);
@endphp

<div class="ph-card ph-card-compact flex flex-col gap-3">
    <div class="flex items-center justify-between gap-2">
        <span class="ph-row-title">{{ $definition['label'] }}</span>

        @if ($metric['total'] > 0)
            <span class="ph-row-title font-mono tabular-nums">{{ $metric['percentage'] }}%</span>
        @endif
    </div>

    @if ($metric['total'] > 0)
        <div
            class="ph-meter-track"
            role="progressbar"
            aria-label="{{ $definition['label'] }}"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-valuenow="{{ $metric['percentage'] }}"
        >
            <div
                class="ph-meter-fill"
                style="width: {{ $metric['percentage'] }}%;{{ $fillColor ? ' background-color: ' . $fillColor . ';' : '' }}"
            ></div>
        </div>

        <dl class="ph-row-body flex flex-col gap-1">
            <div class="flex justify-between gap-2">
                <dt>Used</dt>
                <dd><strong class="font-mono tabular-nums">{{ $metric['usedLabel'] }}</strong></dd>
            </div>
            <div class="flex justify-between gap-2">
                <dt>Available</dt>
                <dd><strong class="font-mono tabular-nums">{{ $metric['availableLabel'] }}</strong></dd>
            </div>
            <div class="flex justify-between gap-2">
                <dt>Total</dt>
                <dd><strong class="font-mono tabular-nums">{{ $metric['totalLabel'] }}</strong></dd>
            </div>
        </dl>
    @else
        <p class="ph-row-body">Unavailable</p>
    @endif
</div>
