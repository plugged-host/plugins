<?php

namespace PluggedHost\Core\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PluggedHost\Core\Support\NoprofitLink;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every panel account is a noprofit.space account. A user who is signed in to the panel but not
 * linked (a session or remember-me cookie from before the move to noprofit.space) is signed out
 * once and sent to sign in, which is noprofit.space: "Continue with plugged.host" (their panel
 * password) or Discord links the account, carries the credits over and brings them back to the
 * page they were on. Without this, such a session would keep working unlinked for up to 30 days
 * with no plan (no new servers, nothing in the store) and no credit sync.
 *
 * Only while linking is actually possible: the link column exists and the SSO secret is set.
 * Web panel pages only (authMiddleware); API keys, the daemon and Livewire updates are untouched.
 */
class RequireNoprofitLink
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user instanceof User || !self::enforced() || NoprofitLink::accountId($user) !== null) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->guest(Filament::getLoginUrl() ?? '/login');
    }

    public static function enforced(): bool
    {
        return NoprofitLink::available() && filled(config('plugged-host-core.noprofit.sso_secret'));
    }
}
