<?php

namespace PluggedHost\Noprofit\Identity;

use PluggedHost\Core\Contracts\AccountPortal;
use PluggedHost\Noprofit\Support\NoprofitLink;

/**
 * noprofit.space as the account portal: sign-in details, passkeys, 2FA, the Discord link and
 * account deletion are all managed there.
 */
final class NoprofitAccountPortal implements AccountPortal
{
    public function external(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'noprofit.space';
    }

    public function manageUrl(?string $page = null): ?string
    {
        return NoprofitLink::accountsUrl($page ?? '');
    }

    public function discordLinkUrl(): ?string
    {
        return NoprofitLink::accountsUrl('/settings');
    }
}
