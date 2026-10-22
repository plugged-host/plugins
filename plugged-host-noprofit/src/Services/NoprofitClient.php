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

    /**
     * A signed POST whose answer is a server-sent event stream (neo's streamed turns): every
     * `data:` line is handed to $onData as it arrives. Plain curl, because the HTTP client reads a
     * body to its end before it returns. The connection may stay silent for $idle seconds at
     * most (the other side pings while it waits). An answer that is not a stream (an error with
     * its own status) comes back whole.
     *
     * @param  array<string, mixed>  $body
     * @param  callable(string): void  $onData
     * @return array{status: int, body: string, error: ?string} error is curl's, when the connection failed.
     *
     * @throws JsonException
     */
    public function stream(string $path, array $body, string $secret, int $timeout, int $idle, callable $onData): array
    {
        $url = $this->baseUrl() . '/' . ltrim($path, '/');
        $raw = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));

        $signature = hash_hmac('sha256', implode("\n", [
            'POST',
            '/' . ltrim((string) parse_url($url, PHP_URL_PATH), '/'),
            $timestamp,
            $nonce,
            hash('sha256', $raw),
        ]), $secret);

        $status = 0;
        $streaming = false;
        $whole = '';
        $buffer = '';

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $raw,
            CURLOPT_HTTPHEADER => [
                'X-NP-Timestamp: ' . $timestamp,
                'X-NP-Nonce: ' . $nonce,
                'X-NP-Signature: ' . $signature,
                'Content-Type: application/json',
                'Accept: text/event-stream, application/json',
                'User-Agent: plugged.host-panel/1.2.1',
            ],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => max(5, $idle),
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$status, &$streaming): int {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                    $status = (int) $match[1];
                    $streaming = false;
                } elseif (stripos($line, 'content-type:') === 0 && str_contains(strtolower($line), 'text/event-stream')) {
                    $streaming = true;
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$streaming, &$whole, &$buffer, $onData): int {
                if (!$streaming) {
                    $whole .= $chunk;

                    return strlen($whole) > 1024 * 1024 ? 0 : strlen($chunk);
                }

                $buffer .= $chunk;

                while (($end = strpos($buffer, "\n")) !== false) {
                    $line = rtrim(substr($buffer, 0, $end), "\r");
                    $buffer = substr($buffer, $end + 1);

                    if (str_starts_with($line, 'data:')) {
                        $onData(ltrim(substr($line, 5)));
                    }
                }

                return strlen($chunk);
            },
        ]);

        $ip = config('plugged-host-noprofit.resolve_ip');
        if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            $host = (string) parse_url($url, PHP_URL_HOST);
            $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
            curl_setopt($curl, CURLOPT_RESOLVE, ["{$host}:{$port}:{$ip}"]);
        }

        $bundle = config('plugged-host-noprofit.ca_bundle');
        if (is_string($bundle) && $bundle !== '' && is_readable($bundle)) {
            curl_setopt($curl, CURLOPT_CAINFO, $bundle);
        }

        curl_exec($curl);
        $error = curl_errno($curl) !== 0 ? curl_error($curl) : null;

        if ($streaming && trim($buffer) !== '' && str_starts_with(trim($buffer), 'data:')) {
            $onData(ltrim(substr(trim($buffer), 5)));
        }

        return ['status' => $status, 'body' => $whole, 'error' => $error];
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
