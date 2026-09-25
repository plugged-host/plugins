<?php

namespace PluggedHost\Core\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures authenticated panel sessions always carry a valid "remember me" (recaller) cookie.
 *
 * Prevents premature session dropouts and logout loops by:
 * 1. Automatically generating/refreshing the recaller cookie if it is missing, expired,
 *    or invalidated by a password update.
 * 2. Pruning stale host-only cookies (e.g. from panel.plugged.host) that conflict with the
 *    shared .plugged.host domain cookie.
 */
class EnsureRememberMeCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        $this->ensureValidRecallerCookie($request);

        /** @var Response $response */
        $response = $next($request);

        $this->cleanDuplicateHostCookies($request, $response);

        return $response;
    }

    private function ensureValidRecallerCookie(Request $request): void
    {
        $user = $request->user();

        if (!$user instanceof User) {
            return;
        }

        $guard = auth()->guard();

        if (!$guard instanceof SessionGuard) {
            return;
        }

        $recallerName = $guard->getRecallerName();
        $cookieValue = (string) $request->cookies->get($recallerName, '');

        $needsRefresh = false;

        if ($cookieValue === '' || empty($user->getRememberToken())) {
            $needsRefresh = true;
        } else {
            $segments = explode('|', $cookieValue);

            if (count($segments) < 3 || (int) $segments[0] !== (int) $user->getAuthIdentifier() || !hash_equals((string) $user->getRememberToken(), $segments[1])) {
                $needsRefresh = true;
            } else {
                $storedHash = $segments[2];
                $currentHash = $guard->hashPasswordForCookie($user->getAuthPassword());

                if (!hash_equals($currentHash, $storedHash) && !hash_equals($user->getAuthPassword(), $storedHash)) {
                    $needsRefresh = true;
                }
            }
        }

        if ($needsRefresh && !$guard->getCookieJar()->hasQueued($recallerName)) {
            $guard->login($user, true);
        }
    }

    private function cleanDuplicateHostCookies(Request $request, Response $response): void
    {
        $cookieHeader = (string) $request->header('cookie', '');

        if ($cookieHeader === '') {
            return;
        }

        $sessionName = config('session.cookie', 'pelican_session');
        if (substr_count($cookieHeader, $sessionName . '=') > 1) {
            $response->headers->setCookie(new Cookie(
                $sessionName,
                '',
                1,
                config('session.path', '/'),
                null,
                (bool) config('session.secure', true),
                true,
                false,
                'lax'
            ));
        }

        $guard = auth()->guard();
        if ($guard instanceof SessionGuard) {
            $recallerName = $guard->getRecallerName();
            if (substr_count($cookieHeader, $recallerName . '=') > 1) {
                $response->headers->setCookie(new Cookie(
                    $recallerName,
                    '',
                    1,
                    config('session.path', '/'),
                    null,
                    (bool) config('session.secure', true),
                    true,
                    false,
                    'lax'
                ));
            }
        }
    }
}
