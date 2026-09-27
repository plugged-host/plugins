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
use PluggedHost\Core\Http\Controllers\NoprofitSftpAuthenticationController;
use PluggedHost\Core\Http\Middleware\VerifyNoprofitSignature;
use PluggedHost\Core\Support\NoprofitLink;

/**
 * Converted accounts: a plugged.host account that was moved into a noprofit.space account
 * (NoprofitLink::isConverted) has its sign-in details on noprofit.space only.
 *
 *  - The two signed endpoints noprofit.space uses to finish a conversion (NoprofitAccountController).
 *  - SFTP password logins for those accounts are checked by noprofit.space.
 *  - Upstream's is_managed_externally locks username/email and hides the password on the
 *    profile page and the client API; the header action points to where they are managed now.
 *  - Whatever route tries to set a password on such an account anyway (admin edit, a reset
 *    link issued before the conversion) is ignored, and no reset mail goes out for it.
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
                ->visible(fn () => NoprofitLink::isConverted(auth()->user())),
        );
    }

    public function boot(): void
    {
        Route::middleware([VerifyNoprofitSignature::class, 'throttle:60,1'])
            ->prefix('api/noprofit/account')
            ->group(function (): void {
                Route::post('/export', [NoprofitAccountController::class, 'export'])->name('plugged-host.noprofit.account.export');
                Route::post('/commit', [NoprofitAccountController::class, 'commit'])->name('plugged-host.noprofit.account.commit');
            });

        // Judged on the stored state, so an admin who deliberately un-ticks "managed
        // externally" can still give the account a panel password again.
        User::saving(function (User $user): void {
            if (!$user->exists || !$user->isDirty('password')) {
                return;
            }

            if ($user->getOriginal('is_managed_externally') && $user->getOriginal(NoprofitLink::COLUMN) !== null) {
                $user->setAttribute('password', $user->getOriginal('password'));
            }
        });

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
