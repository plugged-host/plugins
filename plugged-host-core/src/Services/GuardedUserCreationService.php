<?php

namespace PluggedHost\Core\Services;

use App\Models\User;
use App\Services\Users\UserCreationService as BaseUserCreationService;
use Illuminate\Support\Str;
use PluggedHost\Core\Exceptions\OAuthRegistrationDisabledException;
use PluggedHost\Core\Support\OAuthPasswordGate;

/**
 * Applies plugged.host's OAuth account-creation rules -- the registration toggle,
 * username de-duplication, and flagging the passwordless account the provider
 * hands us -- without editing the panel's own OAuthService or OAuthController.
 *
 * Bound over App\Services\Users\UserCreationService in RegisterPluginProvider,
 * the same container-rebind pattern CoreRuntimeServiceProvider uses for the
 * Guarded* server services. Upstream resolves UserCreationService out of the
 * container in OAuthController's constructor, so the rebind is picked up with no
 * cooperation needed from core.
 *
 * All three key off $data['oauth'], which upstream's OAuthController is the only
 * caller to set. Admin- and API-created users are deliberately left alone: a
 * clashing username there should still surface as a validation error rather than
 * being silently renamed behind the operator's back.
 */
class GuardedUserCreationService extends BaseUserCreationService
{
    /**
     * users.username is varchar(255) with a unique index. Trimming the base to
     * 240 leaves room for the numeric suffix appended on a collision.
     */
    private const USERNAME_LIMIT = 240;

    /**
     * @param  array<array-key, mixed>  $data
     *
     * @throws OAuthRegistrationDisabledException
     */
    public function handle(array $data): User
    {
        if (!isset($data['oauth'])) {
            return parent::handle($data);
        }

        if (!config('plugged-host-core.register.oauth_registration_enabled', true)) {
            throw new OAuthRegistrationDisabledException(
                'Refused to create an account for an OAuth login: OAuth registration is disabled.'
            );
        }

        $data['username'] = $this->uniqueUsername($data);

        $user = parent::handle($data);

        // An OAuth signup arrives with no password, so parent::handle() hashes
        // Str::random(30) and mails a reset token. Until someone acts on that mail the
        // account can only be reached through the provider -- so flag it, and let
        // RequirePasswordForOAuthSignup collect a real password on the way in.
        //
        // Keyed off an empty password rather than off $data['oauth'] alone: if upstream
        // ever starts passing one through OAuthController, there is nothing to ask for.
        if (($data['password'] ?? '') === '') {
            OAuthPasswordGate::flag($user);
        }

        return $user;
    }

    /**
     * The provider's nickname, normalised and de-duplicated against existing accounts.
     *
     * Without this, a second OAuth user whose provider nickname is already taken
     * trips the unique index on users.username, and upstream turns that integrity
     * violation into the same dead-end "No linked User found" redirect an unknown
     * account gets -- so the person simply cannot sign up.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function uniqueUsername(array $data): string
    {
        $base = (string) ($data['username'] ?? '');

        if ($base === '') {
            $base = str((string) ($data['email'] ?? ''))->before('@')->toString();
        }

        if ($base === '') {
            $base = 'user';
        }

        $base = Str::of($base)->lower()->trim()->substr(0, self::USERNAME_LIMIT)->toString();

        // A nickname that normalises away to nothing falls through to the panel's
        // own "email local part + 3 random characters" fallback in parent::handle().
        if ($base === '') {
            return '';
        }

        $username = $base;
        $suffix = 2;

        while (User::whereUsername($username)->exists()) {
            $username = Str::limit($base, self::USERNAME_LIMIT, '') . $suffix;
            $suffix++;
        }

        return $username;
    }
}
