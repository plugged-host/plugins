<?php

namespace PluggedHost\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every /api request is answered in JSON, whatever its Accept header asks for. Pelican renders
 * a failed sign-in as JSON only for requests that ask for JSON; anything else (a browser, or a
 * connector or script that accepts any type) was redirected to /login, which is noprofit.space's
 * sign-in, instead of getting a 401 that says the key is missing or wrong. 403s, 404s and 429s
 * render as JSON for the same reason. Authentication itself is untouched: a browser that is
 * signed in to the panel still reads the client API with its session, as upstream allows.
 *
 * The raw header is read, not expectsJson(): Symfony caches the parsed Accept header on first
 * use, and a cached value would outlive the header set here.
 */
class ForceJsonOnApi
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api', 'api/*') && !self::asksForJson((string) $request->headers->get('Accept', ''))) {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }

    private static function asksForJson(string $accept): bool
    {
        return str_contains($accept, '/json') || str_contains($accept, '+json');
    }
}
