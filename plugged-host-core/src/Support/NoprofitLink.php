<?php

namespace PluggedHost\Core\Support;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * One place that knows about users.noprofit_account_id (011_add_noprofit_account_id_to_users).
 *
 * The link is the identity anchor between the panel and noprofit.space: /sso/callback finds
 * the panel user through it, and neo sends it so noprofit.space meters the right account.
 *
 * Shaped after PanelTour: the column is not in upstream User::$fillable, so writes go through
 * forceFill()->saveQuietly(), and a Schema::hasColumn() memo keeps everything a no-op until
 * the migration has run.
 */
final class NoprofitLink
{
    public const COLUMN = 'noprofit_account_id';

    /**
     * Per-process memo for the Schema::hasColumn() probe.
     */
    private static ?bool $available = null;

    public static function accountId(?User $user): ?int
    {
        if ($user === null || !self::available()) {
            return null;
        }

        $id = $user->getAttribute(self::COLUMN);

        return $id === null ? null : (int) $id;
    }

    public static function userFor(int $accountId): ?User
    {
        if ($accountId < 1 || !self::available()) {
            return null;
        }

        return User::query()->where(self::COLUMN, $accountId)->first();
    }

    /**
     * Link a panel user to a noprofit.space account. The id is first taken off any other
     * panel user so the unique index cannot block the sign-in that is writing it: the caller
     * only ever passes a link that noprofit.space itself vouched for.
     */
    public static function remember(User $user, int $accountId): void
    {
        if ($accountId < 1 || !self::available() || self::accountId($user) === $accountId) {
            return;
        }

        try {
            User::query()
                ->where(self::COLUMN, $accountId)
                ->whereKeyNot($user->getKey())
                ->update([self::COLUMN => null]);

            $user->forceFill([self::COLUMN => $accountId])->saveQuietly();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * What a gateway call sends to identify the caller: the link when there is one, plus the
     * panel id so noprofit.space can fall back to its migration link.
     *
     * @return array{account_id: ?int, external_user_id: string}
     */
    public static function identity(User $user): array
    {
        return [
            'account_id' => self::accountId($user),
            'external_user_id' => (string) $user->getKey(),
        ];
    }

    private static function available(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }

        try {
            return self::$available = Schema::hasColumn('users', self::COLUMN);
        } catch (Throwable) {
            return false;
        }
    }
}
