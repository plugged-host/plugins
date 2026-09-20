<?php

namespace PluggedHost\Core\Services;

use App\Models\Server;
use App\Services\Servers\ServerDeletionService as BaseServerDeletionService;

class GuardedServerDeletionService extends BaseServerDeletionService
{
    public function handle(Server $server): void
    {
        AllocationInvariant::allowPrimaryDetach(fn () => parent::handle($server));
    }
}
