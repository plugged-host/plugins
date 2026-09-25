<?php

namespace PluggedHost\Core\Support;

use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\Facades\Filament;
use Throwable;

/**
 * The panel's own multi-factor authentication, enforced on the noprofit.space sign-in paths.
 *
 * Every panel login now arrives through /sso/callback (MigrationEndpointServiceProvider
 * replaces /login), which bypasses Filament's login page and with it the MFA challenge. This
 * wraps the same Filament providers PanelProvider configures (app codes with recovery, email
 * codes), called with an explicit user because nobody is authenticated yet. The provider
 * instances come from the app panel itself, so their settings (recovery codes, windows) match
 * what the user enrolled with.
 */
final class PanelMfa
{
    public const APP = 'app';

    public const EMAIL = 'email';

    /**
     * Which second factor the user has turned on, preferring the authenticator app.
     */
    public static function method(User $user): ?string
    {
        try {
            if (self::app()?->isEnabled($user)) {
                return self::APP;
            }

            if (self::email()?->isEnabled($user)) {
                return self::EMAIL;
            }
        } catch (Throwable $e) {
            report($e);
        }

        return null;
    }

    /**
     * Start a challenge: email codes have to be sent first. Returns false when Filament's own
     * send limit refused a new code.
     */
    public static function begin(User $user): bool
    {
        if (self::method($user) !== self::EMAIL) {
            return true;
        }

        return (bool) self::email()?->sendCode($user);
    }

    public static function verify(User $user, string $code): bool
    {
        $code = trim($code);
        if ($code === '') {
            return false;
        }

        try {
            return match (self::method($user)) {
                self::APP => self::verifyApp($user, $code),
                self::EMAIL => (bool) self::email()?->verifyCode($code, $user),
                default => true,
            };
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    private static function verifyApp(User $user, string $code): bool
    {
        $app = self::app();
        if ($app === null) {
            return false;
        }

        if (preg_match('/^\d{6}$/', $code) === 1) {
            return $app->verifyCode($code, $app->getSecret($user), shouldPreventCodeReuse: true);
        }

        return $app->isRecoverable() && $app->verifyRecoveryCode($code, $user);
    }

    private static function app(): ?AppAuthentication
    {
        $provider = self::providers()[AppAuthentication::make()->getId()] ?? null;

        return $provider instanceof AppAuthentication ? $provider : null;
    }

    private static function email(): ?EmailAuthentication
    {
        $provider = self::providers()[EmailAuthentication::make()->getId()] ?? null;

        return $provider instanceof EmailAuthentication ? $provider : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function providers(): array
    {
        return Filament::getPanel('app')->getMultiFactorAuthenticationProviders();
    }
}
