<?php

namespace PluggedHost\Core\Filament\Components\Actions;

use App\Exceptions\DisplayException;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\Server;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use PluggedHost\Core\Services\AccountDeletionService;
use Throwable;

/**
 * The admin-side delete for the case upstream's delete button gives up on.
 *
 * `EditUser`'s own `DeleteAction` is `disabled()` whenever the user owns a server, with a tooltip
 * explaining why. That is a defensible default -- but it is also a dead end: there is no admin
 * surface anywhere that removes the servers, so the only route to deleting an account was to open
 * each server, delete it, then come back. This action appears in exactly the situation upstream's
 * refuses in, and does the whole job through the same service the profile page uses.
 *
 * It is deliberately *not* a replacement for upstream's button: for a user with no servers, that
 * button already works and this one stays hidden.
 */
class DeleteUserWithServersAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'delete_user_with_servers';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn () => trans('plugged-host-core::strings.admin_delete_user.label'));
        $this->color('danger');
        $this->icon('tabler-trash-x');
        $this->requiresConfirmation();
        $this->modalHeading(fn (User $record) => trans('plugged-host-core::strings.admin_delete_user.heading', ['username' => $record->username]));
        $this->modalSubmitActionLabel(fn () => trans('plugged-host-core::strings.admin_delete_user.submit'));

        /*
         * Only where upstream gives up, only for someone allowed to delete this user, and never on
         * yourself -- User::booted()'s `deleting` listener throws on a self-delete through a
         * request that still resolves to that user, and an admin deleting themselves out of the
         * admin panel is not a flow worth supporting.
         */
        $this->visible(fn (?User $record): bool => $record instanceof User
            && $record->servers()->count() > 0
            && user()?->id !== $record->id
            && (bool) user()?->can('delete', $record));

        $this->modalDescription(function (User $record): string {
            $servers = $this->ownedServers($record);

            return trans('plugged-host-core::strings.admin_delete_user.description', [
                'count' => $servers->count(),
                'servers' => $servers->pluck('name')->implode(', '),
            ]);
        });

        $this->form(fn (User $record): array => [
            Checkbox::make('delete_servers')
                ->label(trans('plugged-host-core::strings.admin_delete_user.acknowledge', ['count' => $this->ownedServers($record)->count()]))
                ->required()
                ->accepted()
                ->validationMessages([
                    'accepted' => trans('plugged-host-core::strings.notifications.account_delete_servers_not_acknowledged'),
                ]),
            TextInput::make('confirm_username')
                ->label(trans('plugged-host-core::strings.admin_delete_user.confirm_username', ['username' => $record->username]))
                ->required()
                ->autocomplete(false)
                ->in([$record->username])
                ->validationMessages([
                    'in' => trans('plugged-host-core::strings.notifications.account_delete_username_mismatch'),
                ]),
            Checkbox::make('force')
                ->label(trans('plugged-host-core::strings.admin_delete_user.force'))
                ->helperText(trans('plugged-host-core::strings.admin_delete_user.force_helper'))
                ->default(false),
        ]);

        $this->action(function (Action $action, User $record, AccountDeletionService $service, array $data) {
            $username = $record->username;

            try {
                $deleted = $service->handle($record, withServers: true, force: (bool) ($data['force'] ?? false));

                Notification::make()
                    ->title(trans('plugged-host-core::strings.notifications.user_deleted'))
                    ->body(trans('plugged-host-core::strings.notifications.user_deleted_body', [
                        'username' => $username,
                        'count' => $deleted,
                    ]))
                    ->success()
                    ->send();

                $action->redirect(UserResource::getUrl('index'), navigate: false);
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

    /**
     * @return Collection<int, Server>
     */
    protected function ownedServers(User $user): Collection
    {
        return $user->servers()->get(['id', 'name', 'uuid_short']);
    }
}
