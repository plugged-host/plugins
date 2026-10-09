<?php

namespace PluggedHost\Ai\Services;

use App\Enums\ContainerStatus;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonServerRepository;
use PluggedHost\Ai\Tools\ToolResult;
use PluggedHost\Core\Power\StartChain;
use Throwable;

/**
 * Power actions requested by neo, sent through the same start chain as the Start button.
 *
 * start/restart go to core's StartChain::interceptFor() first, which asks the enabled gates
 * (limbo, the start queue, the ad gate) in order. A URL back means one of them now owns the
 * action (restored, queued, or already sent behind the waiting room), so neo must not send it
 * again and takes the user to that page instead.
 */
final class PowerChain
{
    public function dispatch(User $user, Server $server, string $action): ToolResult
    {
        if (in_array($action, StartChain::GATED_ACTIONS, true)) {
            try {
                $url = app(StartChain::class)->interceptFor($user, $server, $action);
            } catch (Throwable $e) {
                // Fail open, like the Livewire hook: a chain that cannot answer must not lose the start.
                report($e);
                $url = null;
            }

            if (is_string($url) && $url !== '') {
                return ToolResult::redirect(
                    "The {$action} was handed to plugged.host's start flow (start queue, waiting room or hibernation restore). The user is being taken to that page now; tell them it continues there.",
                    $url,
                );
            }
        }

        app(DaemonServerRepository::class)->setServer($server)->power($action);

        if ($action === 'start' || $action === 'restart') {
            cache()->put("servers.{$server->uuid}.status", ContainerStatus::Starting, now()->addSeconds(15));
        }

        return ToolResult::ok("Sent \"{$action}\" to the server. It can take a moment; check the console or the status to confirm.");
    }
}
