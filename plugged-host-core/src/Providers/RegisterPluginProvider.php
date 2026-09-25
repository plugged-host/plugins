<?php

namespace PluggedHost\Core\Providers;

use App\Models\User;
use App\Services\Users\UserCreationService;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Services\GuardedUserCreationService;
use PluggedHost\Core\Support\OAuthPasswordGate;

class RegisterPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        // plugged.host's OAuth account-creation rules -- honour the "OAuth
        // registration" toggle, and de-duplicate the provider nickname against the
        // unique index on users.username -- both live in the guarded service.
        //
        // They used to be an in-place edit to app/Extensions/OAuth/OAuthService.php
        // and app/Http/Controllers/Auth/OAuthController.php. The documented panel
        // upgrade extracts the release tarball straight over pelican/, so those
        // edits were reverted on every update with no error and no warning, quietly
        // re-enabling OAuth registration. A container rebind survives an upgrade
        // untouched, which is why it lives here instead.
        $this->app->bind(UserCreationService::class, GuardedUserCreationService::class);
    }

    public function boot(): void
    {
        /*
         * An account created by a Discord login has a password nobody knows (see
         * OAuthPasswordGate). Collecting one is RequirePasswordForOAuthSignup's job, and it is
         * registered from PluggedHostCorePlugin::register() with $panel->authMiddleware(), not
         * from here.
         *
         * It *was* `$router->pushMiddlewareToGroup('web', RequirePasswordForOAuthSignup::class)`
         * on this line, by analogy with LegalAuthenticationPluginProvider's OAuth gate. That
         * analogy is wrong, and quietly so: the legal gate acts on `auth.oauth.*`, which really
         * are `web` routes, while this gate has to act on the pages a *signed-in* user browses --
         * and Filament panels do not use the `web` group at all. PanelProvider enumerates their
         * middleware individually, so nothing pushed to `web` reaches an app-, server- or
         * admin-panel page. The flag was being set on every Discord signup and never read.
         */

        // Whatever route the password actually changes by -- the profile page, a reset
        // mail, an admin editing the account -- stop asking. Without this the flag would
        // outlive the thing it asks for, and someone who used the reset link out of their
        // signup mail would be nagged forever.
        //
        // saveQuietly() inside clear() is what keeps this from recursing.
        User::updated(function (User $user): void {
            if ($user->wasChanged('password')) {
                OAuthPasswordGate::clear($user);

                // If the user whose password was changed is currently authenticated,
                // re-issue their recaller cookie with the new password hash HMAC so
                // AuthenticateSession does not log them out when their session expires.
                if (auth()->check() && auth()->id() === $user->id) {
                    $guard = auth()->guard();
                    if ($guard instanceof \Illuminate\Auth\SessionGuard) {
                        $guard->login($user, true);
                    }
                }
            }
        });
    }
}
