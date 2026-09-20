<?php

namespace PluggedHost\Core\Support;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * One place that knows about users.oauth_password_pending_at.
 *
 * The column is added by 008_add_oauth_password_pending_to_users and says "this
 * account was created by an OAuth login and still has the random password upstream
 * generated for it". Three callers share it -- GuardedUserCreationService sets it,
 * RequirePasswordForOAuthSignup reads it, SetPasswordPage clears it -- plus a
 * User::updated listener in RegisterPluginProvider that clears it whenever the
 * password changes by any route at all (profile page, reset mail, admin edit), so
 * the gate cannot outlive the thing it is asking for.
 *
 * isPending() deliberately reads the attribute off an already-hydrated model and
 * never queries: an unmigrated column is simply absent from $attributes, so it
 * reads as null and the gate stays shut, instead of throwing "Unknown column" on
 * every authenticated request. That matters because a plugin tree lands on disk a
 * few seconds before `artisan migrate` runs.
 *
 * The writes cannot be that relaxed -- an UPDATE naming a missing column is a hard
 * error -- so they check Schema once per process and swallow failures. Neither
 * write is worth failing a signup or a password change over.
 */
final class OAuthPasswordGate
{
    public const COLUMN = 'oauth_password_pending_at';

    /**
     * Memo for the Schema::hasColumn() probe. Per-process, not per-request: the
     * answer cannot change without a deploy, and this is on the signup path.
     */
    private static ?bool $available = null;

    /**
     * Does this user still owe us a password?
     *
     * Zero queries. Zero exceptions. See the class docblock.
     */
    public static function isPending(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        // An externally managed account (LDAP/SSO) has its credentials owned elsewhere
        // and the profile page hides the password field for it entirely -- asking would
        // be a gate with no door.
        if ($user->is_managed_externally) {
            return false;
        }

        return $user->getAttribute(self::COLUMN) !== null;
    }

    /**
     * Mark a freshly created OAuth account as owing a password.
     */
    public static function flag(User $user): void
    {
        self::write($user, now()->toDateTimeString());
    }

    /**
     * Stop asking. Idempotent, and a no-op when there was nothing to clear -- so the
     * User::updated listener can call it on every password change without writing.
     */
    public static function clear(User $user): void
    {
        if (!self::isPending($user)) {
            return;
        }

        self::write($user, null);
    }

    private static function write(User $user, ?string $value): void
    {
        if (!self::available()) {
            return;
        }

        try {
            // forceFill + saveQuietly: the column is not in the model's $fillable
            // (it cannot be -- User is upstream), and a flag write has no business
            // firing model events that the panel's own observers listen to.
            $user->forceFill([self::COLUMN => $value])->saveQuietly();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private static function available(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }

        try {
            return self::$available = Schema::hasColumn('users', self::COLUMN);
        } catch (Throwable) {
            return self::$available = false;
        }
    }
}
