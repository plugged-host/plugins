<?php

namespace PluggedHost\Core\Filament\App\Pages;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use PluggedHost\Core\Support\OAuthPasswordGate;

/**
 * "Choose a password" for an account that was created by a Discord login.
 *
 * Upstream's UserCreationService, called with no password -- which is exactly what
 * OAuthController does for a first-time social login -- hashes Str::random(30) and
 * mails a reset token. So the account works, but only through Discord: lose the
 * Discord account and the only way back in is a mail nobody read. This page collects
 * a real password, and RequirePasswordForOAuthSignup keeps sending people here until
 * they do.
 *
 * It renders in the *simple* layout -- one centred card, panel logo, no sidebar --
 * because a gate with a full navigation shell around it invites clicking the
 * navigation, and every one of those clicks would bounce straight back here.
 * $layout is set rather than extending SimplePage: Panel::discoverPages() only
 * registers subclasses of Filament\Pages\Page, and SimplePage is a *sibling* of Page,
 * not a subclass -- a SimplePage dropped in this directory would get no route at all.
 * hasLogo() is the one member filament-panels::pages.simple needs that Page, unlike
 * SimplePage, does not define.
 *
 * The submit button comes from a schema Form component with livewireSubmitHandler(),
 * which is how Filament's own Login page does it. That renders the <form> itself, so
 * this page keeps the stock page view instead of needing a wrapper like the
 * server-onboarding wizard does.
 */
class SetPasswordPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $layout = 'filament-panels::components.layout.simple';

    protected string $view = 'filament-panels::pages.simple';

    protected static ?string $slug = 'set-password';

    protected static bool $shouldRegisterNavigation = false;

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public function hasLogo(): bool
    {
        return true;
    }

    public function getTitle(): string
    {
        return trans('plugged-host-core::strings.set_password.title');
    }

    public function getHeading(): string
    {
        return trans('plugged-host-core::strings.set_password.heading');
    }

    public function getSubheading(): ?string
    {
        return trans('plugged-host-core::strings.set_password.subheading');
    }

    public function mount(): void
    {
        // Reachable by URL, but pointless for anyone who does not owe us a password --
        // send them to the dashboard rather than showing a form that would then 403 on
        // save. Not canAccess(): a false there renders the panel's 403 page, and there
        // is nothing forbidden about already having a password.
        if (!OAuthPasswordGate::isPending(auth()->user())) {
            $this->redirect(filament()->getPanel('app')->getUrl());

            return;
        }

        $this->content->fill();
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Form::make([
                    TextInput::make('password')
                        ->label(trans('plugged-host-core::strings.set_password.password'))
                        ->helperText(trans('plugged-host-core::strings.set_password.password_helper'))
                        ->password()
                        ->revealable(filament()->arePasswordsRevealable())
                        ->autocomplete('new-password')
                        ->required()
                        // The same rule object the profile page uses, so the two forms
                        // cannot disagree about what a valid password is.
                        ->rule(Password::default())
                        ->same('passwordConfirmation')
                        ->dehydrateStateUsing(fn (string $state): string => Hash::make($state)),
                    TextInput::make('passwordConfirmation')
                        ->label(trans('plugged-host-core::strings.set_password.confirmation'))
                        ->password()
                        ->revealable(filament()->arePasswordsRevealable())
                        ->autocomplete('new-password')
                        ->required()
                        ->dehydrated(false),
                ])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label(trans('plugged-host-core::strings.set_password.submit'))
                                ->submit('save'),
                            // A way out that is not "set a password". Without it the only
                            // escape from the gate is closing the tab, and a session that
                            // cannot be ended is its own support ticket. postToUrl(),
                            // because Filament registers auth.logout as POST only -- a
                            // plain link would 405. Same call the user menu item makes.
                            Action::make('logout')
                                ->label(trans('plugged-host-core::strings.set_password.logout'))
                                ->color('gray')
                                ->link()
                                ->url(filament()->getPanel('app')->getLogoutUrl())
                                ->postToUrl(),
                        ])
                            ->fullWidth()
                            ->key('form-actions'),
                    ]),
            ]);
    }

    public function save(): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        abort_unless(OAuthPasswordGate::isPending($user), 403);

        // Validating read: Schema::getState() runs validate() first, and the field's
        // dehydrateStateUsing() has already hashed what comes back.
        $state = $this->content->getState();

        $password = (string) ($state['password'] ?? '');

        if ($password === '') {
            return;
        }

        // forceFill, not fill: password *is* in User::$fillable today, but going through
        // forceFill keeps this write immune to an upstream change of that list.
        $user->forceFill(['password' => $password])->save();

        // Re-authenticate and queue recaller cookie with the new password hash HMAC
        // so AuthenticateSession middleware does not log the user out when session expires.
        auth()->guard()->login($user, true);

        // Keep AuthenticateSession middleware from logging the user out immediately on the
        // next request: this action runs on /livewire/update (which does not execute panel
        // middleware), so the session still holds the user's old password hash unless we
        // update it here.
        if (request()->hasSession()) {
            $driver = auth()->getDefaultDriver();
            $hash = $user->getAuthPassword();

            try {
                if (method_exists(auth()->guard($driver), 'hashPasswordForCookie')) {
                    $hash = auth()->guard($driver)->hashPasswordForCookie($hash);
                }
            } catch (\Throwable) {
            }

            request()->session()->put(['password_hash_' . $driver => $hash]);
        }

        // Explicit, even though the User::updated listener in RegisterPluginProvider
        // clears it too -- this is the one caller that must not depend on an event being
        // wired up, and clear() is a no-op once the flag is gone.
        OAuthPasswordGate::clear($user);

        Notification::make()
            ->success()
            ->title(trans('plugged-host-core::strings.set_password.saved'))
            ->body(trans('plugged-host-core::strings.set_password.saved_body'))
            ->send();

        $this->redirect(filament()->getPanel('app')->getUrl());
    }
}
