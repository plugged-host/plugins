<?php

namespace PluggedHost\Core\Providers;

use App\Enums\HeaderActionPosition;
use App\Enums\TabPosition;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Pages\Auth\EditProfile;
use App\Models\User;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Filament\Components\Actions\DeleteAccountAction;
use PluggedHost\Core\Filament\Components\Actions\DeleteUserWithServersAction;

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
            if (! $user->exists && ($user->account_is_purged ?? false)) {
                return false;
            }
        });
    }

    public function register(): void
    {
        EditProfile::registerCustomHeaderActions(
            HeaderActionPosition::Before,
            DeleteAccountAction::make()
        );

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

        EditProfile::registerCustomTabs(
            TabPosition::After,
            Tab::make('danger_zone')
                ->label(fn () => trans('plugged-host-core::strings.danger_zone'))
                ->icon('tabler-alert-triangle')
                ->schema([
                    Section::make(fn () => trans('plugged-host-core::strings.delete_account'))
                        ->description(fn () => trans('plugged-host-core::strings.delete_account_description'))
                        ->headerActions([
                            DeleteAccountAction::make(),
                        ]),
                ])
        );
    }
}
