<?php

namespace PluggedHost\Core\Providers;

use App\Models\Server;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Support\ActivityLines;
use PluggedHost\Core\Support\SuspensionReason;

/**
 * Suspension reasons: every suspend/unsuspend of a server (upstream's SuspensionService, the
 * abuse scan, the admin toggles) is written to the activity log with the reason its caller gave
 * (SuspensionReason::with()), and the owner's server pages show it on SuspendedServerPage
 * (RedirectToSuspendedPage). Limbo parks servers with saveQuietly(), which fires no events.
 */
class SuspensionNoticePluginProvider extends ServiceProvider
{
    public function boot(): void
    {
        Server::updated(function (Server $server): void {
            if ($server->wasChanged('status')) {
                SuspensionReason::statusChanged($server, $server->getOriginal('status'), $server->status);
            }
        });

        $this->injectActivityDescriptions();
    }

    /**
     * Human-readable activity lines for the two events, without editing upstream's activity
     * lang file (Support\ActivityLines, which every plugin's activity lines go through).
     */
    private function injectActivityDescriptions(): void
    {
        ActivityLines::inject('plugged-host-core');
    }
}
