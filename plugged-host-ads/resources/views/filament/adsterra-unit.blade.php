@php
    $isolated = (bool) ($unit['isolated'] ?? true);
    $width = ($unit['width'] ?? null) !== null ? $unit['width'] . 'px' : '100%';
@endphp
{{-- An isolated unit is a whole document inside a sandboxed frame: the ad script
     gets a fresh, opaque origin, so document.write works for it and the panel's
     DOM, cookies and Livewire state are out of its reach. See
     PluggedHost\Ads\Services\AdsterraService::document(). --}}
{{-- A slot over several sizes (AdsterraService::pick()): one inert <template> per size,
     widest first. The unit script inserts the widest that fits the slot, or none, so no
     frame loads that nobody sees. --}}
@if (($unit['format'] ?? '') === 'pick')
    <div data-ph-pick style="width:100%;">
        @foreach ($unit['units'] ?? [] as $candidate)
            <template data-ph-width="{{ (int) ($candidate['width'] ?? 0) }}">@include('plugged-host-ads::filament.adsterra-unit', ['unit' => $candidate, 'sandbox' => $sandbox])</template>
        @endforeach
    </div>
@elseif (($unit['format'] ?? '') === 'direct')
    {{-- A sponsored banner in this unit's place (AdBannerService::take()). --}}
    @include('plugged-host-ads::filament.direct-banner', ['unit' => $unit])
@elseif ($isolated)
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
