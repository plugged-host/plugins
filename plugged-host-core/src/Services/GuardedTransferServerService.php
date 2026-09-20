<?php

namespace PluggedHost\Core\Services;

use App\Models\Server;
use App\Services\Servers\TransferServerService as BaseTransferServerService;

class GuardedTransferServerService extends BaseTransferServerService
{
    public function handle(Server $server, int $node_id, ?int $allocation_id = null, ?array $additional_allocations = [], array $backup_uuids = []): bool
    {
        return AllocationInvariant::allowPrimaryDetach(
            fn (): bool => parent::handle($server, $node_id, $allocation_id, $additional_allocations, $backup_uuids)
        );
    }
}
