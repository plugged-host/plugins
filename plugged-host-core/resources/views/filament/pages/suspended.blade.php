{{--
    A suspended server, for its owner and subusers (SuspendedServerPage): that it is suspended,
    by what and when, and why (the admin's note, or what the abuse scan found), and when it will be
    deleted if the abuse checks suspended it (AbusePurge). The one action,
    help on Discord, is the page's header action (none without a support link, see
    supportUrl()). Same card as limbo's restore page.
--}}
<x-filament-panels::page>
    @php
        $reason = $this->reason();
        $findings = $reason['reasons'] ?? [];
        $shown = array_slice($findings, 0, 20);
    @endphp

    <div class="ph-card flex flex-col gap-6">
        <div class="flex flex-col gap-1">
            <p class="ph-row-body">{{ trans('plugged-host-core::strings.suspended.body') }}</p>
            @if ($reason)
                <p class="ph-row-body">
                    {{ trans('plugged-host-core::strings.suspended.by_' . (in_array($reason['source'], ['abuse_scan', 'admin'], true) ? $reason['source'] : 'system'), ['time' => $reason['at']->diffForHumans()]) }}
                </p>
            @endif
            @if ($deletesAt = $this->deletesAt())
                <p class="ph-row-body">{{ trans('plugged-host-core::strings.suspended.deletes_at', ['time' => $deletesAt->diffForHumans()]) }}</p>
            @endif
        </div>

        @if (!empty($reason['note']))
            <div class="flex flex-col gap-1">
                <p class="ph-caption">{{ trans('plugged-host-core::strings.suspended.note_label') }}</p>
                <p class="ph-row-body">{{ $reason['note'] }}</p>
            </div>
        @endif

        @if ($shown !== [])
            <div class="flex flex-col gap-1">
                <p class="ph-caption">{{ trans('plugged-host-core::strings.suspended.findings_label') }}</p>
                <ul class="flex flex-col gap-1">
                    @foreach ($shown as $finding)
                        <li class="ph-row-body font-mono break-all">{{ $finding }}</li>
                    @endforeach
                </ul>
                @if (count($findings) > count($shown))
                    <p class="ph-row-body">{{ trans('plugged-host-core::strings.suspended.more', ['count' => count($findings) - count($shown)]) }}</p>
                @endif
            </div>
        @endif

        <p class="ph-row-body">{{ trans('plugged-host-core::strings.suspended.' . (filled($this->supportUrl()) ? 'help' : 'help_admin')) }}</p>
    </div>
</x-filament-panels::page>
