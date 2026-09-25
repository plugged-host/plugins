<?php

namespace PluggedHost\Core\Http\Controllers;

use App\Models\Server;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PluggedHost\Core\Support\NoprofitLink;
use PluggedHost\Core\Support\NoprofitToken;
use PluggedHost\Core\Support\PanelMfa;
use PluggedHost\Discord\Models\DiscordLink;
use PluggedHost\Economy\Models\UserCredits;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign-in through noprofit.space, and the password-proven "Continue with plugged.host" hand-off.
 *
 * Identity rules (the account-takeover fix):
 *   - SSO finds the panel user by the stored noprofit.space account id, or by the plugged.host
 *     id noprofit.space itself linked through a password-proven migration. Never by email or
 *     username: both can be claimed by anyone who registers on noprofit.space.
 *   - A brand-new panel user is only created when no panel account uses that email; otherwise
 *     the person is asked to connect the existing account with its plugged.host password.
 *   - The panel's own 2FA is enforced after either first factor (SSO token or /migrate password).
 */
final class NoprofitSsoController
{
    /**
     * Session key holding a first-factor-verified sign-in or migration awaiting its 2FA code.
     */
    private const PENDING = 'plugged-host.sso.pending';

    private const PENDING_SECONDS = 600;

    public function login(): RedirectResponse
    {
        if (auth()->guard('web')->check()) {
            return redirect()->to('/');
        }

        return $this->toAccountsSso();
    }

    /**
     * Force the round-trip even when signed in, so an unlinked user can activate the link.
     */
    public function link(): RedirectResponse
    {
        return $this->toAccountsSso();
    }

    public function callback(Request $request): Response
    {
        $token = $request->query('token');
        if (!is_string($token) || $token === '') {
            return redirect()->route('filament.app.auth.login');
        }

        try {
            $claims = NoprofitToken::decode($token, (string) config('plugged-host-core.noprofit.sso_secret'), NoprofitToken::AUDIENCE_SSO);
        } catch (InvalidArgumentException) {
            abort(403, 'This sign-in link is invalid or has expired.');
        }

        $accountId = (int) ($claims['account_user_id'] ?? 0);
        if ($accountId < 1 || !is_string($claims['email'] ?? null) || $claims['email'] === '') {
            abort(403, 'This sign-in link is incomplete.');
        }

        // Single use: a leaked callback URL (logs, history, referrer) cannot be replayed.
        if (!Cache::add('plugged-host:sso-nonce:' . $claims['nonce'], 1, max(60, (int) $claims['exp'] - time() + 60))) {
            abort(403, 'This sign-in link has already been used.');
        }

        $user = $this->resolveSsoUser($claims, $accountId);

        if ($user === null) {
            return response()->view('plugged-host-core::sso-connect', [
                'connectUrl' => $this->accountsUrl() . '/auth/plugged-host?return_to=' . urlencode('/sso?return_to=' . urlencode(route('plugged-host.sso.callback'))),
                'accountsUrl' => $this->accountsUrl(),
            ]);
        }

        return $this->completeOrChallenge($request, $user, 'login');
    }

    public function showChallenge(Request $request): Response
    {
        $user = $this->pendingUser($request);
        if ($user === null) {
            return redirect()->route('filament.app.auth.login');
        }

        return response()->view('plugged-host-core::mfa-challenge', [
            'method' => PanelMfa::method($user),
        ]);
    }

    public function verifyChallenge(Request $request): Response
    {
        $user = $this->pendingUser($request);
        if ($user === null) {
            return redirect()->route('filament.app.auth.login');
        }

        $key = 'plugged-host:sso-mfa:' . $user->getKey();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $request->session()->forget(self::PENDING);

            return redirect()->route('filament.app.auth.login');
        }

        if (!PanelMfa::verify($user, (string) $request->input('code', ''))) {
            RateLimiter::hit($key, self::PENDING_SECONDS);

            return back()->withErrors(['code' => 'That code is not valid.']);
        }

        RateLimiter::clear($key);
        $purpose = (string) $request->session()->pull(self::PENDING . '.purpose', 'login');
        $request->session()->forget(self::PENDING);

        return $this->complete($request, $user, $purpose);
    }

    public function showMigrate(): View
    {
        return view('plugged-host-core::migrate', [
            'cancelUrl' => $this->accountsUrl() . '/login',
        ]);
    }

    public function migrate(Request $request): Response
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        $identifier = trim($validated['identifier']);
        $perAccount = 'plugged-host:migrate:' . sha1(Str::lower($identifier)) . '|' . $request->ip();
        $perIp = 'plugged-host:migrate-ip:' . $request->ip();

        if (RateLimiter::tooManyAttempts($perAccount, 5) || RateLimiter::tooManyAttempts($perIp, 20)) {
            $seconds = max(RateLimiter::availableIn($perAccount), RateLimiter::availableIn($perIp));

            return back()->withInput($request->only('identifier'))->withErrors([
                'identifier' => "Too many attempts. Try again in {$seconds} seconds.",
            ]);
        }

        $user = User::query()->where('email', $identifier)->orWhere('username', $identifier)->first();

        if ($user === null || !Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($perAccount);
            RateLimiter::hit($perIp);

            return back()->withInput($request->only('identifier'))->withErrors([
                'identifier' => 'The provided username/email or password does not match any plugged.host account.',
            ]);
        }

        RateLimiter::clear($perAccount);

        return $this->completeOrChallenge($request, $user, 'migrate');
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function resolveSsoUser(array $claims, int $accountId): ?User
    {
        $user = NoprofitLink::userFor($accountId);
        if ($user !== null) {
            return $user;
        }

        // noprofit.space vouches for this link: it is only ever created by a migration the user
        // proved with their plugged.host password.
        $pelicanUserId = (int) ($claims['pelican_user_id'] ?? 0);
        if ($pelicanUserId > 0 && ($user = User::query()->find($pelicanUserId)) !== null) {
            NoprofitLink::remember($user, $accountId);

            return $user;
        }

        $email = Str::lower(trim((string) $claims['email']));
        if (User::query()->where('email', $email)->exists()) {
            return null;
        }

        $user = User::query()->create([
            'username' => $this->uniqueUsername((string) ($claims['username'] ?? ''), $email),
            'email' => $email,
            'password' => Hash::make(Str::random(40)),
        ]);

        NoprofitLink::remember($user, $accountId);

        return $user;
    }

    private function completeOrChallenge(Request $request, User $user, string $purpose): Response
    {
        if (PanelMfa::method($user) === null) {
            return $this->complete($request, $user, $purpose);
        }

        $request->session()->put(self::PENDING, [
            'user_id' => $user->getKey(),
            'purpose' => $purpose,
            'expires_at' => time() + self::PENDING_SECONDS,
        ]);

        PanelMfa::begin($user);

        return redirect()->route('plugged-host.sso.challenge');
    }

    private function complete(Request $request, User $user, string $purpose): Response
    {
        if ($purpose === 'migrate') {
            return $this->sendMigrationToken($user);
        }

        auth()->guard('web')->login($user, true);
        $request->session()->regenerate();

        return redirect()->to('/');
    }

    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get(self::PENDING);

        if (!is_array($pending) || (int) ($pending['expires_at'] ?? 0) < time()) {
            $request->session()->forget(self::PENDING);

            return null;
        }

        return User::query()->find((int) ($pending['user_id'] ?? 0));
    }

    /**
     * Hand the proven plugged.host identity to noprofit.space. The password hash is not sent:
     * noprofit.space lets the user set their own password right after this sign-in instead.
     */
    private function sendMigrationToken(User $user): RedirectResponse
    {
        $credits = class_exists(UserCredits::class) ? UserCredits::forUser($user) : null;
        $discord = class_exists(DiscordLink::class) ? DiscordLink::query()->where('user_id', $user->id)->first() : null;

        $usage = Server::query()
            ->where('owner_id', $user->id)
            ->toBase()
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(cpu), 0) AS cpu, COALESCE(SUM(memory), 0) AS memory, COALESCE(SUM(disk), 0) AS disk')
            ->first();

        $token = NoprofitToken::encode([
            'pelican_user_id' => (int) $user->id,
            'email' => $user->email,
            'username' => $user->username,
            'credits' => (int) ($credits?->credits ?? 0),
            'extra_cpu' => (int) ($credits?->extra_cpu ?? 0),
            'extra_memory' => (int) ($credits?->extra_memory ?? 0),
            'extra_disk' => (int) ($credits?->extra_disk ?? 0),
            'discord' => $discord ? [
                'discord_id' => (string) $discord->discord_id,
                'username' => $discord->username,
                'avatar' => $discord->avatar,
                'is_boosting' => (bool) ($discord->is_boosting ?? false),
                'boost_count' => (int) ($discord->boost_count ?? 0),
                'boosts_rewarded' => (int) ($discord->boosts_rewarded ?? 0),
                'valid_invites' => (int) ($discord->invite_count ?? 0),
            ] : null,
            'servers_usage' => [
                'count' => (int) ($usage?->count ?? 0),
                'cpu' => (int) ($usage?->cpu ?? 0),
                'memory' => (int) ($usage?->memory ?? 0),
                'disk' => (int) ($usage?->disk ?? 0),
            ],
        ], (string) config('plugged-host-core.noprofit.migration_secret'), NoprofitToken::AUDIENCE_MIGRATION, (int) config('plugged-host-core.noprofit.migration_ttl', 900));

        $callback = (string) config('plugged-host-core.noprofit.migration_callback');
        $separator = str_contains($callback, '?') ? '&' : '?';

        return redirect()->away($callback . $separator . 'token=' . urlencode($token));
    }

    private function toAccountsSso(): RedirectResponse
    {
        return redirect()->away($this->accountsUrl() . '/sso?return_to=' . urlencode(route('plugged-host.sso.callback')));
    }

    private function accountsUrl(): string
    {
        return rtrim((string) config('plugged-host-core.noprofit.accounts_url'), '/');
    }

    private function uniqueUsername(string $preferred, string $email): string
    {
        $base = Str::of($preferred !== '' ? $preferred : Str::before($email, '@'))
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9_.-]+/', '')
            ->limit(40, '')
            ->toString();

        $base = $base !== '' ? $base : 'user';
        $username = $base;

        for ($i = 1; User::query()->where('username', $username)->exists(); $i++) {
            $username = $base . $i;
        }

        return $username;
    }
}
