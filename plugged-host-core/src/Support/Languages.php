<?php

namespace PluggedHost\Core\Support;

/**
 * The languages users may pick (`plugged-host-core.languages`, ALLOWED_LANGUAGES). Empty means
 * every language Pelican and language plugins ship, as upstream.
 */
final class Languages
{
    /** @return list<string> allowed codes as written (pt_BR, zh_CN …); empty = all */
    public static function allowed(): array
    {
        $codes = array_map('trim', explode(',', (string) config('plugged-host-core.languages', '')));

        return array_values(array_unique(array_filter($codes, fn (string $code): bool => preg_match('/^[A-Za-z]{2,3}(_[A-Za-z]{2,4})?$/', $code) === 1)));
    }

    public static function isAllowed(?string $code): bool
    {
        $allowed = self::allowed();

        return $allowed === [] || in_array((string) $code, $allowed, true);
    }

    /** What a user whose saved language is not allowed gets: the app locale when allowed, else the first allowed one. */
    public static function fallback(): string
    {
        $default = (string) config('app.locale', 'en');

        return self::isAllowed($default) ? $default : (self::allowed()[0] ?? 'en');
    }
}
