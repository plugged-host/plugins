{{-- WP4 named ad slot: <x-plugged-host-ads::slot name="sidebar-footer" />

     Renders the slot's `units` specs (AdsterraService::unitsFor()). Asks
     AdDisplayService::slot($name), which applies (in order) the master switch, the
     per-panel switch, the exempt-user check, the interstitial-route check, the slot's own
     toggle, no-zone-twice, and the hard per-page cap — and reserves a unit when it says yes.
     A new placement is this one tag plus a config line under plugged-host-ads.placements.

     Renders nothing (no reserved space, no shift) when the slot is not granted. --}}
@props(['name'])
@php
    $adDisplay = app(\PluggedHost\Ads\Services\AdDisplayService::class);
    $adsterra = app(\PluggedHost\Ads\Services\AdsterraService::class);
    $panelId = \Filament\Facades\Filament::getCurrentPanel()?->getId();

    $granted = false;
    try {
        $granted = $adDisplay->slot($name, user(), $panelId, false);
    } catch (\Throwable) {
        $granted = false;
    }

    $slotCfg = $adDisplay->slotConfig($name) ?? [];
    $units = [];
    if ($granted) {
        try {
            $units = $adsterra->unitsFor((array) ($slotCfg['units'] ?? ['native']));
        } catch (\Throwable) {
            $units = [];
        }
    }
    $maxHeight = (int) ($slotCfg['max_height'] ?? 0);
@endphp

{{-- wire:ignore: inside a Livewire page the next morph would put the <template>s of a
     multi-size unit back over the frame the unit script inserted, and every morph would
     then cost a fresh impression. --}}
@if ($granted && $units !== [])
    <div class="ph-ad-slot ph-ad-slot-{{ $name }}" data-ph-slot wire:ignore
         style="width:100%;margin:1.5rem auto 1rem auto;text-align:center;position:relative;z-index:{{ $adDisplay->getZIndex() }};overflow:visible;@if ($maxHeight) min-height:{{ $maxHeight }}px;@endif">
        @foreach ($units as $unit)
            @include('plugged-host-ads::filament.deferred-unit', ['unit' => $unit, 'sandbox' => $adsterra->frameSandbox()])
        @endforeach
    </div>
@endif
