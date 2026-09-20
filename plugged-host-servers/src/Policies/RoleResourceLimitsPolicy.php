<?php

namespace PluggedHost\Servers\Policies;

use App\Policies\DefaultAdminPolicies;

class RoleResourceLimitsPolicy
{
    use DefaultAdminPolicies;

    protected string $modelName = 'roleResourceLimits';
}
