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

    public static function enabled(): bool
    {
        return self::domain() !== '' && DevboxPki::ready();
    }

    public static function domain(): string
    {
        return (string) config('plugged-host-devbox.web_domain', '');
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
