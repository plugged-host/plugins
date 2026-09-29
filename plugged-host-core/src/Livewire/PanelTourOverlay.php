<?php

namespace PluggedHost\Core\Livewire;

use App\Models\Server;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use PluggedHost\Core\Support\PanelTour;
use PluggedHost\Core\Support\PanelTourSteps;

/**
 * The welcome tour, drawn over the real panel: the page stays where it is, everything but
 * the element being explained is dimmed, and a card next to it says what it is.
 *
 * It replaced a five-step text card in the simple layout, which people clicked through in
 * about a second (every recorded tour was skipped one second after it was acknowledged) and
 * which could only *describe* where things are. Here the thing is on screen, lit up.
 *
 * Mounted by CoreRuntimeServiceProvider at BODY_END, only on the page a part belongs to --
 * the server list for 'panel', a server console for 'server' -- and only while that part
 * is due (PanelTour::panelPartActive() / serverPartActive()). So it costs nothing on every
 * other request and never has to cope with a page it was not written for.
 *
 * The steps are resolved once, at mount, and locked: a Livewire update request has no
 * Filament tenant, so the server part could not work out its own steps again on "Next".
 *
 * The overlay takes every click, so nothing on the page can be used mid-tour and the person
 * is always on the page the current step talks about. Leaving is always one click away
 * ("Skip tour", or Escape), apart from step 1, which is the acknowledgement RequirePanelTour
 * waits for -- the same rule the old card had.
 */
class PanelTourOverlay extends Component
{
    #[Locked]
    public string $part = 'panel';

    /** @var array<int, array{key: string, targets: array<int, string>, sidebar: bool, heading: string, body: string, bullets: array<int, string>, final: ?string}> */
    #[Locked]
    public array $steps = [];

    /** @var ?array{name: string, url: string} */
    #[Locked]
    public ?array $server = null;

    #[Locked]
    public ?string $createUrl = null;

    public int $step = 1;

    public bool $open = true;

    public function mount(string $part = 'panel'): void
    {
        $user = auth()->user();
        $this->part = $part === 'server' ? 'server' : 'panel';

        if (!$user instanceof User) {
            $this->open = false;

            return;
        }

        if ($this->part === 'server') {
            $tenant = Filament::getTenant();

            if (!$tenant instanceof Server) {
                $this->open = false;

                return;
            }

            $this->steps = PanelTourSteps::server($tenant);
            $this->step = PanelTour::state($user)['server_step'];
        } else {
            $panel = PanelTourSteps::panel($user);

            $this->steps = $panel['steps'];
            $this->server = $panel['server'];
            $this->createUrl = $panel['create_url'];
            $this->step = PanelTour::state($user)['step'];
        }

        $this->step = min(count($this->steps), max(1, $this->step));
        $this->open = $this->steps !== [];
    }

    public function next(): void
    {
        $user = $this->user();

        if ($user === null) {
            return;
        }

        $target = min(count($this->steps), $this->step + 1);

        if ($this->part === 'server') {
            PanelTour::advanceServer($user, $target);
        } elseif ($this->step === 1) {
            // Leaving step 1 is the acknowledgement the gate waits for. Everything after it is
            // just a bookmark, so the two writes are deliberately different calls.
            PanelTour::acknowledge($user, $target);
        } else {
            PanelTour::advance($user, $target);
        }

        $this->step = $target;
    }

    public function back(): void
    {
        $user = $this->user();

        $this->step = max(1, $this->step - 1);

        if ($user === null) {
            return;
        }

        $this->part === 'server'
            ? PanelTour::advanceServer($user, $this->step)
            : PanelTour::advance($user, $this->step);
    }

    /**
     * "Skip tour" and Escape. Not offered on step 1 of the first part: that step is the
     * acknowledgement, and skip() records one anyway, so it would just be a second way to
     * say "Show me around".
     */
    public function skip(): void
    {
        $user = $this->user();

        if ($user !== null) {
            $this->part === 'server' ? PanelTour::completeServer($user) : PanelTour::skip($user);
        }

        $this->open = false;
    }

    public function finish(): void
    {
        $user = $this->user();

        if ($user !== null) {
            $this->part === 'server' ? PanelTour::completeServer($user) : PanelTour::complete($user);
        }

        $this->open = false;
    }

    /**
     * Last step, for someone who has a server: finish this part and walk into the server,
     * where the second part opens by itself on the console. A full page load rather than
     * wire:navigate, because the console is excluded from the panel's SPA mode.
     */
    public function openServer(): void
    {
        $this->finish();

        if ($this->server !== null) {
            $this->redirect($this->server['url']);
        }
    }

    /**
     * Last step, for a new account: finish this part and go and make the first server. The
     * second part waits for its console.
     */
    public function createServer(): void
    {
        $this->finish();

        if ($this->createUrl !== null) {
            $this->redirect($this->createUrl, navigate: true);
        }
    }

    /**
     * @return array{key: string, targets: array<int, string>, sidebar: bool, heading: string, body: string, bullets: array<int, string>, final: ?string}|null
     */
    public function current(): ?array
    {
        return $this->steps[$this->step - 1] ?? null;
    }

    public function render(): View
    {
        return view('plugged-host-core::livewire.panel-tour-overlay', [
            'current' => $this->open ? $this->current() : null,
            'count' => count($this->steps),
        ]);
    }

    private function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
