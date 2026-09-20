<?php

namespace PluggedHost\Core\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use PluggedHost\Core\Models\LegalAcceptance;

class LegalAcceptanceService
{
    private const SESSION_KEY = 'plugged-host-core.legal-acceptance';

    /**
     * Has to outlive a full OAuth round trip, not just a form post.
     *
     * RequireLegalAcceptanceForOAuth re-checks hasPending() on auth.oauth.callback, so
     * this window has to cover everything that happens on Discord's side after the box
     * is ticked: a fresh Discord login, 2FA, an emailed verification code, or a hand-off
     * to the mobile app. At 10 minutes anyone slower than that came back to a bounce to
     * /login and a "legal acceptance required" notice that does not explain that their
     * acceptance timed out -- indistinguishable from "Discord login is broken", and
     * invisible in the logs because the middleware redirects rather than throwing.
     */
    private const TTL_MINUTES = 30;

    public function begin(string $authenticationMethod): void
    {
        session()->put(self::SESSION_KEY, [
            'terms_version' => (string) config('plugged-host-core.legal.terms_version'),
            'privacy_version' => (string) config('plugged-host-core.legal.privacy_version'),
            'authentication_method' => $authenticationMethod,
            'accepted_at' => now()->toIso8601String(),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES)->getTimestamp(),
        ]);
    }

    public function hasPending(?string $authenticationMethod = null): bool
    {
        $pending = session()->get(self::SESSION_KEY);

        if (!is_array($pending) || (int) ($pending['expires_at'] ?? 0) < now()->getTimestamp()) {
            session()->forget(self::SESSION_KEY);

            return false;
        }

        if (($pending['terms_version'] ?? null) !== (string) config('plugged-host-core.legal.terms_version')) {
            return false;
        }

        if (($pending['privacy_version'] ?? null) !== (string) config('plugged-host-core.legal.privacy_version')) {
            return false;
        }

        return $authenticationMethod === null || ($pending['authentication_method'] ?? null) === $authenticationMethod;
    }

    public function record(User $user): void
    {
        if (!$this->hasPending()) {
            return;
        }

        $pending = session()->pull(self::SESSION_KEY);

        LegalAcceptance::query()->create([
            'user_id' => $user->getKey(),
            'terms_version' => $pending['terms_version'],
            'privacy_version' => $pending['privacy_version'],
            'authentication_method' => $pending['authentication_method'],
            'accepted_at' => Carbon::parse($pending['accepted_at']),
        ]);
    }
}
