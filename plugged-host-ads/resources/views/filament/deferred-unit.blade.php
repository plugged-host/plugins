{{-- A unit that loads only once it is on screen: wrapped in a group the unit script inserts
     when the spot it holds is in the viewport, in a visible tab (scripts/units.blade.php). A
     frame that loads below the fold counts as an impression nobody saw, which is what the foot
     of a long page did. Inline units write into the document as they load and cannot wait in a
     template, so they render as before. --}}
@if (($unit['isolated'] ?? true) || ($unit['format'] ?? '') === 'direct')
    <div data-ph-pick data-ph-defer style="width:100%;"><template data-ph-width="0">@include('plugged-host-ads::filament.adsterra-unit', ['unit' => $unit, 'sandbox' => $sandbox])</template></div>
@else
    @include('plugged-host-ads::filament.adsterra-unit', ['unit' => $unit, 'sandbox' => $sandbox])
@endif
