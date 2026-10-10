<?php

namespace PluggedHost\Core\Services;

use App\Models\Server;
use App\Services\Servers\TransferServerService as BaseTransferServerService;

class GuardedTransferServerService extends BaseTransferServerService
{
    /**
     * Since Pelican rc.1 upstream returns nothing and throws NodeNotViableException when the
     * destination cannot take the server.
     */
    public function handle(Server $server, int $node_id, ?int $allocation_id = null, ?array $additional_allocations = [], array $backup_uuids = []): void
    {
        AllocationInvariant::allowPrimaryDetach(
            fn () => parent::handle($server, $node_id, $allocation_id, $additional_allocations, $backup_uuids)
        );
    }
}
