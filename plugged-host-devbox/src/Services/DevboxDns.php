<?php

namespace PluggedHost\Devbox\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Devbox\Models\Devbox;
use PluggedHost\Devbox\Support\DevboxWeb;
use RuntimeException;

/**
 * One proxied DNS record per devbox for VS Code in the browser: <slug>.<web_domain> → the edge.
 * One level under the domain on purpose: Cloudflare's free edge certificate covers *.<domain>,
 * not deeper names. No catch-all wildcard: the domain is the user-content domain the subdomains
 * plugin also writes to, so only records carrying this plugin's comment are ever changed.
 *
 * The Cloudflare token is the subdomains plugin's when that plugin is on, else the devbox
 * plugin's own (DEVBOX_CLOUDFLARE_TOKEN).
 */
final class DevboxDns
{
    public const COMMENT = 'plugged.host devbox (plugged-host-devbox)';

    public static function token(): string
    {
        if (Plugins::enabled('subdomains')) {
            return (string) config('subdomains.token', '');
        }

        return (string) config('plugged-host-devbox.cloudflare_token', '');
    }

    /**
     * Makes sure the devbox's record exists and points at the edge.
     */
    public function ensure(Devbox $devbox): void
    {
        $ip = (string) config('plugged-host-devbox.web_origin_ip');
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RuntimeException('DEVBOX_WEB_ORIGIN_IP is not an IPv4 address');
        }

        $name = DevboxWeb::host($devbox);
        $cloudflare = $this->client();
        $zone = $this->zone($cloudflare);
        $record = ['type' => 'A', 'name' => $name, 'content' => $ip, 'proxied' => true, 'ttl' => 1, 'comment' => self::COMMENT];

        foreach ((array) $cloudflare->get("/zones/{$zone}/dns_records", ['name' => $name])->throw()->json('result') as $existing) {
            if (($existing['comment'] ?? null) !== self::COMMENT) {
                throw new RuntimeException("{$name} already has a DNS record this plugin didn't make; not touching it");
            }

            if ($existing['type'] === 'A' && $existing['content'] === $ip && $existing['proxied'] === true) {
                return;
            }

            $cloudflare->patch("/zones/{$zone}/dns_records/{$existing['id']}", $record)->throw();

            return;
        }

        $cloudflare->post("/zones/{$zone}/dns_records", $record)->throw();
    }

    /**
     * Removes the devbox's record (only one this plugin made).
     */
    public function remove(Devbox $devbox): void
    {
        $name = DevboxWeb::host($devbox);
        $cloudflare = $this->client();
        $zone = $this->zone($cloudflare);

        foreach ((array) $cloudflare->get("/zones/{$zone}/dns_records", ['name' => $name])->throw()->json('result') as $existing) {
            if (($existing['comment'] ?? null) === self::COMMENT) {
                $cloudflare->delete("/zones/{$zone}/dns_records/{$existing['id']}")->throw();
            }
        }
    }

    private function client(): PendingRequest
    {
        $token = self::token();
        if ($token === '') {
            throw new RuntimeException(Plugins::enabled('subdomains')
                ? 'the subdomains plugin has no Cloudflare token'
                : 'DEVBOX_CLOUDFLARE_TOKEN is not set (the subdomains plugin, whose token would be used, is not installed)');
        }

        return Http::withToken($token)->acceptJson()->timeout(15)->baseUrl('https://api.cloudflare.com/client/v4');
    }

    /**
     * The zone holding the web domain (tries the domain, then its parents), cached for a day.
     */
    private function zone(PendingRequest $cloudflare): string
    {
        $domain = DevboxWeb::domain();

        return Cache::remember('plugged-host-devbox:cf-zone:' . $domain, 86400, function () use ($cloudflare, $domain): string {
            $labels = explode('.', $domain);
            while (count($labels) >= 2) {
                $id = $cloudflare->get('/zones', ['name' => implode('.', $labels)])->throw()->json('result.0.id');
                if (is_string($id) && $id !== '') {
                    return $id;
                }
                array_shift($labels);
            }

            throw new RuntimeException("no Cloudflare zone the token can see holds {$domain}");
        });
    }
}
