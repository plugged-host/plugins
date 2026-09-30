<?php

namespace PluggedHost\Core\Providers;

use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Sign-in details belong to noprofit.space: the panel never changes a user's username, email
 * or password, and nobody unlinks a Discord account here. Only what is plugged.host's own
 * (roles, credits, language, time zone, servers) stays editable on the panel.
 *
 *  - Model guard: on an existing user, a change to username, email, password or
 *    is_managed_externally is put back before it is saved, whatever route made it (the admin
 *    form, the application API, the client API). Accounts are created by the noprofit.space
 *    sign-in (NoprofitSsoController), and a conversion clears the panel password through
 *    NoprofitLink::convert(), which writes with saveQuietly() and the query builder, so neither
 *    passes through this guard.
 *  - Admin user page: username and email are read-only, the password field and the
 *    "managed externally" toggle are gone, and so is upstream's OAuth section with its Unlink
 *    buttons (Discord is linked and unlinked on noprofit.space, which mirrors it here).
 *  - Admin "create user" is closed: a panel-made account has no noprofit.space account, so it
 *    could never sign in.
 *
 * Field locks use configureUsing() on the field classes and decide per render through
 * $livewire, so fields of the same name elsewhere (database hosts have a username and a
 * password) are untouched.
 */
class AccountIdentityLockPluginProvider extends ServiceProvider
{
    /** @var list<string> */
    public const LOCKED = ['username', 'email', 'password', 'is_managed_externally'];

    public function register(): void
    {
        TextInput::configureUsing(function (TextInput $input): void {
            match ($input->getName()) {
                'username', 'email' => $input
                    ->disabled(fn ($livewire): bool => self::onAdminUserPage($livewire))
                    ->helperText(fn ($livewire): ?string => self::onAdminUserPage($livewire)
                        ? trans('plugged-host-core::strings.noprofit.identity_locked')
                        : null),
                'password' => $input->hidden(fn ($livewire): bool => self::onAdminUserPage($livewire)),
                default => null,
            };
        });

        Toggle::configureUsing(function (Toggle $toggle): void {
            if ($toggle->getName() === 'is_managed_externally') {
                $toggle->hidden(fn ($livewire): bool => self::onAdminUserPage($livewire));
            }
        });

        // Upstream's OAuth section, identified by its heading (a plain translated string).
        Section::configureUsing(function (Section $section): void {
            $section->hidden(fn ($livewire): bool => self::onAdminUserPage($livewire)
                && $section->getHeading() === trans('profile.tabs.oauth'));
        });

        CreateAction::configureUsing(function (CreateAction $action): void {
            $action->hidden(fn ($livewire): bool => $livewire instanceof ListUsers);
        });
    }

    public function boot(): void
    {
        User::updating(function (User $user): void {
            $reverted = [];

            foreach (self::LOCKED as $attribute) {
                if ($user->isDirty($attribute)) {
                    $user->setAttribute($attribute, $user->getOriginal($attribute));
                    $reverted[] = $attribute;
                }
            }

            if ($reverted !== []) {
                Log::info('Ignored a panel change to sign-in details; they are managed on noprofit.space.', [
                    'user_id' => $user->getKey(),
                    'attributes' => $reverted,
                ]);
            }
        });

        Event::listen(RouteMatched::class, function (RouteMatched $event): void {
            if ($event->route->getName() === 'filament.admin.resources.users.create') {
                abort(404);
            }
        });
    }

    private static function onAdminUserPage(mixed $livewire): bool
    {
        return $livewire instanceof Page && $livewire::getResource() === UserResource::class;
    }
}
