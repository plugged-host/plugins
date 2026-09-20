<?php

namespace PluggedHost\Core\Http\Middleware;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use PluggedHost\Core\Services\LegalAcceptanceService;
use Symfony\Component\HttpFoundation\Response;

class RequireLegalAcceptanceForOAuth
{
    public function __construct(private readonly LegalAcceptanceService $legalAcceptance) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (
            ($request->routeIs('auth.oauth.redirect') || $request->routeIs('auth.oauth.callback'))
            && auth()->guest()
            && !$this->legalAcceptance->hasPending('oauth:' . $request->route('driver'))
        ) {
            Notification::make()
                ->title('Legal acceptance required')
                ->body('Accept the Terms of Service and acknowledge the Privacy Policy before continuing.')
                ->danger()
                ->send();

            return redirect()->route('filament.app.auth.login');
        }

        return $next($request);
    }
}
