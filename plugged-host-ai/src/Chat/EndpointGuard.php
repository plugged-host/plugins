<?php

namespace PluggedHost\Ai\Chat;

/**
 * Keeps provider endpoints set on this panel pointed at the public internet (no SSRF into
 * loopback, private, link-local, CGNAT or metadata ranges). noprofit.space's EndpointGuard, ported:
 * neo's settings form checks every endpoint it saves and every slot it tests with it.
 */
final class EndpointGuard
{
    /**
     * Validate an endpoint and return the address it resolves to (null when the guard is off).
     *
     * @throws EndpointNotAllowedException
     */
    public function pin(string $endpoint): ?string
    {
        ['host' => $host] = $this->assertAllowedUrl($endpoint);

        if (!config('plugged-host-ai.local.endpoint_guard', true)) {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $this->assertPublic($host);

            return $host;
        }

        $addresses = gethostbynamel($host) ?: [];
        if ($addresses === []) {
            throw new EndpointNotAllowedException('endpoint_unresolvable');
        }

        foreach ($addresses as $address) {
            $this->assertPublic($address);
        }

        return $addresses[0];
    }

    /**
     * @return array{host: string, port: int}
     *
     * @throws EndpointNotAllowedException
     */
    public function assertAllowedUrl(string $endpoint): array
    {
        $parts = parse_url($endpoint);

        if (!is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            throw new EndpointNotAllowedException('endpoint_must_be_https_url');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal')) {
            throw new EndpointNotAllowedException('endpoint_not_public');
        }

        return ['host' => $host, 'port' => (int) ($parts['port'] ?? 443)];
    }

    public static function isPublicIp(string $ip): bool
    {
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            $ip = substr($ip, 7);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);

            foreach (['100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15', '169.254.0.0/16'] as $cidr) {
                [$network, $bits] = explode('/', $cidr);
                $mask = -1 << (32 - (int) $bits);
                if (($long & $mask) === (ip2long($network) & $mask)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @throws EndpointNotAllowedException
     */
    private function assertPublic(string $ip): void
    {
        if (!self::isPublicIp($ip)) {
            throw new EndpointNotAllowedException('endpoint_not_public');
        }
    }
}
