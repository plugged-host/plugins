<?php

namespace PluggedHost\Core\Http\Middleware;

use App\Filament\App\Resources\Servers\ServerResource;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use PluggedHost\Core\Support\PanelTour;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Sends an account that has never seen the welcome tour to the server list until it has read
 * step 1. The tour itself is PanelTourOverlay, drawn over that page (CoreRuntimeServiceProvider
 * mounts it there), so "the page the tour is on" and "the home page" are the same place.
 * Only safe HTML navigations are redirected: JSON, Livewire and file-download requests are
 * left alone, because a redirect in an XHR response body is noise at best.
 *
 * **It is app-panel auth middleware, not `web`-group middleware.** The plan called for
 * `pushMiddlewareToGroup('web', ...)`, copying the gates that existed then. That does not work for
 * a panel page here: Pelican's PanelProvider gives every panel an *explicit* middleware list
 * (EncryptCookies … RedirectIfNotInstalled) and never includes the `web` group, so the group
 * only covers ~20 non-panel routes -- `auth/login`, the two OAuth routes, `docs/api`, the
 * passkey endpoints, `livewire/*`. A gate pushed there would redirect nothing anyone browses.
 * PluggedHostCorePlugin::register() therefore hands this to $panel->authMiddleware() on the
 * app panel only, which buys three things for free:
 *
 *   - it runs after Filament\Http\Middleware\Authenticate, so $request->user() is real;
 *   - the admin and server panels are untouched, without a panel id check here;
 *   - it is *not* registered as persistent middleware, so POST /livewire/update -- which every
 *     Livewire component in the panel shares, including the tour's own buttons -- never sees
 *     it. The isMethodSafe() check below is belt and braces on top of that.
 *
 * No notification is sent with the redirect: the tour opens over the page it lands on, and a
 * toast telling someone to read the thing already filling their screen is noise.
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

        $target = $this->target();

        if ($target === null || $this->isAllowed($request, $target)) {
            return $next($request);
        }

        return redirect()->to($target);
    }

    /**
     * Requests that must survive the gate: the server list the tour is drawn on, and logging
     * out.
     *
     * Matched on the resolved path rather than a route name so it cannot drift from wherever
     * the app panel's home is. Logout is matched by name because each panel registers
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

    /**
     * The server list itself rather than the panel's home URL: the two are the same page today,
     * but the tour is mounted on ListServers, and a gate aimed anywhere else would hold people
     * on a page where the button that opens it never appears.
     */
    private function target(): ?string
    {
        try {
            return ServerResource::getUrl('index', panel: 'app');
        } catch (Throwable) {
            return null;
        }
    }
}
