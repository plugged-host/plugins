<?php

namespace PluggedHost\Noprofit\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Noprofit\Http\Controllers\NoprofitSsoController;

/**
 * noprofit.space sign-in for the panel.
 *
 * Every panel's login page (app /login, /admin/login, /server/login, same route names as
 * Filament's) and /register send people to noprofit.space; /sso/callback receives the signed
 * token back. /migrate is the password-proven "Continue with plugged.host" hand-off in the
 * other direction. All logic, including the identity rules, lives in NoprofitSsoController.
 *
 * Passkeys and authenticator codes are managed and checked on noprofit.space, so upstream's
 * passkey endpoints (sign-in, password confirmation, registering a new one) are closed: a
 * passkey registered on the panel would otherwise still sign in without noprofit.space.
 * Upstream's OAuth sign-in and linking (/auth/oauth/*) are closed for the same reason: a
 * Discord login there would sign in without noprofit.space (and its second factor), and
 * Discord is linked on noprofit.space, which mirrors the link here.
 * A later route with the same method and URI replaces the earlier one.
 */
class MigrationEndpointServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web'])->group(function (): void {
            Route::get('/login', [NoprofitSsoController::class, 'login'])->name('filament.app.auth.login');
            Route::get('/admin/login', [NoprofitSsoController::class, 'login'])->name('filament.admin.auth.login');
            Route::get('/server/login', [NoprofitSsoController::class, 'login'])->name('filament.server.auth.login');
            Route::get('/register', [NoprofitSsoController::class, 'login'])->name('filament.app.auth.register');

            Route::get('/sso/callback', [NoprofitSsoController::class, 'callback'])->name('plugged-host.sso.callback');
            Route::get('/sso/link', [NoprofitSsoController::class, 'link'])->middleware('auth')->name('plugged-host.sso.link');

            Route::get('/migrate', [NoprofitSsoController::class, 'showMigrate'])->name('plugged-host.migrate');
            Route::post('/migrate', [NoprofitSsoController::class, 'migrate'])->name('plugged-host.migrate.submit');
            Route::get('/migrate/challenge', [NoprofitSsoController::class, 'showChallenge'])->name('plugged-host.sso.challenge');
            Route::post('/migrate/challenge', [NoprofitSsoController::class, 'verifyChallenge'])->name('plugged-host.sso.challenge.verify');
        });

        $closed = [NoprofitSsoController::class, 'closed'];

        Route::get('/passkeys/login/options', $closed)->name('passkey.login-options');
        Route::post('/passkeys/login', $closed)->name('passkey.login');
        Route::get('/passkeys/confirm/options', $closed)->name('passkey.confirm-options');
        Route::post('/passkeys/confirm', $closed)->name('passkey.confirm');
        Route::get('/user/passkeys/options', $closed)->name('passkey.registration-options');
        Route::post('/user/passkeys', $closed)->name('passkey.store');

        // routes/auth.php is loaded by the panel's RouteServiceProvider, which boots after the
        // plugin providers, so these have to be added once the application has booted.
        $this->app->booted(function () use ($closed): void {
            Route::get('/auth/oauth/redirect/{driver}', $closed)->name('auth.oauth.redirect');
            Route::get('/auth/oauth/callback/{driver}', $closed)->name('auth.oauth.callback');
        });
    }
}
