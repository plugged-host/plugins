<?php

namespace PluggedHost\Core\Filament\Components\Actions;

use App\Exceptions\DisplayException;
use App\Models\Server;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use PluggedHost\Core\Services\AccountDeletionService;
use Throwable;

/**
 * "Delete account", on the profile page.
 *
 * The interesting part is what happens when the account owns servers. It used to refuse outright
 * -- and since two thirds of accounts here own at least one server, "delete my account" was a
 * button that did nothing for most people, with no route to a working outcome except deleting
 * every server by hand first. It now offers to take the servers with it, but only behind consent
 * that cannot be given by accident: the servers are named in the modal, a checkbox has to be
 * ticked, and the username has to be typed out. Deleting a server destroys its files, worlds,
 * databases and backups, so this is the one place in the panel where three confirmations is the
 * proportionate number rather than paranoia.
 */
class DeleteAccountAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'delete_account';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn () => trans('plugged-host-core::strings.delete_account'));
        $this->color('danger');
        $this->icon('tabler-trash');
        $this->requiresConfirmation();
        $this->modalHeading(fn () => trans('plugged-host-core::strings.delete_account'));
        $this->modalSubmitActionLabel(fn () => trans('plugged-host-core::strings.delete_account'));

        $this->modalDescription(function (): string {
            $description = trans('plugged-host-core::strings.modals.delete_account_confirm')
                . ' ' . trans('plugged-host-core::strings.modals.delete_account_warning');

            $servers = $this->ownedServers();

            if ($servers->isEmpty()) {
                return $description;
            }

            return $description . ' ' . trans('plugged-host-core::strings.modals.delete_account_servers', [
                'count' => $servers->count(),
                'servers' => $servers->pluck('name')->implode(', '),
            ]);
        });

        $this->form(function (): array {
            $user = $this->currentUser();

            if (!$user instanceof User) {
                return [];
            }

            $fields = [];

            if (filled($user->password) && !$user->is_managed_externally) {
                $fields[] = TextInput::make('confirm_password')
                    ->label(trans('plugged-host-core::strings.current_password'))
                    ->password()
                    ->required()
                    ->currentPassword();
            }

            $servers = $this->ownedServers();

            if ($servers->isNotEmpty()) {
                $fields[] = Checkbox::make('delete_servers')
                    ->label(trans('plugged-host-core::strings.modals.delete_account_servers_ack', ['count' => $servers->count()]))
                    ->required()
                    ->accepted()
                    ->validationMessages([
                        'accepted' => trans('plugged-host-core::strings.notifications.account_delete_servers_not_acknowledged'),
                    ]);

                $fields[] = TextInput::make('confirm_username')
                    ->label(trans('plugged-host-core::strings.modals.delete_account_confirm_username', ['username' => $user->username]))
                    ->required()
                    ->autocomplete(false)
                    ->in([$user->username])
                    ->validationMessages([
                        'in' => trans('plugged-host-core::strings.notifications.account_delete_username_mismatch'),
                    ]);
            }

            return $fields;
        });

        $this->action(function (Action $action, AccountDeletionService $service, array $data) {
            $user = $this->currentUser();

            if (!$user instanceof User) {
                $action->halt();

                return;
            }

            try {
                // Ensure remember token is wiped before logout so SessionGuard::logout() does not
                // attempt to cycle remember token on a deleted model via updateRememberToken() -> save(),
                // which would execute an INSERT query and resurrect the deleted user row.
                $user->setRememberToken(null);

                $deleted = $service->handle($user, withServers: (bool) ($data['delete_servers'] ?? false));

                $user->setRememberToken(null);
                $user->account_is_purged = true;

                Filament::auth()->logout();

                if (request()->hasSession()) {
                    request()->session()->invalidate();
                    request()->session()->regenerateToken();
                }

                Notification::make()
                    ->title(trans('plugged-host-core::strings.notifications.account_deleted'))
                    ->body($deleted > 0
                        ? trans('plugged-host-core::strings.notifications.account_deleted_with_servers', ['count' => $deleted])
                        : trans('plugged-host-core::strings.notifications.account_deleted_success'))
                    ->success()
                    ->send();

                $action->redirect(Filament::getPanel('app')->getLoginUrl(), navigate: false);
            } catch (DisplayException $exception) {
                Notification::make()
                    ->title(trans('plugged-host-core::strings.notifications.account_delete_failed'))
                    ->body($exception->getMessage())
                    ->danger()
                    ->persistent()
                    ->send();

                $action->halt();
            } catch (Throwable $exception) {
                report($exception);

                Notification::make()
                    ->title(trans('plugged-host-core::strings.notifications.account_delete_failed'))
                    ->body(trans('plugged-host-core::strings.notifications.account_delete_unknown_error'))
                    ->danger()
                    ->persistent()
                    ->send();

                $action->halt();
            }
        });
    }

    protected function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * The servers this account owns, as the modal and the form both need them.
     *
     * @return Collection<int, Server>
     */
    protected function ownedServers(): Collection
    {
        $user = $this->currentUser();

        if (!$user instanceof User) {
            return collect();
        }

        return $user->servers()->get(['id', 'name', 'uuid_short']);
    }
}
