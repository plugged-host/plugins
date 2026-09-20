<?php

namespace Boy132\Subdomains\Policies;

use App\Policies\DefaultAdminPolicies;

class CloudflareDomainPolicy
{
    use DefaultAdminPolicies;

    protected string $modelName = 'cloudflare_domain';
}
