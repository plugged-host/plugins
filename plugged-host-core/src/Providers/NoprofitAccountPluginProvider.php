<?php

namespace PluggedHost\Core\Providers;

use App\Enums\HeaderActionPosition;
use App\Filament\Pages\Auth\EditProfile;
use App\Http\Controllers\Api\Remote\SftpAuthenticationController;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Http\Controllers\NoprofitAccountController;
use PluggedHost\Core\Http\Controllers\NoprofitServiceController;
use PluggedHost\Core\Http\Controllers\NoprofitSftpAuthenticationController;
use PluggedHost\Core\Http\Middleware\VerifyNoprofitSignature;
use PluggedHost\Core\Support\NoprofitLink;

/**
 * Converted accounts: a plugged.host account that was moved into a noprofit.space account
 * (NoprofitLink::isConverted) has its sign-in details on noprofit.space only.
 *
 *  - The signed endpoints noprofit.space uses to finish a conversion (NoprofitAccountController)
 *    and to delete, auto-migrate and mirror Discord for linked accounts (NoprofitServiceController).
 *  - SFTP password logins for those accounts are checked by noprofit.space.
 *  - Upstream's is_managed_externally locks username/email and hides the password in the
 *    client API. The profile page keeps only the panel's own tabs (ThemeAwareEditProfile in
 *    theme-v2); its header action points every linked account to noprofit.space, where
 *    sign-in details, passkeys, 2FA, Discord and account deletion are managed.
 *  - No reset mail goes out for such an account (a reset link issued before the conversion
 *    would otherwise still arrive). The panel changing sign-in details at all is stopped by
 *    AccountIdentityLockPluginProvider.
 */
class NoprofitAccountPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SftpAuthenticationController::class, NoprofitSftpAuthenticationController::class);

        EditProfile::registerCustomHeaderActions(
            HeaderActionPosition::Before,
            Action::make('manage_on_noprofit')
                ->label(fn () => trans('plugged-host-core::strings.noprofit.manage_account'))
                ->tooltip(fn () => trans('plugged-host-core::strings.noprofit.managed_hint'))
                ->color('gray')
                ->url(fn () => self::accountsUrl() . '/settings')
                ->visible(fn () => NoprofitLink::accountId(auth()->user()) !== null),
        );
    }

    public function boot(): void
    {
        Route::middleware([VerifyNoprofitSignature::class, 'throttle:60,1'])
            ->prefix('api/noprofit')
            ->group(function (): void {
                Route::post('/account/export', [NoprofitAccountController::class, 'export'])->name('plugged-host.noprofit.account.export');
                Route::post('/account/commit', [NoprofitAccountController::class, 'commit'])->name('plugged-host.noprofit.account.commit');
                Route::post('/account/summary', [NoprofitServiceController::class, 'summary'])->name('plugged-host.noprofit.account.summary');
                Route::post('/account/delete', [NoprofitServiceController::class, 'deleteAccount'])->name('plugged-host.noprofit.account.delete');
                Route::post('/discord/resolve', [NoprofitServiceController::class, 'resolveDiscord'])->name('plugged-host.noprofit.discord.resolve');
            });

        Route::middleware([VerifyNoprofitSignature::class . ':sync_secret', 'throttle:120,1'])
            ->post('/api/noprofit/discord/link', [NoprofitServiceController::class, 'linkDiscord'])
            ->name('plugged-host.noprofit.discord.link');

        Event::listen(NotificationSending::class, function (NotificationSending $event): ?bool {
            if ($event->notification instanceof ResetPassword
                && $event->notifiable instanceof User
                && NoprofitLink::isConverted($event->notifiable)) {
                return false;
            }

            return null;
        });

        FilamentView::registerRenderHook(
            PanelsRenderHook::AUTH_PASSWORD_RESET_REQUEST_FORM_AFTER,
            fn () => view('plugged-host-core::partials.password-reset-moved', ['accountsUrl' => self::accountsUrl()]),
        );
    }

    private static function accountsUrl(): string
    {
        return rtrim((string) config('plugged-host-core.noprofit.accounts_url'), '/');
    }
}
