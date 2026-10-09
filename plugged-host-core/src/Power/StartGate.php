<?php

namespace PluggedHost\Core\Power;

use App\Models\Server;
use App\Models\User;

/**
 * A plugin that may hold a server start back (see StartChain).
 *
 * Return the URL of your page when this start must go through you, and null when it may go
 * straight through. A URL is a promise: your plugin now owns the power action and dispatches
 * it (or refuses it) itself, so nothing after you in the chain is asked and nobody else sends
 * it. Register from your plugin's provider:
 *
 *     app(StartChain::class)->register('plugged-host-queue', 20, StartQueueService::class);
 */
interface StartGate
{
    public function interceptStart(User $user, Server $server, string $action): ?string;
}
