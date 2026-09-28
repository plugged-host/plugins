<?php

namespace PluggedHost\Core\Providers;

use Illuminate\Auth\AuthManager;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Auth\NamedRecallerSessionGuard;

/**
 * Gives the session guard its own "remember me" cookie name when SESSION_REMEMBER_COOKIE is
 * set (NamedRecallerSessionGuard explains why dev needs it). Unset -- prod -- nothing changes.
 *
 * The session driver is re-registered through Auth::extend(), which the AuthManager consults
 * before its built-in creators; the guard is built exactly as createSessionDriver() builds it.
 */
class SessionCookiePluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $name = trim((string) config('plugged-host-core.session.remember_cookie', ''));

        if ($name === '' || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) !== 1) {
            return;
        }

        $extend = function (AuthManager $auth) use ($name): void {
            $auth->extend('session', function ($app, string $guardName, array $config) use ($auth, $name) {
                $guard = new NamedRecallerSessionGuard(
                    $guardName,
                    $auth->createUserProvider($config['provider'] ?? null),
                    $app['session.store'],
                    rehashOnLogin: $app['config']->get('hashing.rehash_on_login', true),
                    timeboxDuration: $app['config']->get('auth.timebox_duration', 200000),
                    hashKey: $app['config']->get('app.key'),
                );

                $guard->setRecallerName($name);
                $guard->setCookieJar($app['cookie']);
                $guard->setDispatcher($app['events']);
                $guard->setRequest($app->refresh('request', $guard, 'setRequest'));

                if (isset($config['remember'])) {
                    $guard->setRememberDuration($config['remember']);
                }

                return $guard;
            });
        };

        $this->app->resolved('auth')
            ? $extend($this->app['auth'])
            : $this->app->afterResolving('auth', $extend);
    }
}
