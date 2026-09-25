<?php

namespace PluggedHost\Core\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Http\Controllers\NoprofitSsoController;

/**
 * noprofit.space sign-in for the panel.
 *
 * /login and /register replace Filament's pages (same route names) and send people to
 * noprofit.space; /sso/callback receives the signed token back. /migrate is the
 * password-proven "Continue with plugged.host" hand-off in the other direction. All logic,
 * including the identity rules and the 2FA challenge, lives in NoprofitSsoController.
 */
class MigrationEndpointServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web'])->group(function (): void {
            Route::get('/login', [NoprofitSsoController::class, 'login'])->name('filament.app.auth.login');
            Route::get('/register', [NoprofitSsoController::class, 'login'])->name('filament.app.auth.register');

            Route::get('/sso/callback', [NoprofitSsoController::class, 'callback'])->name('plugged-host.sso.callback');
            Route::get('/sso/challenge', [NoprofitSsoController::class, 'showChallenge'])->name('plugged-host.sso.challenge');
            Route::post('/sso/challenge', [NoprofitSsoController::class, 'verifyChallenge'])->name('plugged-host.sso.challenge.verify');
            Route::get('/sso/link', [NoprofitSsoController::class, 'link'])->middleware('auth')->name('plugged-host.sso.link');

            Route::get('/migrate', [NoprofitSsoController::class, 'showMigrate'])->name('plugged-host.migrate');
            Route::post('/migrate', [NoprofitSsoController::class, 'migrate'])->name('plugged-host.migrate.submit');
        });
    }
}
