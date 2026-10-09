<?php

namespace PluggedHost\Ads\Services;

use App\Enums\ContainerStatus;
use App\Models\Server;
use App\Models\User;
use PluggedHost\Ads\Filament\Server\Pages\StartGatePage;
use PluggedHost\Core\Power\StartGate;
use Throwable;

/**
 * The ad-funded waiting room in front of oversized starts: gate 30 in core's StartChain, asked
 * after limbo and the start queue (so it only sees starts neither of them took).
 *
 * Unlike the other gates it sends the start itself, on the click: cancelling the Livewire call
 * is what keeps the original code path from reaching wings, so without the dispatch below the
 * container would sit stopped for the whole wait. It only remembers an intent when that fails,
 * which is what makes the room ad time rather than a queue.
 */
class AdStartGate implements StartGate
{
    /**
     * Actions gated regardless of what the container is doing right now. `start` is only
     * meaningful on a stopped container, so for it the gate keeps its own "is it already up?"
     * check: pressing Start on a running server is a no-op and must not cost an ad view.
     */
    protected const ALWAYS_GATED_ACTIONS = ['restart'];

    public function __construct(protected StartGateService $gate) {}

    public function interceptStart(User $user, Server $server, string $action): ?string
    {
        if (!$this->gate->isEnabled()) {
            return null;
        }

        if (!$this->gate->requiresGate($user, $server, requireStopped: !in_array($action, self::ALWAYS_GATED_ACTIONS, true))) {
            return null;
        }

        // Built before anything is sent: once the start has gone out, this method must hand
        // back a URL no matter what, or the caller would send the same start a second time.
        $url = StartGatePage::getUrl(panel: 'server', tenant: $server);

        // The start goes out now, not when the ads finish. The room is where the user pays for
        // the start by watching them, so it is ad time rather than a queue — and the console
        // behind it shows the container already coming up. The accepted trade: closing the tab
        // mid-wait costs the ad view, not the start.
        try {
            if ($this->gate->dispatchPower($user, $server, $action)) {
                $this->gate->markDispatched($user, $server);
                cache()->put("servers.{$server->uuid}.status", ContainerStatus::Starting, now()->addSeconds(15));
            } else {
                // wings said no, or the node went away between the gate check and here.
                // Remember what was asked for so the release replays it — a failed dispatch
                // must not cost the user the start *and* the wait.
                $this->gate->rememberStartIntent($user, $server, $action);
            }

            $this->gate->holdStartedAt($user, $server);
        } catch (Throwable) {
            // Whatever happened after the attempt, the gate owns this start now.
        }

        return $url;
    }
}
