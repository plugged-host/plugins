<?php

namespace PluggedHost\Core\Services;

use App\Models\Server;
use App\Services\Servers\ServerConfigurationStructureService;
use Illuminate\Support\Facades\Log;
use PluggedHost\Core\Support\SystemServers;

/**
 * Upstream's Wings configuration with one rule on top: no mount into a volume directory (or
 * /etc/pelican, the docker socket) reaches Wings, except the one source the server's owning
 * plugin vouches for (SystemServers::allowedVolumeSource, a devbox's own game server volume).
 *
 * Every path that sends a server's configuration to Wings resolves the structure service from
 * the container (remote server details, the boot-time bulk list, build changes, egg config),
 * so binding this subclass covers them all without touching upstream.
 */
class GuardedServerConfigurationStructureService extends ServerConfigurationStructureService
{
    protected function returnFormat(Server $server): array
    {
        $response = parent::returnFormat($server);

        if (empty($response['mounts']) || !is_array($response['mounts'])) {
            return $response;
        }

        $base = (string) ($server->node?->daemon_base ?? '');
        $allowed = null;
        $kept = [];

        foreach ($response['mounts'] as $mount) {
            $source = (string) ($mount['source'] ?? '');

            if (!SystemServers::isProtectedSource($source, [$base])) {
                $kept[] = $mount;

                continue;
            }

            $allowed ??= SystemServers::allowedVolumeSource($server) ?? '';

            if ($allowed !== '' && SystemServers::clean($source) === $allowed) {
                $kept[] = $mount;

                continue;
            }

            Log::warning('plugged-host: dropped a mount into a protected path from the Wings configuration', [
                'server' => $server->uuid,
                'source' => $source,
                'target' => $mount['target'] ?? null,
            ]);
        }

        if ($kept === []) {
            unset($response['mounts']);
        } else {
            $response['mounts'] = array_values($kept);
        }

        return $response;
    }
}
