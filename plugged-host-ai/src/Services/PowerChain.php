<?php

namespace PluggedHost\Ai\Services;

use App\Enums\ContainerStatus;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonServerRepository;
use PluggedHost\Ai\Tools\ToolResult;
use PluggedHost\Core\Support\Plugins;
use Throwable;

/**
 * Power actions requested by neo, sent through the same start chain as the Start button.
 *
 * start/restart go to plugged-host-ads' PowerActionInterceptor::interceptFor() first, which
 * asks limbo, the start queue and the ad gate in that order. A URL back means one of them now
 * owns the action (restored, queued, or already sent behind the waiting room), so neo must
 * not send it again and takes the user to that page instead. Without the ads plugin there is
 * no chain, exactly as on the Console page.
 */
final class PowerChain
{
    private const INTERCEPTOR = 'PluggedHost\\Ads\\Services\\PowerActionInterceptor';

    public function dispatch(User $user, Server $server, string $action): ToolResult
    {
        if (in_array($action, ['start', 'restart'], true) && Plugins::available(self::INTERCEPTOR)) {
            try {
                $url = app(self::INTERCEPTOR)->interceptFor($user, $server, $action);
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
