<?php

namespace PluggedHost\Core\Auth;

use Illuminate\Auth\SessionGuard;

/**
 * The panel's session guard with a "remember me" cookie of its own name.
 *
 * Laravel names that cookie `remember_<guard>_<sha1 of the guard class>`, so every Laravel app
 * with a `web` guard uses the same name. When two of them share a parent domain -- prod sets
 * its cookies for `.plugged.host`, dev.plugged.host is under it -- the browser sends dev both
 * cookies, and with the same APP_KEY dev decrypts prod's: the wrong account's token, so the
 * account is signed in again and again and the console's requests end up logged out. Set
 * SESSION_REMEMBER_COOKIE on the panel that sits under the other's domain (dev) and it reads
 * only its own. See SessionCookiePluginProvider.
 */
class NamedRecallerSessionGuard extends SessionGuard
{
    protected string $recallerName = '';

    public function setRecallerName(string $name): static
    {
        $this->recallerName = $name;

        return $this;
    }

    public function getRecallerName()
    {
        return $this->recallerName !== '' ? $this->recallerName : parent::getRecallerName();
    }
}
