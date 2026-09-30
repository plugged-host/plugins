<?php

namespace PluggedHost\Core\Http\Middleware;

use App\Models\Server;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use PluggedHost\Core\Filament\Server\Pages\SuspendedServerPage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Every page of a suspended server lands its owner and subusers on SuspendedServerPage, which
 * says why, instead of pages whose every button fails (upstream refuses a suspended server's
 * client API). Admins keep the normal pages; limbo's parked servers go to limbo's own page.
 *
 * Server-panel tenant middleware, not persistent: full loads and wire:navigate only, never
 * POST /livewire/update. Fails open.
 */
class RedirectToSuspendedPage
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            if ($request->isMethod('GET') && !$request->routeIs(SuspendedServerPage::getRouteName())) {
                $server = Filament::getTenant();
                $user = $request->user();

                if ($server instanceof Server && $user !== null && !$user->can('update server', $server) && SuspendedServerPage::appliesTo($server)) {
                    return redirect()->to(SuspendedServerPage::getUrl(panel: 'server', tenant: $server));
                }
            }
        } catch (Throwable) {
            //
        }

        return $next($request);
    }
}
