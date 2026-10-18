<?php

namespace PluggedHost\Devbox\Support;

use App\Models\Allocation;
use PluggedHost\Devbox\Models\Devbox;
use PluggedHost\Devbox\Services\DevboxPki;

/**
 * VS Code in the browser: a devbox's address (https://<slug>.<web_domain>) and where the edge
 * finds it (its node and port). On only with a web domain configured and the devbox CA set up.
 */
final class DevboxWeb
{
    public const COOKIE = '__Host-ph_devbox';

    /** Second levels under a country code that are suffixes, not domains (example.co.uk). */
    private const COUNTRY_SECOND_LEVELS = ['ac', 'co', 'com', 'edu', 'go', 'gob', 'gov', 'ltd', 'me', 'mil', 'ne', 'net', 'nom', 'or', 'org', 'plc', 'sch'];

    public static function enabled(): bool
    {
        return self::domain() !== '' && DevboxPki::ready();
    }

    public static function domain(): string
    {
        return (string) config('plugged-host-devbox.web_domain', '');
    }

    /**
     * Whether $domain may be the web domain: a host name that is not the panel's own domain, not
     * under it and not above it. Devboxes serve whatever their users put there, and a browser
     * sends the panel's session cookie to every host its domain covers.
     */
    public static function allowedDomain(string $domain): bool
    {
        $domain = strtolower(trim($domain, '. '));
        if (preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $domain) !== 1) {
            return false;
        }

        foreach (self::panelDomains() as $panel) {
            if ($domain === $panel || str_ends_with($domain, '.' . $panel) || str_ends_with($panel, '.' . $domain)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The domains the panel's session cookie can reach: the registrable domain of APP_URL's host
     * (panel.example.com → example.com, panel.example.co.uk → example.co.uk) and SESSION_DOMAIN
     * when it is set.
     *
     * @return list<string>
     */
    public static function panelDomains(): array
    {
        $domains = [];

        $host = strtolower(trim((string) parse_url((string) config('app.url', ''), PHP_URL_HOST), '.'));
        if ($host !== '' && str_contains($host, '.') && filter_var($host, FILTER_VALIDATE_IP) === false) {
            $labels = explode('.', $host);
            $count = count($labels);
            $keep = $count >= 3 && strlen($labels[$count - 1]) === 2 && in_array($labels[$count - 2], self::COUNTRY_SECOND_LEVELS, true) ? 3 : 2;
            $domains[] = implode('.', array_slice($labels, -$keep));
        }

        $cookie = strtolower(trim((string) config('session.domain', ''), '. '));
        if ($cookie !== '') {
            $domains[] = $cookie;
        }

        return array_values(array_unique($domains));
    }

    public static function host(Devbox $devbox): string
    {
        return $devbox->slug . '.' . self::domain();
    }

    public static function url(Devbox $devbox, string $path = '/'): string
    {
        return 'https://' . self::host($devbox) . $path;
    }

    /**
     * The devbox for a host name of the web domain, or null.
     */
    public static function devboxForHost(string $host): ?Devbox
    {
        $suffix = '.' . self::domain();
        $host = strtolower(trim($host));
        if (self::domain() === '' || !str_ends_with($host, $suffix)) {
            return null;
        }

        $slug = substr($host, 0, -strlen($suffix));
        if (!preg_match('/^[a-z0-9]{8,24}$/', $slug)) {
            return null;
        }

        return Devbox::query()->where('slug', $slug)->first();
    }

    /**
     * node:port the edge proxies to, or null while the devbox has no server.
     */
    public static function upstream(Devbox $devbox): ?string
    {
        $server = $devbox->server;
        $allocation = $server ? Allocation::withoutGlobalScopes()->find($server->allocation_id) : null;
        if ($allocation === null) {
            return null;
        }

        $host = (string) ($allocation->alias ?: $server->node?->fqdn);

        return $host === '' ? null : "{$host}:{$allocation->port}";
    }
}
