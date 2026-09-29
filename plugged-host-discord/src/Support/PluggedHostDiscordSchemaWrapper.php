<?php

namespace PluggedHost\Discord\Support;

use App\Extensions\OAuth\OAuthSchemaInterface;
use App\Models\User;
use BackedEnum;
use Laravel\Socialite\Contracts\User as OAuthUser;

/**
 * Decorator for Discord's OAuthSchemaInterface.
 *
 * Enforces account-takeover protections:
 * 1. shouldLinkMissingUser ALWAYS returns false: unauthenticated Discord OAuth logins
 *    must NEVER auto-link to an existing account based on email matching alone.
 * 2. shouldCreateMissingUser enforces that the Discord email is verified.
 */
class PluggedHostDiscordSchemaWrapper implements OAuthSchemaInterface
{
    public function __construct(
        private readonly OAuthSchemaInterface $inner,
    ) {}

    public function getId(): string
    {
        return $this->inner->getId();
    }

    public function getName(): string
    {
        return $this->inner->getName();
    }

    public function getConfigKey(): string
    {
        return $this->inner->getConfigKey();
    }

    public function getSocialiteProvider(): ?string
    {
        return $this->inner->getSocialiteProvider();
    }

    public function getServiceConfig(): array
    {
        return $this->inner->getServiceConfig();
    }

    public function getSettingsForm(): array
    {
        return $this->inner->getSettingsForm();
    }

    public function getSetupSteps(): array
    {
        return $this->inner->getSetupSteps();
    }

    public function getIcon(): null|string|BackedEnum
    {
        return $this->inner->getIcon();
    }

    public function getHexColor(): ?string
    {
        return $this->inner->getHexColor();
    }

    public function isEnabled(): bool
    {
        return $this->inner->isEnabled();
    }

    public function shouldCreateMissingUser(OAuthUser $user): bool
    {
        $rawUser = method_exists($user, 'getRaw') ? $user->getRaw() : ($user->user ?? []);

        // Disallow new account creation if the Discord email is unverified
        if (empty($rawUser['verified'])) {
            return false;
        }

        return $this->inner->shouldCreateMissingUser($user);
    }

    public function shouldLinkMissingUser(User $user, OAuthUser $oauthUser): bool
    {
        // Zero-click account takeover defense:
        // Never auto-link an existing account to an incoming OAuth login based on email.
        // Existing users must sign in with their password and link Discord from Settings/Profile.
        return false;
    }

    public function getInner(): OAuthSchemaInterface
    {
        return $this->inner;
    }

    /**
     * Delegate any other method calls to the inner schema.
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->inner->{$name}(...$arguments);
    }
}
