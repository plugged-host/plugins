<?php

namespace PluggedHost\Noprofit\Services;

use App\Exceptions\DisplayException;
use App\Models\Server;
use App\Models\Subuser;
use App\Models\User;
use App\Services\Subusers\SubuserCreationService;

/**
 * Subuser invites only reach people who already have a plugged.host account.
 *
 * Upstream creates a panel user for an unknown email. Here accounts come from noprofit.space,
 * and an email held by a panel user that was never linked is treated as someone else's
 * account: its real owner is asked for that account's password, which nobody has, and a
 * password reset cannot set one (the identity lock reverts it). So anyone with a server could
 * invite an email and lock its owner out of plugged.host. Invite them after they sign up.
 */
class GuardedSubuserCreationService extends SubuserCreationService
{
    public function handle(Server $server, string $email, array $permissions): Subuser
    {
        if (!User::withoutGlobalScopes()->where('email', $email)->exists()) {
            throw new DisplayException(trans('plugged-host-noprofit::strings.noprofit.subuser_unknown_email', ['app' => config('app.name')]));
        }

        return parent::handle($server, $email, $permissions);
    }
}
