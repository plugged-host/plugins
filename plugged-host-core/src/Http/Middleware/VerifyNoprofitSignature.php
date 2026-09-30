<?php

namespace PluggedHost\Core\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies a request signed by noprofit.space. The secret is the plugged-host-core.noprofit.*
 * key named by the middleware parameter: migration_secret (default) for everything that can
 * sign someone in or delete them, sync_secret for balance and Discord-link sync.
 *
 * The mirror of NoprofitClient: HMAC-SHA256 over the method, path, timestamp, a single-use
 * nonce and a hash of the exact body bytes (noprofit.space's RequestSignature). A request is
 * accepted once, within a minute of being signed; an unset or short secret rejects everything.
 */
final class VerifyNoprofitSignature
{
    private const TOLERANCE_SECONDS = 60;

    public function handle(Request $request, Closure $next, string $secretKey = 'migration_secret'): Response
    {
        $secret = (string) config('plugged-host-core.noprofit.' . $secretKey, '');

        $timestamp = (string) $request->header('X-NP-Timestamp', '');
        $nonce = (string) $request->header('X-NP-Nonce', '');
        $signature = (string) $request->header('X-NP-Signature', '');

        if (strlen($secret) < 32
            || !ctype_digit($timestamp)
            || abs(time() - (int) $timestamp) > self::TOLERANCE_SECONDS
            || preg_match('/^[A-Za-z0-9_-]{16,64}$/', $nonce) !== 1
            || $signature === '') {
            return $this->reject();
        }

        $expected = hash_hmac('sha256', implode("\n", [
            strtoupper($request->getMethod()),
            '/' . ltrim($request->getPathInfo(), '/'),
            $timestamp,
            $nonce,
            hash('sha256', $request->getContent()),
        ]), $secret);

        if (!hash_equals($expected, $signature)) {
            return $this->reject();
        }

        if (!Cache::add('plugged-host:np-nonce:' . $nonce, 1, self::TOLERANCE_SECONDS * 2 + 10)) {
            return $this->reject();
        }

        return $next($request);
    }

    private function reject(): JsonResponse
    {
        return response()->json(['error' => 'unauthorized'], 401);
    }
}
