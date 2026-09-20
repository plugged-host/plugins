<?php

namespace PluggedHost\Core\Filament\App\Pages;

use App\Models\User;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use PluggedHost\Core\Support\PanelTour;
use Throwable;

/**
 * "Here is what this panel is and where things are" -- shown once, on the first login.
 *
 * The problem it solves is support-shaped: people land on the server list, are told "go to
 * this page", and cannot tell what to click. Five steps, each naming a real thing in the
 * interface by the label it actually carries.
 *
 * It renders in the *simple* layout -- one centred card, panel logo, no sidebar -- and that
 * is the whole reason this shape was chosen over a floating overlay on the dashboard: with
 * no navigation on screen there is nothing to click past, so RequirePanelTour only has to
 * hold one page rather than argue with every link in the sidebar. Step 1 is therefore
 * unskippable by construction rather than by disabling buttons.
 *
 * $layout is set rather than extending SimplePage, for the reason SetPasswordPage documents:
 * Panel::discoverPages() only registers subclasses of Filament\Pages\Page, and SimplePage is
 * a *sibling* of Page, so a SimplePage dropped in this directory would get no route at all.
 * hasLogo() is the one member filament-panels::components.page.simple needs that Page, unlike
 * SimplePage, does not define.
 *
 * getHeading() is deliberately empty: header.simple renders the logo and nothing else, and
 * each step's own heading lives inside the card so it animates with the rest of the step
 * instead of jumping while the body slides.
 *
 * A Filament page *is* a Livewire component, so the buttons are plain wire:click and $step is
 * a public property -- no Alpine state to keep in sync with the server, and closing the tab
 * mid-tour resumes where it left off because every transition records the step.
 */
class WelcomeTourPage extends Page
{
    protected static string $layout = 'filament-panels::components.layout.simple';

    protected string $view = 'plugged-host-core::filament.pages.welcome-tour';

    protected static ?string $slug = 'welcome';

    protected static bool $shouldRegisterNavigation = false;

    /**
     * Wider than the simple layout's default (Large), which is sized for a login form. A step
     * with five bullets in it needs the room.
     */
    protected Width|string|null $maxContentWidth = Width::TwoExtraLarge;

    public int $step = 1;

    /**
     * The tour, in order. Copy lives in lang/{en,de}/strings.php under tour.steps.<key>;
     * `bullets` is optional there and absent for the intro on purpose -- step 1 is one
     * paragraph and one button, because a wall of text is the thing people skip.
     *
     * @var array<int, array{key: string, icon: string}>
     */
    public const STEPS = [
        ['key' => 'intro', 'icon' => 'tabler-rocket'],
        ['key' => 'servers', 'icon' => 'tabler-server'],
        ['key' => 'console', 'icon' => 'tabler-terminal-2'],
        ['key' => 'inside', 'icon' => 'tabler-layout-sidebar'],
        ['key' => 'settings', 'icon' => 'tabler-settings'],
    ];

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

    public function hasLogo(): bool
    {
        return true;
    }

    public function getTitle(): string
    {
        return trans('plugged-host-core::strings.tour.title');
    }

    public function getHeading(): string
    {
        return '';
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    public function mount(): void
    {
        $user = auth()->user();

        if (!$user instanceof User) {
            return;
        }

        // ?restart=1 is the user-menu entry. Clear the finished markers but leave
        // acknowledged_at alone, so abandoning a replay halfway cannot re-arm the gate.
        if (request()->boolean('restart')) {
            PanelTour::restart($user);

            return;
        }

        // Reachable by URL, but pointless for someone who has already been through it -- send
        // them to the dashboard. Not canAccess(): a false there renders the panel's 403 page,
        // and there is nothing forbidden about having already seen the tour.
        if (PanelTour::isFinished($user)) {
            $this->redirect(filament()->getPanel('app')->getUrl());

            return;
        }

        $this->step = min(count(self::STEPS), max(1, PanelTour::state($user)['step']));
    }

    public function next(): void
    {
        $user = auth()->user();

        if (!$user instanceof User) {
            return;
        }

        $target = min(count(self::STEPS), $this->step + 1);

        // Leaving step 1 is the acknowledgement the gate waits for. Everything after it is
        // just a bookmark, so the two writes are deliberately different calls.
        if ($this->step === 1) {
            PanelTour::acknowledge($user, $target);
        } else {
            PanelTour::advance($user, $target);
        }

        $this->step = $target;
    }

    public function back(): void
    {
        $user = auth()->user();

        $this->step = max(1, $this->step - 1);

        if ($user instanceof User) {
            PanelTour::advance($user, $this->step);
        }
    }

    public function skipRest(): void
    {
        $user = auth()->user();

        if ($user instanceof User) {
            PanelTour::skip($user);
        }

        $this->leave();
    }

    public function finish(): void
    {
        $user = auth()->user();

        if ($user instanceof User) {
            PanelTour::complete($user);
        }

        $this->leave();
    }

    /**
     * The step being shown, with its copy resolved.
     *
     * @return array{key: string, icon: string, heading: string, body: string, bullets: array<int, string>}
     */
    public function currentStep(): array
    {
        $step = self::STEPS[$this->step - 1] ?? self::STEPS[0];

        $bullets = trans('plugged-host-core::strings.tour.steps.' . $step['key'] . '.bullets');

        return [
            ...$step,
            'heading' => trans('plugged-host-core::strings.tour.steps.' . $step['key'] . '.heading'),
            'body' => trans('plugged-host-core::strings.tour.steps.' . $step['key'] . '.body'),
            // Absent in lang => trans() hands back the key it was given, which is not an array.
            'bullets' => is_array($bullets) ? array_values($bullets) : [],
        ];
    }

    public function isFirstStep(): bool
    {
        return $this->step <= 1;
    }

    public function isLastStep(): bool
    {
        return $this->step >= count(self::STEPS);
    }

    public function stepCount(): int
    {
        return count(self::STEPS);
    }

    /**
     * navigate: false, because this is the one moment the sidebar has to appear -- the simple
     * layout has no navigation to swap into, so a wire:navigate visit would land the user on
     * the dashboard content inside a chrome-less shell.
     */
    private function leave(): void
    {
        $this->redirect(filament()->getPanel('app')->getUrl(), navigate: false);
    }
}
