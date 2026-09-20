@php
    $isolated = (bool) ($unit['isolated'] ?? true);
    $width = ($unit['width'] ?? null) !== null ? $unit['width'] . 'px' : '100%';
@endphp
{{-- An isolated unit is a whole document inside a sandboxed frame: the ad script
     gets a fresh, opaque origin, so document.write works for it and the panel's
     DOM, cookies and Livewire state are out of its reach. See
     PluggedHost\Ads\Services\AdsterraService::document(). --}}
@if ($isolated)
    <iframe data-ph-unit="adsterra-{{ $unit['format'] }}"
            sandbox="{{ $sandbox }}"
            srcdoc="{{ $unit['document'] }}"
            title="{{ trans('plugged-host-ads::strings.unit_frame_title') }}"
            scrolling="no"
            loading="lazy"
            allowtransparency="true"
            referrerpolicy="no-referrer-when-downgrade"
            style="border:0; display:block; margin:0.75rem auto 0 auto; width:{{ $width }}; max-width:100%; height:{{ $unit['height'] }}px; background-color:transparent; color-scheme:normal;"></iframe>
@elseif (($unit['format'] ?? '') === 'banner')
    @include('plugged-host-ads::filament.adsterra-banner-inline', ['unit' => $unit])
@else
    <div data-ph-unit="adsterra-{{ $unit['format'] }}" style="margin:0.75rem auto 0 auto; max-width:100%;">{!! $unit['markup'] !!}</div>
@endif
