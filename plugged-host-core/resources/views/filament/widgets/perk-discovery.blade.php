{{--
    Dashboard perk card. All of the markup lives in the shared partial so this surface, the
    server-installing page and anywhere added later cannot drift apart.

    Deliberately the quiet version of that partial: collapsed to a single bar with a count in the
    heading and no description. The always-open full-width panel this replaced was the loudest
    thing on the dashboard and read as an ad. The offers themselves are unchanged and one click
    away, and persist-collapsed means a user who opens it keeps it open.

    The other surfaces still include the partial in its original card form.
--}}
<x-filament-widgets::widget>
    @include('plugged-host-core::filament.components.perk-list', [
        'perks' => $perks,
        'heading' => trans('plugged-host-core::strings.perks.heading_compact', ['count' => count($perks)]),
        'collapsible' => true,
        'collapsed' => true,
        'collapseId' => 'plugged-host-perks-dashboard',
        'compact' => true,
    ])
</x-filament-widgets::widget>
