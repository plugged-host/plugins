<?php

namespace PluggedHost\Devbox\Http\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use PluggedHost\Devbox\Models\Devbox;
use PluggedHost\Devbox\Support\DevboxAccess;

/**
 * The devbox a request comes from, by the bearer token of its current start, and a per-devbox
 * rate limit. Many devboxes share a node's address, so limits are per devbox, not per IP.
 */
trait ResolvesDevbox
{
    private function devbox(Request $request): ?Devbox
    {
        $token = (string) $request->bearerToken();
        if (strlen($token) < 32 || !DevboxAccess::ready()) {
            return null;
        }

        return Devbox::query()->where('token_hash', Devbox::hashToken($token))->first();
    }

    private function allow(Devbox $devbox, string $action, int $perMinute): bool
    {
        $key = "plugged-host-devbox:agent:{$action}:{$devbox->id}";
        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            return false;
        }
        RateLimiter::hit($key, 60);

        return true;
    }
}
