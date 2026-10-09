{{-- The in-content ad slot. Rendered by PluggedHost\Ads\Providers\AdsPluginServiceProvider. --}}
<div class="ph-support-slot" data-ph-slot style="width: 100%; margin: 2rem auto 1rem auto; clear: both; position: relative; z-index: {{ $zIndex }}; text-align: center; overflow: visible;">
    @if ($notice)
        {{-- Deliberately empty, and outside every ad-shaped wrapper: a cosmetic
             filter that collapses the units below must not also take away the
             message that explains why they are empty. The class and attribute
             names avoid anything a filter list matches on for the same reason.

             It is a real Filament section, so once the detector fills it in it is
             the same card the rest of the panel is built out of. Empty and hidden
             it renders nothing. --}}
        <div class="ph-support-notice fi-section fi-section-has-header" data-ph-notice hidden></div>
    @endif

    @foreach ($adsterraUnits as $unit)
        @include('plugged-host-ads::filament.adsterra-unit', ['unit' => $unit, 'sandbox' => $sandbox])
    @endforeach
</div>
