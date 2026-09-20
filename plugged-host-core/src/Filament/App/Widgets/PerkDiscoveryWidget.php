<?php

namespace PluggedHost\Core\Filament\App\Widgets;

use Filament\Widgets\Widget;
use PluggedHost\Core\Services\PerkDiscovery;

/**
 * The perk-discovery widget.
 *
 * This is the replacement for an announcement banner that was pinned to every page: the
 * same "you can get more RAM by linking Discord" message, but only where the reader is
 * already thinking about resources, and gone for good the moment there is nothing left to
 * claim. canView() returning false when PerkDiscovery is empty is what makes that
 * automatic — a user who has linked Discord, joined the server and has no credits offer
 * left never sees this widget again.
 *
 * It used to be a header widget on the server list too, and that was the same mistake as the
 * banner one step smaller: the app panel's landing page opened with an "unlock more
 * resources" bar above the servers the reader came for. That registration is gone; the
 * widget is now rendered by the resource store (see plugged-host-economy), which is a page
 * you only reach by choosing to. $sort therefore decides nothing.
 *
 * $columnSpan stays 'full' on purpose: the widget is a single collapsed bar (see the view),
 * and a half-width bar with an empty column beside it would read as broken rather than quiet.
 */
class PerkDiscoveryWidget extends Widget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'plugged-host-core::filament.widgets.perk-discovery';

    public static function canView(): bool
    {
        return app(PerkDiscovery::class)->forUser(auth()->user()) !== [];
    }

    protected function getViewData(): array
    {
        return [
            'perks' => app(PerkDiscovery::class)->forUser(auth()->user()),
        ];
    }
}
