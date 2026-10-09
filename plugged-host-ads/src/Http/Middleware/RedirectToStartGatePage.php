<?php

namespace PluggedHost\Ads\Http\Middleware;

use App\Models\Server;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PluggedHost\Ads\Filament\Server\Pages\StartGatePage;
use PluggedHost\Ads\Models\StartGateEvent;
use PluggedHost\Ads\Services\StartGateService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Sends a user entering a gated server's panel to the waiting room first.
 *
 * **Off by default since wave 3.** This shipped as the gate's only trigger on a
 * premise that turned out to be false — that the console's Start button never
 * reaches PHP. It does (see core's StartChain), so gating navigation was both
 * unnecessary and wrong in practice: it charged an ad view for opening the file
 * manager of a server that happened to be stopped. core's `StartChain` gates
 * the click itself, and this stays behind `start_credits.gate_on_navigation` for the
 * one thing it still covers that the interceptor cannot — a container started from
 * outside the panel (client API, a schedule) where the panel only ever sees the
 * user arriving afterwards.
 *
 * Registered as *non-persistent* tenant middleware (see PluggedHostAdsPlugin), for
 * the same reason core's installing redirect is: Filament applies tenant middleware
 * to page routes only, so this runs on full loads and wire:navigate requests but
 * never on POST /livewire/update. A 302 answering a Livewire update is swallowed as
 * component HTML, which would break the very poll that releases the user.
 *
 * The entire body is inside a try/catch that falls through to the request. This
 * middleware sits in front of every server-panel page, and the worst outcome it
 * could produce is not "someone skipped an ad" — it is a customer locked out of a
 * server they are paying for because a cache backend blinked. Fail open, always.
 */
class RedirectToStartGatePage
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $gate = app(StartGateService::class);

            if (!$gate->isEnabled()) {
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

            // Route names rather than URLs: the gate is reached with and without a
            // query string, and comparing full URLs would loop on the difference.
            if ($request->route()?->getName() === StartGatePage::getRouteName()) {
                return $next($request);
            }

            // Enforce active wait: if the user started this server, they cannot leave the
            // start gate room until the minimum dwell has finished.
            if ($gate->hasHold($user, $server)) {
                if ($gate->releaseAllowed($user, $server)) {
                    // Dwell satisfied: cleanly release, open grace pass, and proceed
                    $gate->releaseFromHold($user, $server, StartGateEvent::OUTCOME_ADS);

                    return $next($request);
                }

                // Dwell not satisfied yet: bounce straight back to the start gate
                return redirect()->to(StartGatePage::getUrl(tenant: $server));
            }

            // Cheapest check next: with navigation gate off this costs one config read
            if (!$gate->gateOnNavigation()) {
                return $next($request);
            }

            if (!$gate->requiresGate($user, $server)) {
                return $next($request);
            }

            return redirect()->to(StartGatePage::getUrl(tenant: $server));
        } catch (Throwable) {
            return $next($request);
        }
    }
}
