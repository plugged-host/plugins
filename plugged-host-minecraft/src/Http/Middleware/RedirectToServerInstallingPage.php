<?php

namespace PluggedHost\Minecraft\Http\Middleware;

use App\Models\Server;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use PluggedHost\Minecraft\Filament\Server\Pages\ServerInstallingPage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a server that is still installing on its waiting page.
 *
 * Registered as *non-persistent* tenant middleware (see PluggedHostCorePlugin::register),
 * which matters: Filament applies tenant middleware to the panel's page routes only, so
 * this runs on full loads and wire:navigate requests but never on POST /livewire/update.
 * A 302 answering a Livewire update would be swallowed as component HTML, which would break
 * the very poll ServerInstallingPage uses to notice that the install has finished.
 *
 * Only an unfinished install redirects. Suspended servers, servers under node maintenance
 * and servers restoring a backup are all in a conflict state too, and each already has its
 * own handling — a banner on the console, not a takeover.
 */
class RedirectToServerInstallingPage
{
    public function handle(Request $request, Closure $next): Response
    {
        $server = $request->route()?->parameter('server');
        $server ??= Filament::getTenant();

        if (!$server instanceof Server || $server->isInstalled()) {
            return $next($request);
        }

        // Route names rather than URLs: the waiting page is reached with and without a
        // query string, and comparing full URLs would loop on the difference. No panel is
        // passed because this only ever runs inside the server panel's route group.
        if ($request->route()?->getName() === ServerInstallingPage::getRouteName()) {
            return $next($request);
        }

        return redirect()->to(ServerInstallingPage::getUrl(tenant: $server));
    }
}
