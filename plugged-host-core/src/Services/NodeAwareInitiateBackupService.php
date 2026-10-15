<?php

namespace PluggedHost\Core\Services;

use App\Exceptions\DisplayException;
use App\Models\Backup;
use App\Models\Server;
use App\Services\Backups\InitiateBackupService;
use PluggedHost\Core\Support\NodeBackups;

/**
 * Upstream's InitiateBackupService, refusing a server on a node with backups turned off
 * (Support\NodeBackups) before anything else runs. Before, not after: at its limit a scheduled
 * backup deletes the server's oldest one first, which must not happen when the new one is
 * refused anyway.
 *
 * Bound over the upstream class (NodeBackupsPluginProvider), so the Backups page, the client
 * API, schedule tasks and neo's create_backup all go through it.
 */
class NodeAwareInitiateBackupService extends InitiateBackupService
{
    public function handle(Server $server, ?string $name = null, bool $override = false): Backup
    {
        if (NodeBackups::disabledFor($server)) {
            throw new DisplayException(trans('plugged-host-core::strings.backups.disabled'));
        }

        return parent::handle($server, $name, $override);
    }
}
