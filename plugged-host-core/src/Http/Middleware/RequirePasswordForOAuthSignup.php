<?php

namespace PluggedHost\Core\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use PluggedHost\Core\Filament\App\Pages\SetPasswordPage;
use PluggedHost\Core\Support\OAuthPasswordGate;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Sends an account created by a Discord login to SetPasswordPage until it has a real
 * password. Registered with $panel->authMiddleware() from PluggedHostCorePlugin::register(),
 * on all three panels, immediately before the welcome-tour gate.
 *
 * Not `pushMiddlewareToGroup('web', …)`, which is what it used to be and is the trap worth
 * recording: Filament panels do not use the `web` group. PanelProvider enumerates each panel's
 * middleware individually, so a gate pushed to `web` reaches the OAuth redirect/callback routes
 * and the handful of other non-panel routes -- and no page a signed-in user ever loads. The
 * pending flag was set on every Discord signup and then never read.
 *
 * Three deliberate limits on how far it reaches:
 *
 * 1. **GET and HEAD only.** Livewire drives the set-password form through POST
 *    /livewire/update, and redirecting that would break the very page this gate sends
 *    people to. Since every Livewire component in the panel shares that one endpoint,
 *    letting it through means a determined user could still operate the panel behind
 *    the gate. That is accepted: this asks for a password so the person is not locked
 *    out when Discord goes away, it is not an authorisation boundary. The account is
 *    already legitimately authenticated -- it signed in through the provider.
 * 2. **HTML navigation only.** JSON, Livewire and file-download requests are left
 *    alone; a redirect in an XHR response body is noise at best.
 * 3. **No interaction with the legal gate.** RequireLegalAcceptanceForOAuth only ever
 *    acts on `auth()->guest()` requests to the two OAuth routes, and this one only
 *    ever acts on authenticated requests, so the two cannot deadlock. (The plan assumed
 *    a legal-acceptance *page* that would need allowlisting -- there is none; acceptance
 *    is a checkbox on the login and register forms.)
 *
 * The redirect target is resolved through SetPasswordPage::getUrl() inside a
 * try/catch: if the page is ever un-discovered, or the app panel is not registered on
 * the request being handled, the gate opens rather than 500ing every page on the site.
 * The panel is named explicitly, so a server- or admin-panel request still resolves the
 * app panel's URL rather than looking for a route that does not exist there.
 */
class RequirePasswordForOAuthSignup
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user instanceof User || !OAuthPasswordGate::isPending($user)) {
            return $next($request);
        }

        if (!$request->isMethodSafe() || $request->expectsJson() || $request->ajax()) {
            return $next($request);
        }

        $target = $this->target();

        if ($target === null || $this->isAllowed($request, $target)) {
            return $next($request);
        }

        Notification::make()
            ->warning()
            ->title(trans('plugged-host-core::strings.set_password.gate_title'))
            ->body(trans('plugged-host-core::strings.set_password.gate_body'))
            ->send();

        return redirect()->to($target);
    }

    /**
     * Requests that must survive the gate: the page itself, and logging out.
     *
     * Matched on the resolved path rather than a hardcoded route name so it cannot drift
     * from whatever slug SetPasswordPage declares. Logout is matched on the route name
     * because each panel registers its own.
     */
    private function isAllowed(Request $request, string $target): bool
    {
        $targetPath = trim((string) parse_url($target, PHP_URL_PATH), '/');

        if (trim($request->path(), '/') === $targetPath) {
            return true;
        }

        $name = (string) ($request->route()?->getName() ?? '');

        // filament.{panel}.auth.logout, for any panel. POST-only upstream, so it never
        // actually reaches this check -- kept so the allowlist reads completely.
        return str_starts_with($name, 'filament.') && str_ends_with($name, '.auth.logout');
    }

    private function target(): ?string
    {
        try {
            return SetPasswordPage::getUrl(panel: 'app');
        } catch (Throwable) {
            return null;
        }
    }
}
