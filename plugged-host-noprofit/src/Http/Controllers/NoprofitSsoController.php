<?php

namespace PluggedHost\Noprofit\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PluggedHost\Economy\Services\NoprofitCreditSync;
use PluggedHost\Noprofit\Services\NoprofitAccountSync;
use PluggedHost\Noprofit\Support\NoprofitLink;
use PluggedHost\Noprofit\Support\NoprofitMigrationToken;
use PluggedHost\Noprofit\Support\NoprofitToken;
use PluggedHost\Noprofit\Support\PanelMfa;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Sign-in through noprofit.space, and the password-proven "Continue with plugged.host" hand-off.
 *
 * Identity rules (the account-takeover fix):
 *   - SSO finds the panel user by the stored noprofit.space account id, or by the plugged.host
 *     id noprofit.space itself linked through a password-proven migration. Never by email or
 *     username: both can be claimed by anyone who registers on noprofit.space.
 *   - A brand-new panel user is only created when no panel account uses that email; otherwise
 *     the person is asked to connect the existing account with its plugged.host password.
 *   - Second factors (passkeys, authenticator codes) are checked by noprofit.space before it
 *     issues an SSO token. /migrate proves an account by its plugged.host password instead, so
 *     an account that turned on panel 2FA must also pass that second factor (PanelMfa).
 *   - Both hand-offs carry a random state that was stored in the session of the browser that
 *     started them, so a token captured from someone else's flow cannot be replayed into a
 *     victim's browser (login CSRF / account linking CSRF).
 *   - A converted account (NoprofitLink::isConverted) has no panel password and /migrate
 *     refuses it: an account can be moved to noprofit.space once.
 */
final class NoprofitSsoController
{
    /**
     * Session key holding the state of the SSO round-trip this browser started.
     */
    private const SSO_STATE = 'plugged-host.sso.state';

    private const STATE_PATTERN = '/^[A-Za-z0-9]{32,128}$/';

    /**
     * Session key holding a password-proven /migrate that still needs the account's panel 2FA code.
     */
    private const PENDING = 'plugged-host.migrate.pending';

    private const PENDING_SECONDS = 600;

    public function login(): RedirectResponse
    {
        if (auth()->guard('web')->check()) {
            return redirect()->intended('/');
        }

        return $this->toAccountsSso();
    }

    /**
     * Upstream endpoints this panel no longer offers (see MigrationEndpointServiceProvider).
     */
    public function closed(): never
    {
        abort(404);
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
            $claims = NoprofitToken::decode($token, (string) config('plugged-host-noprofit.sso_secret'), NoprofitToken::AUDIENCE_SSO);
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

        // Only the browser that started this sign-in may finish it.
        $state = $request->session()->pull(self::SSO_STATE);
        if (!is_string($state) || !is_string($claims['state'] ?? null) || !hash_equals($state, $claims['state'])) {
            abort(403, 'This sign-in link was started in a different browser or has expired. Please sign in again.');
        }

        $user = $this->resolveSsoUser($claims, $accountId);

        if ($user === null) {
            return response()->view('plugged-host-noprofit::sso-connect', [
                'connectUrl' => $this->accountsUrl() . '/auth/plugged-host?return_to=' . urlencode('/sso?return_to=' . urlencode(route('plugged-host.sso.callback'))),
                'accountsUrl' => $this->accountsUrl(),
            ]);
        }

        return $this->signIn($request, $user);
    }

    public function showMigrate(Request $request): Response
    {
        $state = $request->query('state');
        if (!is_string($state) || preg_match(self::STATE_PATTERN, $state) !== 1) {
            // Every hand-off starts on noprofit.space, which is what issues the state.
            return redirect()->away($this->accountsUrl() . '/auth/plugged-host');
        }

        return response()->view('plugged-host-noprofit::migrate', [
            'cancelUrl' => $this->accountsUrl() . '/login',
            'accountsUrl' => $this->accountsUrl(),
            'state' => $state,
        ]);
    }

    public function migrate(Request $request): Response
    {
        $state = $request->input('state');
        if (!is_string($state) || preg_match(self::STATE_PATTERN, $state) !== 1) {
            return redirect()->away($this->accountsUrl() . '/auth/plugged-host');
        }

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

        // A converted account has no panel password left, and gets the same answer as a wrong
        // one so the form does not reveal which accounts have been converted.
        if ($user === null || NoprofitLink::isConverted($user) || !Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($perAccount);
            RateLimiter::hit($perIp);

            return back()->withInput($request->only('identifier'))->withErrors([
                'identifier' => 'The provided username/email or password does not match any plugged.host account.',
            ]);
        }

        RateLimiter::clear($perAccount);

        // An account that turned on panel 2FA must pass it here: the password alone would
        // otherwise convert it into the noprofit.space account that started this hand-off.
        if (PanelMfa::method($user) !== null) {
            $request->session()->put(self::PENDING, [
                'user_id' => $user->getKey(),
                'state' => $state,
                'expires_at' => time() + self::PENDING_SECONDS,
            ]);

            PanelMfa::begin($user);

            return redirect()->route('plugged-host.sso.challenge');
        }

        return redirect()->away(NoprofitMigrationToken::callbackUrl(NoprofitMigrationToken::issue($user, $state)));
    }

    public function showChallenge(Request $request): Response
    {
        $user = $this->pendingUser($request);
        if ($user === null) {
            return redirect()->away($this->accountsUrl() . '/auth/plugged-host');
        }

        return response()->view('plugged-host-noprofit::mfa-challenge', [
            'method' => PanelMfa::method($user),
        ]);
    }

    public function verifyChallenge(Request $request): Response
    {
        $user = $this->pendingUser($request);
        if ($user === null) {
            return redirect()->away($this->accountsUrl() . '/auth/plugged-host');
        }

        $key = 'plugged-host:migrate-mfa:' . $user->getKey();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $request->session()->forget(self::PENDING);

            return redirect()->away($this->accountsUrl() . '/auth/plugged-host');
        }

        if (!PanelMfa::verify($user, (string) $request->input('code', ''))) {
            RateLimiter::hit($key, self::PENDING_SECONDS);

            return back()->withErrors(['code' => 'That code is not valid.']);
        }

        RateLimiter::clear($key);
        $state = $request->session()->pull(self::PENDING . '.state');
        $request->session()->forget(self::PENDING);

        // Re-checked: the code can come in after a conversion finished elsewhere.
        if (!is_string($state) || NoprofitLink::isConverted($user)) {
            return redirect()->away($this->accountsUrl() . '/login');
        }

        return redirect()->away(NoprofitMigrationToken::callbackUrl(NoprofitMigrationToken::issue($user, $state)));
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

        // Born on noprofit.space: it never gets a panel password or editable sign-in details.
        $user = User::query()->create([
            'username' => $this->uniqueUsername((string) ($claims['username'] ?? ''), $email),
            'email' => $email,
            'password' => '',
            'is_managed_externally' => true,
        ]);

        // noprofit.space learns right away which panel user the account now has (it had none,
        // or one that no longer exists).
        NoprofitLink::remember($user, $accountId);
        NoprofitAccountSync::reportLink($accountId, (int) $user->getKey());

        return $user;
    }

    /**
     * Back to the page that sent the visitor to sign in (Laravel's url.intended, stored in
     * this session when the auth middleware redirected them), or the dashboard.
     */
    private function signIn(Request $request, User $user): RedirectResponse
    {
        auth()->guard('web')->login($user, true);
        $request->session()->regenerate();

        $this->pullPlanIfMissing($user);

        return redirect()->intended('/');
    }

    /**
     * CPU, memory and disk are the account's plan on noprofit.space, with no panel fallback
     * (AccountAllowance::forUser), so an account that never synced -- a first sign-in --
     * fetches it now rather than waiting for noprofit:credits-sync.
     */
    private function pullPlanIfMissing(User $user): void
    {
        $accountId = NoprofitLink::accountId($user);

        if ($accountId === null
            || !class_exists(NoprofitCreditSync::class)
            || !NoprofitCreditSync::enabled()
            || NoprofitCreditSync::plan($user) !== null) {
            return;
        }

        try {
            NoprofitCreditSync::pullAccounts([$accountId]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function toAccountsSso(): RedirectResponse
    {
        $state = Str::random(40);
        session()->put(self::SSO_STATE, $state);

        return redirect()->away($this->accountsUrl() . '/sso?' . http_build_query([
            'return_to' => route('plugged-host.sso.callback'),
            'state' => $state,
        ]));
    }

    private function accountsUrl(): string
    {
        return rtrim((string) config('plugged-host-noprofit.accounts_url'), '/');
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
