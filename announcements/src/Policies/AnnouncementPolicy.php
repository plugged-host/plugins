<?php

namespace Boy132\Announcements\Policies;

use App\Policies\DefaultAdminPolicies;

class AnnouncementPolicy
{
    use DefaultAdminPolicies;

    protected string $modelName = 'announcement';
}
