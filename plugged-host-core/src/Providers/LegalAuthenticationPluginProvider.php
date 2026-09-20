<?php

namespace PluggedHost\Core\Providers;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Passkeys;
use PluggedHost\Core\Http\Middleware\RequireLegalAcceptanceForOAuth;
use PluggedHost\Core\Services\LegalAcceptanceService;

class LegalAuthenticationPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LegalAcceptanceService::class);
    }

    public function boot(LegalAcceptanceService $legalAcceptance, Router $router): void
    {
        $router->pushMiddlewareToGroup('web', RequireLegalAcceptanceForOAuth::class);

        Event::listen(Login::class, function (Login $event) use ($legalAcceptance): void {
            if ($event->user instanceof User) {
                $legalAcceptance->record($event->user);
            }
        });

        Passkeys::authorizeLoginUsing(
            fn (): bool => $legalAcceptance->hasPending('passkey'),
        );
    }
}
