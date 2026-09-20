<?php

namespace PluggedHost\Core\Services;

use App\Models\Server;
use App\Services\Servers\BuildModificationService as BaseBuildModificationService;

class GuardedBuildModificationService extends BaseBuildModificationService
{
    public function handle(Server $server, array $data): Server
    {
        return AllocationInvariant::allowPrimaryDetach(
            fn (): Server => parent::handle($server, $data)
        );
    }
}
