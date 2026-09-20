<?php

namespace PluggedHost\Core\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use PluggedHost\Core\Filament\App\Pages\WelcomeTourPage;
use PluggedHost\Core\Support\OAuthPasswordGate;
use PluggedHost\Core\Support\PanelTour;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Sends an account that has never seen the welcome tour to WelcomeTourPage until it has read
 * step 1. Shaped after RequirePasswordForOAuthSignup -- read that docblock first, it argues
 * most of the limits below -- with one structural difference that matters:
 *
 * **It is app-panel auth middleware, not `web`-group middleware.** The plan called for
 * `pushMiddlewareToGroup('web', ...)`, copying the two existing gates. That does not work for
 * a panel page here: Pelican's PanelProvider gives every panel an *explicit* middleware list
 * (EncryptCookies … RedirectIfNotInstalled) and never includes the `web` group, so the group
 * only covers ~20 non-panel routes -- `auth/login`, the two OAuth routes, `docs/api`, the
 * passkey endpoints, `livewire/*`. A gate pushed there would redirect nothing anyone browses.
 * PluggedHostCorePlugin::register() therefore hands this to $panel->authMiddleware() on the
 * app panel only, which buys three things for free:
 *
 *   - it runs after Filament\Http\Middleware\Authenticate and RequireTwoFactorAuthentication,
 *     so $request->user() is real and 2FA is settled before the tour is considered;
 *   - the admin and server panels are untouched, without a panel id check here;
 *   - it is *not* registered as persistent middleware, so POST /livewire/update -- which every
 *     Livewire component in the panel shares, including the tour's own buttons -- never sees
 *     it. The isMethodSafe() check below is belt and braces on top of that.
 *
 * No notification is sent with the redirect, unlike the password gate: the page that appears
 * is a five-step explanation of itself, and a toast telling someone to read the thing already
 * filling their screen is noise.
 *
 * Deliberately deferring to the password gate: an account that still owes a password is left
 * alone here. Both gates redirect authenticated safe GETs, so if they ever both fire on the
 * same panel route, whichever runs second would bounce the other's target and the pair would
 * loop forever. Ordering is not something a plugin can rely on, so the tour yields -- the
 * password is asked for first, and the tour appears on the next navigation after it is set.
 *
 * The redirect target is resolved inside a try/catch and the gate opens on failure: if the
 * page is ever un-discovered, the panel must still work. PanelTour::mustAcknowledge() applies
 * the same rule to the state read -- it answers false when the column is missing, because a
 * gate that shuts on an unmigrated database could never record the acknowledgement that opens
 * it again.
 */
class RequirePanelTour
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user instanceof User || !PanelTour::mustAcknowledge($user)) {
            return $next($request);
        }

        if (!$request->isMethodSafe() || $request->expectsJson() || $request->ajax()) {
            return $next($request);
        }

        if (OAuthPasswordGate::isPending($user)) {
            return $next($request);
        }

        $target = $this->target();

        if ($target === null || $this->isAllowed($request, $target)) {
            return $next($request);
        }

        return redirect()->to($target);
    }

    /**
     * Requests that must survive the gate: the tour itself, and logging out.
     *
     * Matched on the resolved path rather than a route name so it cannot drift from whatever
     * slug WelcomeTourPage declares. Logout is matched by name because each panel registers
     * its own; it is POST-only upstream, so it never actually reaches this check -- kept so
     * the allowlist reads completely.
     */
    private function isAllowed(Request $request, string $target): bool
    {
        $targetPath = trim((string) parse_url($target, PHP_URL_PATH), '/');

        if (trim($request->path(), '/') === $targetPath) {
            return true;
        }

        $name = (string) ($request->route()?->getName() ?? '');

        return str_starts_with($name, 'filament.') && str_ends_with($name, '.auth.logout');
    }

    private function target(): ?string
    {
        try {
            return WelcomeTourPage::getUrl(panel: 'app');
        } catch (Throwable) {
            return null;
        }
    }
}
