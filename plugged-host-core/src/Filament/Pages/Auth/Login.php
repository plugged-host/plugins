<?php

namespace PluggedHost\Core\Filament\Pages\Auth;

use App\Filament\Pages\Auth\Login as PanelLogin;
use BladeUI\Icons\Exceptions\SvgNotFound;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use PluggedHost\Core\Services\LegalAcceptanceService;

class Login extends PanelLogin
{
    public function form(Schema $schema): Schema
    {
        if (config('auth.disable_password_login', false)) {
            return $schema->components([
                $this->getLegalAcceptanceFormComponent(),
                $this->getLegalOAuthFormComponent(),
                $this->getPasskeyFormComponent(),
            ]);
        }

        $components = [
            $this->getLoginFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getRememberFormComponent(),
            $this->getLegalAcceptanceFormComponent(),
            $this->getLegalOAuthFormComponent(),
            $this->getPasskeyFormComponent(),
        ];

        if ($captchaComponent = $this->captchaService->getActiveSchema()?->getFormComponent()) {
            $components[] = $captchaComponent
                ->hidden(fn () => filled($this->userUndertakingMultiFactorAuthentication));
        }

        return $schema->components($components);
    }

    public function authenticate(): ?LoginResponse
    {
        if (filled($this->userUndertakingMultiFactorAuthentication)) {
            if (!app(LegalAcceptanceService::class)->hasPending('password')) {
                throw ValidationException::withMessages([
                    'data.legal_acceptance' => 'Your legal acceptance expired. Return to the login form and accept the policies again.',
                ]);
            }

            return parent::authenticate();
        }

        $this->assertLegalAccepted();
        app(LegalAcceptanceService::class)->begin('password');

        return parent::authenticate();
    }

    public function beginOAuthAuthentication(string $driver): void
    {
        $this->assertLegalAccepted();
        app(LegalAcceptanceService::class)->begin("oauth:$driver");
        $this->redirect(route('auth.oauth.redirect', ['driver' => $driver], false), navigate: false);
    }

    public function beginPasskeyAuthentication(): bool
    {
        $this->assertLegalAccepted();
        app(LegalAcceptanceService::class)->begin('passkey');

        return true;
    }

    /**
     * Drops the "Forgot password?" link from the login form.
     *
     * Filament renders that link in exactly one place — as the *hint* of the password field in
     * Filament\Auth\Pages\Login::getPasswordFormComponent(), guarded by hasPasswordReset() — so
     * clearing the hint is the entire feature, and it stays clear on all three panels because
     * PluggedHostCorePlugin points each one's ->login() at this class.
     *
     * The panel keeps upstream's ->passwordReset() call, so /password-reset stays routable and
     * a reset link mailed from the admin panel still works; only the self-serve entry point on
     * the login screen is gone. Removing the route instead would break those flows too, and
     * would mean editing app/Providers/Filament/PanelProvider.php, which an upgrade reverts.
     */
    protected function getPasswordFormComponent(): Component
    {
        /** @var TextInput $component */
        $component = parent::getPasswordFormComponent();

        return $component->hint(null);
    }

    protected function getRememberFormComponent(): Component
    {
        return parent::getRememberFormComponent()
            ->default(true);
    }

    protected function getPasskeyFormComponent(): Component
    {
        return Actions::make([
            Action::make('passkey')
                ->label(trans('passkeys.authenticate_using_passkey'))
                ->color('gray')
                ->alpineClickHandler('$wire.beginPasskeyAuthentication().then((allowed) => allowed && window.authenticateWithPasskey())')
                ->extraAttributes(['type' => 'button']),
        ])->fullWidth()->hidden(fn () => !$this->request->isSecure());
    }

    private function getLegalAcceptanceFormComponent(): Component
    {
        $termsUrl = e(config('plugged-host-core.legal.terms_url'));
        $privacyUrl = e(config('plugged-host-core.legal.privacy_url'));

        return Checkbox::make('legal_acceptance')
            ->label(new HtmlString("I agree to the <a class=\"fi-link\" href=\"{$termsUrl}\" target=\"_blank\" rel=\"noopener noreferrer\">Terms of Service</a> and acknowledge the <a class=\"fi-link\" href=\"{$privacyUrl}\" target=\"_blank\" rel=\"noopener noreferrer\">Privacy Policy</a>."))
            ->accepted()
            ->required()
            ->dehydrated(false);
    }

    private function getLegalOAuthFormComponent(): Component
    {
        $actions = [];

        foreach ($this->oauthService->getEnabled() as $oauthSchema) {
            $id = $oauthSchema->getId();
            $icon = $oauthSchema->getIcon();

            if (is_string($icon)) {
                try {
                    $this->iconFactory->svg($icon);
                } catch (SvgNotFound) {
                    $icon = null;
                }
            }

            // Secondary, not the provider's brand colour: "Sign in" is the page's one primary
            // button. The provider's logo stays — it is how people recognise the option.
            $actions[] = Action::make("oauth_$id")
                ->label($oauthSchema->getName())
                ->icon($icon)
                ->color('gray')
                ->action(fn () => $this->beginOAuthAuthentication($id));
        }

        // Full width so the provider buttons match "Sign in" and the passkey button, which
        // both render full width; the default size is left alone because those two are
        // fi-size-md, so the boxes come out identical.
        //
        // ->alignment() is deliberately absent: Filament only emits the alignment class
        // when the action row is *not* full width (Actions::toEmbeddedHtml()), so pairing
        // the two would leave dead configuration behind.
        return Actions::make($actions)
            ->fullWidth();
    }

    private function assertLegalAccepted(): void
    {
        if (($this->data['legal_acceptance'] ?? false) === true) {
            return;
        }

        Notification::make()
            ->title('Legal acceptance required')
            ->body('Accept the Terms of Service and acknowledge the Privacy Policy to continue.')
            ->danger()
            ->send();

        throw ValidationException::withMessages([
            'data.legal_acceptance' => 'You must accept the Terms of Service and acknowledge the Privacy Policy.',
        ]);
    }
}
