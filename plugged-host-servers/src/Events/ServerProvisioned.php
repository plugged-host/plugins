<?php

namespace PluggedHost\Servers\Events;

use App\Models\Server;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A user created a server (ServerProvisioner), right after Pelican created it. $switchable says
 * whether its egg has software types in the GameCatalog (a game a plugin manages, e.g.
 * Minecraft), which is when that plugin sets the server up (plugged-host-minecraft: onboarding,
 * the EULA).
 */
final class ServerProvisioned
{
    use Dispatchable;

    public function __construct(
        public readonly Server $server,
        public readonly bool $switchable,
    ) {}
}
