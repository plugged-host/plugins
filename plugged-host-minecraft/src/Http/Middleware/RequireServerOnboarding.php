<?php

namespace PluggedHost\Minecraft\Http\Middleware;

use App\Models\Server;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use PluggedHost\Minecraft\Filament\Server\Pages\ServerOnboardingPage;
use PluggedHost\Minecraft\Models\ServerOnboarding;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Keeps a newly created server on its setup wizard until the wizard has been answered.
 *
 * This exists because CreateServerPage stopped asking which server software to install. That
 * question now belongs to ServerOnboardingPage, which can ask for the Minecraft version in the same
 * breath and reinstall when either changes — but it also means a server created through the panel is
 * running whatever the catalog happened to list first. Leaving setup optional would hand someone a
 * Purpur server when they came for Fabric, with no indication anything was unfinished.
 *
 * Registered as *non-persistent* tenant middleware next to RedirectToServerInstallingPage (see
 * PluggedHostCorePlugin::register), which is what makes this safe:
 *
 *  - tenant middleware runs after IdentifyTenant, so Filament::getTenant() is populated and
 *    ServerOnboardingPage::canAccess() can be asked directly rather than reimplemented here;
 *  - it is applied to the panel's page routes only, so POST /livewire/update never reaches it. A 302
 *    answering a Livewire update would be swallowed as component HTML, and the wizard is itself a
 *    Livewire component — gating its own updates would break the thing this gate points at.
 *
 * Three separate conditions have to hold before anyone is redirected, and each is guarding against a
 * different way this could go wrong:
 *
 * 1. **ServerOnboarding::requiredFor()**, not pendingFor(). pendingFor() counts a *missing* row as
 *    pending, which is right for "is there still something to offer" and would be catastrophic here:
 *    it would lock every server that predates this feature, and every server an admin created,
 *    behind a wizard its owner never asked for. requiredFor() reads a flag that only
 *    ServerProvisioner sets, on the servers users create themselves.
 * 2. **ServerOnboardingPage::canAccess()**, so nobody is redirected to a page they would then get a
 *    403 or a 404 from. That covers a subuser with no FileUpdate permission, a non-Minecraft egg,
 *    and a server in a conflict state — including one that is still installing, which is the state
 *    every server created through the panel starts in, and which RedirectToServerInstallingPage owns.
 * 3. **The target resolves.** Everything is wrapped in a try/catch that opens the gate on failure.
 *    A gate that 500s is worse than no gate at all: it would put every page of the server panel out
 *    of reach with no way for the owner to get back in, and the worst case of failing open is a
 *    server whose software the owner has to change from the Version tab by hand.
 */
class RequireServerOnboarding
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $target = $this->target($request);
        } catch (Throwable $exception) {
            report($exception);

            return $next($request);
        }

        if ($target === null) {
            return $next($request);
        }

        Notification::make()
            ->warning()
            ->title(trans('plugged-host-minecraft::strings.onboarding.gate_title'))
            ->body(trans('plugged-host-minecraft::strings.onboarding.gate_body'))
            ->send();

        return redirect()->to($target);
    }

    /**
     * Where this request has to go instead, or null to let it through.
     */
    private function target(Request $request): ?string
    {
        // GET and HEAD only. Everything else is a form post or an action, and the wizard's own
        // submit is one of them.
        if (!$request->isMethodSafe() || $request->expectsJson() || $request->ajax()) {
            return null;
        }

        $server = $request->route()?->parameter('server');
        $server ??= Filament::getTenant();

        if (!$server instanceof Server) {
            return null;
        }

        // Route names rather than URLs: the wizard is reached with and without ?step=, and
        // comparing full URLs would redirect it to itself forever.
        if ($request->route()?->getName() === ServerOnboardingPage::getRouteName()) {
            return null;
        }

        if (!ServerOnboarding::requiredFor($server) || !ServerOnboardingPage::canAccess()) {
            return null;
        }

        return ServerOnboardingPage::getUrl(tenant: $server);
    }
}
