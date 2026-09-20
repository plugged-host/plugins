<?php

namespace PluggedHost\Core\Filament\Pages\Auth;

use App\Extensions\Captcha\CaptchaService;
use App\Extensions\OAuth\OAuthService;
use BladeUI\Icons\Exceptions\SvgNotFound;
use BladeUI\Icons\Factory as IconFactory;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\RegistrationResponse;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use PluggedHost\Core\Services\LegalAcceptanceService;

class Register extends BaseRegister
{
    protected CaptchaService $captchaService;

    protected OAuthService $oauthService;

    protected IconFactory $iconFactory;

    public function boot(CaptchaService $captchaService, OAuthService $oauthService, IconFactory $iconFactory): void
    {
        $this->captchaService = $captchaService;
        $this->oauthService = $oauthService;
        $this->iconFactory = $iconFactory;
    }

    public function form(Schema $schema): Schema
    {
        $components = [];

        if (config('plugged-host-core.register.password_registration_enabled', true)) {
            $components = [
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
            ];

            if ($captchaComponent = $this->getCaptchaComponent()) {
                $components[] = $captchaComponent;
            }
        }

        $components[] = $this->getLegalAcceptanceFormComponent();

        if (config('plugged-host-core.register.oauth_registration_enabled', true)) {
            $components[] = $this->getOAuthFormComponent();
        }

        return $schema
            ->components($components);
    }

    public function register(): ?RegistrationResponse
    {
        if (!config('plugged-host-core.register.password_registration_enabled', true)) {
            Notification::make()
                ->title('Password registration is disabled')
                ->body('Use one of the available OAuth providers to create your account.')
                ->danger()
                ->send();

            return null;
        }

        $this->assertLegalAccepted();
        app(LegalAcceptanceService::class)->begin('registration');

        return parent::register();
    }

    protected function getFormActions(): array
    {
        return config('plugged-host-core.register.password_registration_enabled', true) ? parent::getFormActions() : [];
    }

    private function getCaptchaComponent(): ?Component
    {
        return $this->captchaService->getActiveSchema()?->getFormComponent();
    }

    private function getOAuthFormComponent(): Component
    {
        $actions = [];

        foreach ($this->oauthService->getEnabled() as $schema) {
            $id = $schema->getId();

            $color = $schema->getHexColor();
            $color = is_string($color) ? Color::hex($color) : null;

            $icon = $schema->getIcon();
            if (is_string($icon)) {
                try {
                    $this->iconFactory->svg($icon);
                } catch (SvgNotFound) {
                    $icon = null;
                }
            }

            $actions[] = Action::make("oauth_$id")
                ->label('Continue with ' . $schema->getName())
                ->icon($icon)
                ->color($color)
                ->action(fn () => $this->beginOAuthAuthentication($id));
        }

        // Same shape as the login page's provider buttons: full width, default size. No
        // ->alignment() here either -- Actions::toEmbeddedHtml() drops the alignment class
        // whenever the row is full width, so the centring it used to ask for never reached
        // the markup.
        return Actions::make($actions)
            ->fullWidth();
    }

    protected function getNameFormComponent(): Component
    {
        /** @var TextInput $parent */
        $parent = parent::getNameFormComponent();

        return $parent
            ->name('username')
            ->statePath('username')
            ->label(trans('profile.username'))
            ->unique($this->getUserModel(), 'username');
    }

    public function beginOAuthAuthentication(string $driver): void
    {
        $this->assertLegalAccepted();
        app(LegalAcceptanceService::class)->begin("oauth:$driver");
        $this->redirect(route('auth.oauth.redirect', ['driver' => $driver], false), navigate: false);
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
