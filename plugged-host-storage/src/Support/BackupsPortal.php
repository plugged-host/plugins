<?php

namespace PluggedHost\Storage\Support;

/**
 * Where the backups portal answers: on its own host when BACKUPS_PORTAL_URL names one
 * (backups.<domain>, pointed at this panel's web server), else on the panel itself at /backups.
 * Every link to the portal (the server's Backups page, Theme v2's sidebar, the portal's own
 * redirects) is built here, so all of them follow the setting.
 *
 * A portal URL on the panel's own host counts as unset: the portal's routes sit at the root of
 * its host, and on the panel's host they would answer in place of the panel's own pages.
 */
final class BackupsPortal
{
    /** The portal's own host, or '' when it lives on the panel at /backups. */
    public static function host(): string
    {
        $host = strtolower((string) parse_url(self::configured(), PHP_URL_HOST));
        $panelHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return $host !== '' && $host !== $panelHost ? $host : '';
    }

    /** Whether the portal lives on the panel (/backups), sharing the panel's session. */
    public static function onPanel(): bool
    {
        return self::host() === '';
    }

    /** The portal's base URL, without a trailing slash. */
    public static function url(): string
    {
        if (self::onPanel()) {
            return rtrim(url('/backups'), '/');
        }

        $parts = parse_url(self::configured());
        $scheme = in_array($parts['scheme'] ?? '', ['http', 'https'], true) ? $parts['scheme'] : 'https';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . self::host() . $port;
    }

    /** The portal with one of the owner's servers picked out (?server=<short uuid>). */
    public static function forServer(?string $uuidShort): string
    {
        return filled($uuidShort) ? self::url() . '/?server=' . rawurlencode((string) $uuidShort) : self::url();
    }

    /** BACKUPS_PORTAL_URL, with https:// in front when it was written without a scheme. */
    private static function configured(): string
    {
        $url = trim((string) config('plugged-host-storage.portal_url', ''));

        return $url !== '' && !str_contains($url, '://') ? 'https://' . ltrim($url, '/') : $url;
    }
}
