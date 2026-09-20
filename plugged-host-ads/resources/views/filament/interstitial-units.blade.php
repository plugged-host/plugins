{{--
    The unit stack for the waiting room.

    Every unit is rendered inside its own try/catch, which matters more here than in
    the in-content slot: that one lives in a render hook the provider already wraps,
    while this one is part of a page whose entire job is to let a customer reach their
    server. An ad unit that throws has to render nothing, not a 500.

    The generic in-content slot is suppressed on this route (see
    AdsPluginServiceProvider::registerSlot), so these are the only copies of these
    zones in the document.

    wire:ignore is load-bearing, not defensive. The gate page polls every couple of
    seconds, and a Livewire morph reconciles the DOM against freshly rendered markup —
    which does not contain the iframes and divs the ad scripts appended after the last
    render. Without it, every tick would wipe the units and the non-isolated banner
    would refuse to refill (its container is already flagged as filled), leaving an
    empty page for the rest of the wait.

    Heights are reserved per unit, so the layout does not shift as scripts fill and a
    blocked unit leaves a quiet gap instead of collapsing the page around the countdown.

    Two columns from `lg` up. A waiting room with several banner zones configured is a
    very long single column otherwise, and a 300x250 beside a 300x250 is two units on
    one screen instead of one unit and a scroll. The reserved min-height stays per unit
    rather than per row, so a short unit next to a tall one does not inflate both.

    Rendered twice per room, over disjoint slices of the same list (see the gate page),
    so nothing here may assume it is the only copy on the page — hence the min-height
    living on the unit and not on the container.
--}}
@if ($units !== [])
    <div class="ph-gate-stack flex flex-col gap-2" wire:ignore>
        <span class="ph-gate-sponsored">{{ trans('plugged-host-ads::strings.start_gate_sponsored') }}</span>

        <div class="grid gap-4 lg:grid-cols-2">
            @foreach ($units as $unit)
                <div class="ph-gate-unit"
                     data-ph-gate-unit="{{ $unit['id'] ?? $unit['format'] }}"
                     style="min-height: {{ max(80, (int) ($unit['height'] ?? 90)) }}px;">
                    @php
                        try {
                            echo view('plugged-host-ads::filament.adsterra-unit', [
                                'unit' => $unit,
                                'sandbox' => $sandbox,
                            ])->render();
                        } catch (\Throwable) {
                            // Intentionally silent: no ad is a better outcome than no page.
                        }
                    @endphp
                </div>
            @endforeach
        </div>
    </div>
@endif
