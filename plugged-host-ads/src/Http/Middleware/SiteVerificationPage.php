<?php

namespace PluggedHost\Ads\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PluggedHost\Ads\Services\SiteVerification;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ad networks verify a site by reading a meta tag on its home page, but a signed-out visit to
 * `/` is redirected to /login and on to the account portal on another domain, so their checker
 * never sees one. A request for `/` without a session cookie (a checker, a first visit) gets a
 * small page instead: the verification tags and a zero-second refresh to /login, which is where
 * the redirect went. Anyone with a session cookie gets the panel exactly as before, and with no
 * tag configured this does nothing at all.
 *
 * Global (prepended in AdsPluginServiceProvider), because `/` belongs to the panel's own route
 * and its authentication runs before any group middleware a plugin could add.
 */
class SiteVerificationPage
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->isMethod('GET') && !$request->isMethod('HEAD')) {
            return $next($request);
        }

        if ($request->path() !== '/' || $request->cookies->has((string) config('session.cookie'))) {
            return $next($request);
        }

        $tags = app(SiteVerification::class)->tags();

        if ($tags === '') {
            return $next($request);
        }

        $title = e((string) config('app.name', 'Panel'));
        $login = e(url('/login'));

        return response(<<<HTML
            <!doctype html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <title>{$title}</title>
            {$tags}
            <meta http-equiv="refresh" content="0;url={$login}">
            </head>
            <body><a href="{$login}">{$title}</a></body>
            </html>
            HTML, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
