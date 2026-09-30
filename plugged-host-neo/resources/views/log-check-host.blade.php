{{--
    Host for the automatic log check above the console, rendered from NeoPluginProvider's
    PAGE_START hook inside a try/catch. Explicit key prevents child key shifting on Livewire updates.
--}}
@php
    $serverId = (int) ($serverId ?? \Filament\Facades\Filament::getTenant()?->getKey() ?? 0);
@endphp

@livewire('plugged-host-neo.log-check', ['serverId' => $serverId], key('ph-neo-log-check-' . $serverId))