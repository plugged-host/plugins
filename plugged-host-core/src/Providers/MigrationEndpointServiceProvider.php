<?php

namespace PluggedHost\Core\Providers;

use App\Models\User;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Economy\Models\UserCredits;
use PluggedHost\Discord\Models\DiscordLink;

class MigrationEndpointServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web'])->group(function (): void {
            Route::get('/login', function (Request $request) {
                if (auth()->guard('web')->check()) {
                    return redirect()->to('/');
                }

                $accountsUrl = env('NOPROFIT_ACCOUNTS_URL', 'https://accounts.noprofit.space');
                $callbackUrl = route('plugged-host.sso.callback');

                return redirect()->away($accountsUrl . '/sso?return_to=' . urlencode($callbackUrl));
            })->name('filament.app.auth.login');

            Route::get('/register', function (Request $request) {
                if (auth()->guard('web')->check()) {
                    return redirect()->to('/');
                }

                $accountsUrl = env('NOPROFIT_ACCOUNTS_URL', 'https://accounts.noprofit.space');
                $callbackUrl = route('plugged-host.sso.callback');

                return redirect()->away($accountsUrl . '/sso?return_to=' . urlencode($callbackUrl));
            })->name('filament.app.auth.register');

            Route::get('/sso/callback', function (Request $request) {
                $token = $request->query('token');
                if (!$token || !str_contains($token, '.')) {
                    return redirect()->route('filament.app.auth.login');
                }

                [$encodedPayload, $signature] = explode('.', $token, 2);
                $secret = env('NOPROFIT_MIGRATION_SECRET', 'np_mig_sec_98f48b11c210452d96c9e053a47bf310');
                $expectedSig = hash_hmac('sha256', $encodedPayload, $secret);

                if (!hash_equals($expectedSig, $signature)) {
                    abort(403, 'Invalid SSO signature.');
                }

                $json = base64_decode(strtr($encodedPayload, '-_', '+/'));
                $payload = json_decode($json, true);

                if (!$payload || !isset($payload['email'], $payload['exp']) || time() > $payload['exp']) {
                    abort(403, 'SSO token expired or invalid.');
                }

                $email = strtolower(trim($payload['email']));
                $user = User::where('email', $email)->first();

                if (!$user && !empty($payload['username'])) {
                    $user = User::where('username', strtolower(trim($payload['username'])))->first();
                }

                if (!$user) {
                    $baseUsername = !empty($payload['username']) ? strtolower(trim($payload['username'])) : explode('@', $email)[0];
                    $username = $baseUsername;
                    $counter = 1;
                    while (User::where('username', $username)->exists()) {
                        $username = $baseUsername . $counter;
                        $counter++;
                    }

                    $user = User::create([
                        'username' => $username,
                        'email' => $email,
                        'password' => Hash::make(\Illuminate\Support\Str::random(32)),
                    ]);
                }

                auth()->guard('web')->login($user, true);
                $request->session()->regenerate();

                return redirect()->to('/');
            })->name('plugged-host.sso.callback');

            Route::get('/migrate', function (Request $request) {
                $returnTo = $request->query('return_to', 'https://accounts.noprofit.space/auth/plugged-host/callback');
                return view('plugged-host-core::migrate', [
                    'returnTo' => $returnTo,
                ]);
            })->name('plugged-host.migrate');

            Route::post('/migrate', function (Request $request) {
                $validated = $request->validate([
                    'identifier' => ['required', 'string'],
                    'password' => ['required', 'string'],
                    'return_to' => ['required', 'url'],
                ]);

                $identifier = trim($validated['identifier']);
                $password = $validated['password'];
                $returnTo = $validated['return_to'];

                $user = User::where('email', $identifier)
                    ->orWhere('username', $identifier)
                    ->first();

                if (!$user || !Hash::check($password, $user->password)) {
                    return back()->withInput()->withErrors([
                        'identifier' => 'The provided username/email or password does not match any plugged.host account.',
                    ]);
                }

                // Gather user balances
                $credits = class_exists(UserCredits::class) ? UserCredits::forUser($user) : null;
                $discord = class_exists(DiscordLink::class) ? DiscordLink::where('user_id', $user->id)->first() : null;

                // Gather server usage
                $usage = Server::query()
                    ->where('owner_id', $user->id)
                    ->toBase()
                    ->selectRaw('COUNT(*) AS count, COALESCE(SUM(cpu), 0) AS cpu, COALESCE(SUM(memory), 0) AS memory, COALESCE(SUM(disk), 0) AS disk')
                    ->first();

                $payload = [
                    'pelican_user_id' => (int) $user->id,
                    'email' => $user->email,
                    'username' => $user->username,
                    'password_hash' => $user->password,
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
                    'nonce' => bin2hex(random_bytes(16)),
                    'exp' => time() + 900, // 15 minutes
                ];

                $secret = env('NOPROFIT_MIGRATION_SECRET', 'np_mig_sec_98f48b11c210452d96c9e053a47bf310');
                $encodedPayload = strtr(base64_encode(json_encode($payload)), '+/', '-_');
                $sig = hash_hmac('sha256', $encodedPayload, $secret);
                $token = "{$encodedPayload}.{$sig}";

                $separator = str_contains($returnTo, '?') ? '&' : '?';
                return redirect()->away($returnTo . $separator . 'token=' . urlencode($token));
            })->name('plugged-host.migrate.submit');
        });
    }
}
