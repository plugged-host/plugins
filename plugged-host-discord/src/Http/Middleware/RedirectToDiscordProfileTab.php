<?php

namespace PluggedHost\Discord\Http\Middleware;

use App\Filament\Pages\Auth\EditProfile;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectToDiscordProfileTab
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // If completing a Discord OAuth callback while authenticated, redirect directly to the Discord profile tab
        $isDiscordCallback = ($request->routeIs('auth.oauth.callback') || $request->is('auth/oauth/callback/discord'))
            && ($request->route('driver') === 'discord' || $request->is('*discord*'));

        if ($isDiscordCallback && auth()->check() && class_exists(EditProfile::class)) {
            return redirect()->to(EditProfile::getUrl(['tab' => 'discord::data::tab'], panel: 'app'));
        }

        return $response;
    }
}
