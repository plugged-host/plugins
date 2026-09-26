<?php

namespace PluggedHost\Core\Providers;

use App\Enums\ContainerStatus;
use App\Filament\Server\Pages\Console;
use App\Filament\Server\Widgets\ServerCpuChart;
use App\Filament\Server\Widgets\ServerMemoryChart;
use App\Filament\Server\Widgets\ServerNetworkChart;
use App\Models\Server;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ServiceProvider;
use Livewire\Component;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Why the server console goes blank, the power buttons stop meaning anything, or an error
 * modal covers it. The first three compound: one kills the page's only connection to the
 * daemon, the others let the header keep claiming a state it has no way of knowing.
 *
 * 1. **One renderer takes the whole page with it.**
 *    `resources/views/filament/components/server-console.blade.php` loads the WebGL addon
 *    (:93), opens the terminal (:95), and only then creates the websocket (:135). The addon
 *    defers its real work into `terminal.open()` -- `activate()` re-registers itself on the
 *    terminal's `onWillOpen` when there is no element yet -- and that work throws
 *    `WebGL2 not supported` on any browser without a WebGL2 context: hardware acceleration
 *    switched off, a blacklisted driver, a GPU denylist, Safari below 16. The throw comes
 *    back out of `terminal.open()`, so the rest of the `@script` block never runs. There is
 *    no socket, so there is no output, no status frame, and no power action -- every button
 *    dispatches `setServerState`, the browser forwards it over that socket, and there is no
 *    HTTP fallback anywhere. It depends on the browser rather than the account, and it does
 *    not heal on reload, which is exactly "blank for some people".
 *
 * 2. **Nothing reconnects.** The blade assigns `onerror`, `onmessage` and `onopen`, and no
 *    `onclose`; the websocket JWT lasts ten minutes. So a laptop that sleeps, a phone that
 *    changes network, or a wings restart ends the console until somebody reloads the page --
 *    and takes the power buttons with it, silently, because a click on a closed socket
 *    throws nothing the page ever sees.
 *
 * 3. **The state the buttons trust is not measured.** `Console::$status` is initialised to
 *    `ContainerStatus::Offline` and only a `console-status` event from the websocket ever
 *    changes it. A page whose socket never opened therefore offers Start for a server that
 *    is running and a greyed-out Stop for one that could be stopped -- "it says its stopped
 *    but it isnt and then i click start". Worse, `ServerOverview` is on the same page
 *    polling `$server->condition` once a second, so the top of the screen contradicts the
 *    header while both look authoritative.
 *
 * 4. **A stopping server throws a "403 | Forbidden" over the console.** The CPU, memory and
 *    network charts poll every second, and Filament re-checks a widget's `canView()` on every
 *    hydrate (`CanAuthorizeAccess`). Theirs is `!isInConflictState() && !isOffline()`, a
 *    status check rather than a permission, so the first poll after a crash or a stop, before
 *    the page's own `console-status` re-render drops the charts, aborts with a bare 403 and
 *    Livewire shows it as an error modal.
 *
 * The script fixes (1) and (2) in the browser, without a fork: it makes the WebGL addon
 * unable to throw (xterm then keeps the DOM renderer it used before WebGL was an option)
 * and swaps `window.WebSocket` for a reconnecting facade, but only for the daemon's console
 * URL. The listeners below fix (3) by seeding `$status` from the daemon, and (4) by letting
 * that one poll pass without a render.
 */
class ConsoleResiliencePluginProvider extends ServiceProvider
{
    public function register(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::SCRIPTS_BEFORE,
            fn (): Htmlable => new HtmlString(
                (string) view('plugged-host-core::filament.scripts.console-resilience')->render(),
            ),
            Console::class,
        );

        Livewire::listen('mount', function (object $component): void {
            if ($component instanceof Console) {
                $this->seedStatus($component);
            }
        });

        Livewire::listen('hydrate', function (object $component): void {
            if ($component instanceof Console) {
                $this->seedStatus($component);
            }
        });

        Livewire::listen('exception', function (object $component, Throwable $exception, callable $stopPropagation): void {
            if ($this->isStaleChartPoll($component, $exception)) {
                $stopPropagation();
                $component->skipRender();
            }
        });
    }

    /**
     * A console chart whose `canView()` just turned false on its own poll (see 4). Only these
     * three widgets and only their bare 403: the tenant itself is authorised by Filament's
     * tenant middleware on the same request, so nothing is let through that the user could
     * not already see.
     */
    protected function isStaleChartPoll(object $component, Throwable $exception): bool
    {
        return $component instanceof Component
            && ($component instanceof ServerCpuChart || $component instanceof ServerMemoryChart || $component instanceof ServerNetworkChart)
            && $exception instanceof HttpExceptionInterface
            && $exception->getStatusCode() === 403;
    }

    /**
     * Give `$status` the daemon's answer instead of the `Offline` it is born with.
     *
     * On `mount` this is what the first paint offers: Stop for a running server rather than
     * Start. On `hydrate` it is what a *click* is judged against -- the visible/disabled
     * closures on all four power actions are re-evaluated server-side on the request the
     * click arrives in, so a stale property is not merely cosmetic, it decides which action
     * exists. Both hooks fire before Livewire applies updates and calls, so a live socket
     * still wins: `receivedConsoleUpdate()` overwrites this in the same request, and it
     * writes the very cache key that `retrieveStatus()` reads.
     *
     * `Missing` is the one answer that must not be copied. `DaemonServerRepository::getDetails()`
     * gives the daemon one second and returns `missing` for anything else -- and `Missing`
     * reports false from `isStartable()`, `isRestartable()`, `isStoppable()` and
     * `isKillable()` alike, so a single slow probe would leave the header with no power
     * button at all. Keeping whatever the page already believed is the smaller error.
     *
     * The cost is close to nothing in practice: `retrieveStatus()` is a fifteen-second cache,
     * and `ServerOverview` polls it on this same page every second, so the key is warm.
     * Failures are reported rather than thrown -- an unreachable node must still render its
     * console page, which is where the operator goes to find out that it is unreachable.
     */
    protected function seedStatus(Console $console): void
    {
        try {
            $server = Filament::getTenant();

            if (!$server instanceof Server) {
                return;
            }

            $status = $server->retrieveStatus();

            if ($status === ContainerStatus::Missing) {
                return;
            }

            $console->status = $status;
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
