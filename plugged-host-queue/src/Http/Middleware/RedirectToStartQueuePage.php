<?php

namespace PluggedHost\Queue\Http\Middleware;

use App\Models\Server;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PluggedHost\Queue\Filament\Server\Pages\StartQueuePage;
use PluggedHost\Queue\Services\StartQueueService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Ensures a server actively waiting in the start queue cannot navigate into the panel.
 *
 * Registered as non-persistent tenant middleware on the server panel (see PluggedHostQueuePlugin).
 * If a server has an active waiting entry in the start queue, any attempt to visit the server's
 * pages (Console, File Manager, etc.) redirects immediately to the queue waiting page.
 *
 * Runs on full loads and wire:navigate requests, but never on POST /livewire/update.
 * Fails open on any exception.
 */
class RedirectToStartQueuePage
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $queue = app(StartQueueService::class);

            if (!$queue->isEnabled()) {
                return $next($request);
            }

            $server = $request->route()?->parameter('server');
            $server ??= Filament::getTenant();

            if (!$server instanceof Server) {
                return $next($request);
            }

            $user = Auth::user();

            if (!$user instanceof User) {
                return $next($request);
            }

            if ($request->routeIs('filament.server.pages.queue') || StartQueuePage::isCurrentRoute()) {
                return $next($request);
            }

            $waiting = $queue->waitingEntry($server);

            if ($waiting !== null) {
                return redirect()->to(StartQueuePage::getUrl(panel: 'server', tenant: $server));
            }

            return $next($request);
        } catch (Throwable) {
            return $next($request);
        }
    }
}
