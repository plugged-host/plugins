<?php

namespace PluggedHost\Core\Filament\App\Pages;

use App\Filament\App\Resources\Servers\ServerResource;
use App\Models\User;
use Filament\Pages\Page;
use PluggedHost\Core\Support\PanelTour;
use Throwable;

/**
 * /welcome: the way back into the welcome tour, from the user menu.
 *
 * The tour used to *be* this page -- a text card in the simple layout, describing where
 * things are. It is now PanelTourOverlay, drawn over the server list and a server's console
 * so it can point at the real thing, and this page is only the entry the "Replay the welcome
 * tour" menu item links to: ?restart=1 resets both parts, and every visit lands on the
 * server list, where the overlay opens if a part is due.
 *
 * Kept as a Filament page rather than a bare route so it sits behind the app panel's own
 * auth middleware, and so the URL people may have bookmarked keeps working.
 */
class WelcomeTourPage extends Page
{
    protected string $view = 'plugged-host-core::filament.pages.welcome-tour';

    protected static ?string $slug = 'welcome';

    protected static bool $shouldRegisterNavigation = false;

    /**
     * Where the user-menu entry points: the tour, from the top, without re-arming the gate.
     *
     * Null when there is nothing to link to -- the tour is switched off, or the page is not
     * discovered on the app panel -- and PluggedHostCorePlugin hides the menu entry on null.
     * A user menu that throws takes the whole topbar with it, on every page, for everyone.
     */
    public static function restartUrl(): ?string
    {
        if (!PanelTour::enabled()) {
            return null;
        }

        try {
            return static::getUrl(panel: 'app') . '?restart=1';
        } catch (Throwable) {
            return null;
        }
    }

    public function getTitle(): string
    {
        return trans('plugged-host-core::strings.tour.title');
    }

    public function mount(): void
    {
        $user = auth()->user();

        // ?restart=1 is the user-menu entry. restart() clears the finished markers but leaves
        // acknowledged_at alone, so abandoning a replay halfway cannot re-arm the gate.
        if ($user instanceof User && request()->boolean('restart')) {
            PanelTour::restart($user);
        }

        // navigate: false -- this is reached by a plain link from the user menu, and the server
        // list has to arrive with its BODY_END hooks rendered, which is where the tour lives.
        $this->redirect(ServerResource::getUrl('index', panel: 'app'), navigate: false);
    }
}
