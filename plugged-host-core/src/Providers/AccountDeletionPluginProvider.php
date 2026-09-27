<?php

namespace PluggedHost\Core\Providers;

use App\Enums\HeaderActionPosition;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Models\User;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Filament\Components\Actions\DeleteUserWithServersAction;

/**
 * Deleting accounts. People delete their own account on noprofit.space, which removes the
 * linked panel account and its servers through NoprofitServiceController::deleteAccount, so
 * the profile page offers no delete button of its own. Admins keep theirs.
 */
class AccountDeletionPluginProvider extends ServiceProvider
{
    public function boot(): void
    {
        /*
         * Guard against resurrection: if an account has been purged by AccountDeletionService,
         * refuse any subsequent save on that in-memory model instance. This prevents Laravel's
         * SessionGuard::logout() or any other post-delete hooks from re-inserting the deleted user
         * via an INSERT query when $user->exists is false.
         */
        User::saving(function (User $user) {
            if (!$user->exists && ($user->account_is_purged ?? false)) {
                return false;
            }
        });
    }

    public function register(): void
    {
        /*
         * Admin Area -> Users -> edit. Upstream's own DeleteAction is disabled() while the user
         * owns a server and offers no way out of that state, so this one appears exactly there and
         * removes the servers first. It hides itself otherwise, so a user with no servers still
         * gets upstream's single button rather than two that look alike.
         */
        EditUser::registerCustomHeaderActions(
            HeaderActionPosition::Before,
            DeleteUserWithServersAction::make()
        );
    }
}
