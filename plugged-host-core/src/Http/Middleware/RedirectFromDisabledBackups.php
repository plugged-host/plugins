<?php

namespace PluggedHost\Core\Http\Middleware;

use App\Filament\Server\Pages\Console;
use App\Models\Server;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use PluggedHost\Core\Support\NodeBackups;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The Backups page of a server on a node with backups turned off (Support\NodeBackups) goes to
 * the console: the sidebar no longer links it, and this covers bookmarks and old links.
 *
 * Server-panel tenant middleware, not persistent: full loads and wire:navigate only. Fails open.
 */
class RedirectFromDisabledBackups
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            if ($request->isMethod('GET') && $request->routeIs('filament.server.resources.backups.*')) {
                $server = Filament::getTenant();

                if ($server instanceof Server && NodeBackups::disabledFor($server)) {
                    return redirect()->to(Console::getUrl(panel: 'server', tenant: $server));
                }
            }
        } catch (Throwable) {
            //
        }

        return $next($request);
    }
}
