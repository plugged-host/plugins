<?php

namespace PluggedHost\Devbox\Http\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use PluggedHost\Devbox\Models\Devbox;
use PluggedHost\Devbox\Support\DevboxAccess;

/**
 * The devbox a request comes from, by the bearer token of its current start, and a per-devbox
 * rate limit. Many devboxes share a node's address, so limits are per devbox, not per IP.
 *
 * A devbox that is being stopped keeps its token until Wings confirms the stop, but it is no
 * longer answered for: its `ph` gets a 401, closes every session and ends what runs inside.
 */
trait ResolvesDevbox
{
    private function devbox(Request $request): ?Devbox
    {
        $token = (string) $request->bearerToken();
        if (strlen($token) < 32 || !DevboxAccess::ready()) {
            return null;
        }

        return Devbox::query()
            ->where('token_hash', Devbox::hashToken($token))
            ->whereNull('stopping_at')
            ->first();
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
