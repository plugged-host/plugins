<?php

namespace PluggedHost\Limbo\Http\Middleware;

use App\Filament\Server\Pages\Console;
use App\Models\Server;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use PluggedHost\Limbo\Filament\Server\Pages\RestoreFromLimboPage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Opening a parked server lands its owner on the restore page instead of a Console that only
 * says "suspended" with every power button disabled (upstream disables Start for any server in
 * a conflict state, so the start interceptor never sees a click).
 *
 * Only the Console, the page every server link opens: the other pages (backups, settings)
 * stay reachable, and subusers and admins, who cannot restore, keep the plain Console.
 * Registered as tenant middleware on the server panel (PluggedHostLimboPlugin). Runs on full
 * loads and wire:navigate requests, never on POST /livewire/update. Fails open.
 */
class RedirectToRestorePage
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            if ($request->routeIs(Console::getRouteName()) && RestoreFromLimboPage::canAccess()) {
                $server = Filament::getTenant();

                if ($server instanceof Server) {
                    return redirect()->to(RestoreFromLimboPage::getUrl(panel: 'server', tenant: $server));
                }
            }
        } catch (Throwable) {
            //
        }

        return $next($request);
    }
}
