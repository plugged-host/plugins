<?php

namespace PluggedHost\Noprofit\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;

/**
 * Signed calls to noprofit.space's internal API.
 *
 * Each request is HMAC-signed over the method, path, timestamp, a single-use nonce and a hash
 * of the exact body bytes, matching VerifySignedServiceRequest on the other side. The secret
 * is passed in per call because it belongs to the feature (neo has its own); this class only
 * knows how to reach noprofit.space and how to sign.
 */
final class NoprofitClient
{
    public const MIN_SECRET_LENGTH = 32;

    public function configured(string $secret): bool
    {
        return $this->baseUrl() !== '' && strlen($secret) >= self::MIN_SECRET_LENGTH;
    }

    /**
     * @param  array<string, mixed>|null  $body
     *
     * @throws JsonException
     * @throws \Illuminate\Http\Client\ConnectionException
     */
    public function send(string $method, string $path, ?array $body, string $secret, int $timeout = 10): Response
    {
        $url = $this->baseUrl() . '/' . ltrim($path, '/');
        $raw = $body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));

        $signature = hash_hmac('sha256', implode("\n", [
            strtoupper($method),
            '/' . ltrim((string) parse_url($url, PHP_URL_PATH), '/'),
            $timestamp,
            $nonce,
            hash('sha256', $raw),
        ]), $secret);

        $request = Http::withHeaders([
            'X-NP-Timestamp' => $timestamp,
            'X-NP-Nonce' => $nonce,
            'X-NP-Signature' => $signature,
            'Accept' => 'application/json',
            'User-Agent' => 'plugged.host-panel/1.2.1',
        ])
            ->connectTimeout(3)
            ->timeout($timeout)
            ->withOptions($this->transportOptions($url));

        if ($raw !== '') {
            $request = $request->withBody($raw, 'application/json');
        }

        return $request->send(strtoupper($method), $url);
    }

    public function baseUrl(): string
    {
        return rtrim((string) config('plugged-host-noprofit.accounts_url', ''), '/');
    }

    /**
     * @return array<string, mixed>
     */
    private function transportOptions(string $url): array
    {
        $options = ['allow_redirects' => false];
        $ip = config('plugged-host-noprofit.resolve_ip');

        if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            $host = (string) parse_url($url, PHP_URL_HOST);
            $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
            $options['curl'] = [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]];
        }

        $bundle = config('plugged-host-noprofit.ca_bundle');
        if (is_string($bundle) && $bundle !== '' && is_readable($bundle)) {
            $options['verify'] = $bundle;
        }

        return $options;
    }
}
