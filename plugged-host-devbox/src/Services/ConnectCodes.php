<?php

namespace PluggedHost\Devbox\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use PluggedHost\Devbox\Models\Devbox;
use Throwable;

/**
 * Connect codes: what a person types as the SSH password for their devbox. Shown in the panel
 * (behind its sign-in and 2FA), one per person per start of the devbox, kept encrypted in the
 * cache until the devbox stops or max_hours pass. Account passwords are never accepted over SSH:
 * they would skip 2FA and send every guess a bot makes on to noprofit.space.
 */
final class ConnectCodes
{
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public static function for(Devbox $devbox, User $user): string
    {
        $key = self::key($devbox, $user);

        try {
            $stored = Cache::get($key);
            if (is_string($stored) && $stored !== '') {
                return Crypt::decryptString($stored);
            }
        } catch (Throwable) {
            // Unreadable (APP_KEY changed): make a new one.
        }

        $code = self::generate();
        Cache::put($key, Crypt::encryptString($code), now()->addHours(max(1, (int) config('plugged-host-devbox.max_hours', 12))));

        return $code;
    }

    public static function check(Devbox $devbox, User $user, string $code): bool
    {
        try {
            $stored = Cache::get(self::key($devbox, $user));

            return is_string($stored) && $stored !== '' && hash_equals(Crypt::decryptString($stored), $code);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Retires every code of the devbox (it stopped, or starts again with new ones).
     */
    public static function forgetAll(Devbox $devbox): void
    {
        Cache::forever(self::generationKey($devbox), self::generation($devbox) + 1);
    }

    private static function key(Devbox $devbox, User $user): string
    {
        return 'plugged-host-devbox:code:' . $devbox->id . ':' . self::generation($devbox) . ':' . $user->id;
    }

    private static function generation(Devbox $devbox): int
    {
        return (int) Cache::get(self::generationKey($devbox), 0);
    }

    private static function generationKey(Devbox $devbox): string
    {
        return 'plugged-host-devbox:code-generation:' . $devbox->id;
    }

    /**
     * xxxx-xxxx-xxxx-xxxx from 31 unambiguous characters: about 79 bits.
     */
    private static function generate(): string
    {
        $groups = [];
        for ($g = 0; $g < 4; $g++) {
            $group = '';
            for ($i = 0; $i < 4; $i++) {
                $group .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $groups[] = $group;
        }

        return implode('-', $groups);
    }
}
