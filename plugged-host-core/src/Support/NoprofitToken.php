<?php

namespace PluggedHost\Core\Support;

use InvalidArgumentException;

/**
 * The token format shared with noprofit.space (App\Services\SignedToken over there):
 * base64url(json) "." hex(hmac-sha256).
 *
 * Every token names its audience, so one minted for SSO (noprofit.space -> panel) is useless
 * as a migration token (panel -> noprofit.space) and vice versa, and carries iat/exp plus a
 * nonce that the receiving side burns on first use.
 */
final class NoprofitToken
{
    public const AUDIENCE_SSO = 'plugged-host:sso';

    public const AUDIENCE_MIGRATION = 'noprofit:migrate';

    private const MIN_SECRET_LENGTH = 32;

    /**
     * @param  array<string, mixed>  $claims
     */
    public static function encode(array $claims, string $secret, string $audience, int $ttlSeconds): string
    {
        self::assertSecret($secret);

        $claims = array_merge($claims, [
            'aud' => $audience,
            'iat' => time(),
            'exp' => time() + $ttlSeconds,
            'nonce' => bin2hex(random_bytes(16)),
        ]);

        $payload = rtrim(strtr(base64_encode((string) json_encode($claims)), '+/', '-_'), '=');

        return $payload . '.' . hash_hmac('sha256', $payload, $secret);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public static function decode(string $token, string $secret, string $audience): array
    {
        self::assertSecret($secret);

        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException('Malformed token.');
        }

        [$payload, $signature] = $parts;
        if (!hash_equals(hash_hmac('sha256', $payload, $secret), $signature)) {
            throw new InvalidArgumentException('Invalid token signature.');
        }

        $padded = $payload . str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $claims = json_decode((string) base64_decode(strtr($padded, '-_', '+/'), true), true);

        if (!is_array($claims) || ($claims['aud'] ?? null) !== $audience) {
            throw new InvalidArgumentException('Token was issued for a different purpose.');
        }

        if ((int) ($claims['exp'] ?? 0) < time() || (int) ($claims['iat'] ?? 0) > time() + 60) {
            throw new InvalidArgumentException('Token has expired.');
        }

        if (!is_string($claims['nonce'] ?? null) || $claims['nonce'] === '') {
            throw new InvalidArgumentException('Token is missing its nonce.');
        }

        return $claims;
    }

    private static function assertSecret(string $secret): void
    {
        if (strlen($secret) < self::MIN_SECRET_LENGTH) {
            throw new InvalidArgumentException('Token secret is not configured.');
        }
    }
}
