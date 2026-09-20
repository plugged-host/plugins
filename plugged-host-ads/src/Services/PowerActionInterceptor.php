<?php

namespace PluggedHost\Ads\Services;

use App\Enums\ContainerStatus;
use App\Filament\App\Resources\Servers\Pages\ListServers;
use App\Filament\Server\Pages\Console;
use App\Models\Server;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use PluggedHost\Ads\Filament\Server\Pages\StartGatePage;
use Throwable;

/**
 * Stops a start before wings hears about it.
 *
 * Wave 1 gated navigation because of a wrong premise — that pressing Start only
 * ever existed in the browser. It does not. Both of the panel's start surfaces
 * round-trip to PHP first:
 *
 *   console header  →  Livewire call `mountAction('start')`, because a Filament
 *                      action with no modal runs its `->action()` closure straight
 *                      away (`InteractsWithActions::mountAction()`); the
 *                      `setServerState` browser event is emitted *by* that closure.
 *   app-panel row   →  the action is `->dispatch('powerAction', …)`, which Livewire
 *                      delivers back to the server as a call to `__dispatch`
 *                      (whitelisted in `HandleComponents::callMethods()`), handled
 *                      by `#[On('powerAction')] ListServers::powerAction()`.
 *
 * So the interception point is Livewire's `call` hook, which runs before the method
 * and can cancel it outright via `$returnEarly()`. Registered with
 * `Livewire\before('call', …)` rather than `Livewire::listen()` on purpose:
 * `SupportEvents` handles `__dispatch` from an ordinary `on('call')` listener and
 * *invokes the listener method itself*, so a listener registered after it would run
 * only once the power signal had already gone out.
 *
 * Two properties matter more than the mechanism:
 *
 * It cannot be bypassed from devtools. Calling `$wire.mountAction('start')` by hand
 * arrives at exactly the same hook.
 *
 * It fails open. Anything unexpected — an unresolvable server, a throw anywhere in
 * here — returns without cancelling, and the start proceeds. A bug in an ad feature
 * must never be the reason someone cannot start their server.
 *
 * And one thing it does beyond deciding: it sends the start. Cancelling the call is
 * what stops the original code path from reaching wings, so if this class did nothing
 * else the container would sit stopped for the whole wait. It dispatches the power
 * action itself and only remembers an intent when that fails, which is what makes the
 * room ad time rather than a queue.
 *
 * It is also the single interception point for *both* waits a start can hit — the
 * capacity queue in plugged-host-queue and this plugin's ad gate, asked in that order.
 * That is not tidiness. Livewire's `EventBus::trigger()` runs every `before('call')`
 * listener whether or not an earlier one called `$returnEarly`, so two interceptors
 * would each act on a start the other had already cancelled: the queue would enqueue
 * it and the gate would send it to wings anyway. One hook, one order.
 */
class PowerActionInterceptor
{
    /**
     * Power actions that bring a container up, and are therefore gated.
     *
     * `restart` counts, and it counts even on a server that is already running: a
     * restart takes the container down and brings it back up, so it is a start with
     * an extra step, and there is no reason the expensive half of it should be free
     * just because the button was pressed while the server happened to be live. Only
     * `stop` and `kill` are never gated — nothing about them costs us anything.
     */
    protected const GATED_ACTIONS = ['start', 'restart'];

    /**
     * Actions gated regardless of what the container is doing right now.
     *
     * `start` is only meaningful on a stopped container, so for it the gate keeps
     * its own "is it already up?" check — pressing Start on a running server is a
     * no-op and must not cost an ad view.
     */
    protected const ALWAYS_GATED_ACTIONS = ['restart'];

    public function __construct(protected StartGateService $gate) {}

    /**
     * @param  array<int, mixed>  $params
     */
    public function handle(mixed $component, string $method, array $params, callable $returnEarly): void
    {
        try {
            [$server, $action] = $this->resolve($component, $method, $params);

            if (!$server instanceof Server || $action === null) {
                return;
            }

            $user = Auth::user();

            if (!$user instanceof User) {
                return;
            }

            // Limbo is asked FIRST, ahead of the queue: a hibernated server is not waiting for
            // capacity, it is parked on the limbo node and must be brought back (and paid for)
            // before any start means anything. Same one-method contract as the queue, resolved
            // by class-string so limbo can be uninstalled alone. Livewire runs every
            // before('call') listener regardless of $returnEarly, so this must live in this one
            // hook rather than as a second listener (fact 8).
            if ($this->limboIntercepted($user, $server, $action, $component, $returnEarly)) {
                return;
            }

            // The start queue comes next, and it is asked before the gate's own
            // isEnabled() check on purpose: the queue is about node capacity, not about
            // ads, and it has to keep working on a panel where the ad gate is switched
            // off entirely. It is also the only one of the two that can refuse a start
            // rather than merely delay it.
            if ($this->queueIntercepted($user, $server, $action, $component, $returnEarly)) {
                return;
            }

            if (!$this->gate->isEnabled()) {
                return;
            }

            if (!$this->gate->requiresGate($user, $server, requireStopped: !in_array($action, self::ALWAYS_GATED_ACTIONS, true))) {
                return;
            }

            // The start goes out now, not when the ads finish. The room is where the
            // user pays for the start by watching them, so it is ad time rather than a
            // queue — and the console behind it shows the container already coming up.
            // The accepted trade: closing the tab mid-wait costs the ad view, not the
            // start.
            if ($this->gate->dispatchPower($user, $server, $action)) {
                $this->gate->markDispatched($user, $server);
                cache()->put("servers.{$server->uuid}.status", ContainerStatus::Starting, now()->addSeconds(15));
            } else {
                // wings said no, or the node went away between the gate check and
                // here. Remember what was asked for so the release replays it —
                // a failed dispatch must not cost the user the start *and* the wait.
                $this->gate->rememberStartIntent($user, $server, $action);
            }

            $this->gate->holdStartedAt($user, $server);

            $url = StartGatePage::getUrl(panel: 'server', tenant: $server);

            $this->redirect($component, $url);

            $returnEarly(null);
        } catch (Throwable) {
            // Fall through: the power action runs as if this class did not exist.
        }
    }

    /**
     * Ask plugged-host-limbo whether this start must first pass through the restore page.
     *
     * Same contract and same reasoning as queueIntercepted(): one method returning a URL to send
     * the user to, or null to fall through. Resolved by class-string behind class_exists() so
     * limbo can be uninstalled alone. A URL means limbo now owns the flow — it charges the token
     * ransom and performs the transfer back — so nothing below may dispatch the power action.
     */
    protected function limboIntercepted(
        User $user,
        Server $server,
        string $action,
        mixed $component,
        callable $returnEarly,
    ): bool {
        $service = 'PluggedHost\\Limbo\\Services\\LimboService';

        if (!class_exists($service)) {
            return false;
        }

        try {
            $url = app($service)->interceptStart($user, $server, $action);
        } catch (Throwable) {
            // Fail open: a limbo lookup that cannot answer must not lose a start.
            return false;
        }

        if (!is_string($url) || $url === '') {
            return false;
        }

        $this->redirect($component, $url);

        $returnEarly(null);

        return true;
    }

    /**
     * Hand the start to plugged-host-queue and report whether it took it.
     *
     * Resolved by string rather than by import because the queue is a separate plugin
     * that can be uninstalled, and because the dependency has to point this way: the
     * queue needs the Livewire `call` hook, and Livewire runs *every* `before('call')`
     * listener regardless of who called `$returnEarly` — so a second interceptor
     * registered by the queue plugin would let this one send the same start it had just
     * cancelled. One hook, asked in order, is the only safe shape.
     *
     * The contract is one method. `interceptStart()` returns the URL of the queue page
     * when the start has to wait, and null when it may go straight through. A URL also
     * means the queue now owns the power action — it dispatches on release, so nothing
     * below here may dispatch it as well.
     */
    protected function queueIntercepted(
        User $user,
        Server $server,
        string $action,
        mixed $component,
        callable $returnEarly,
    ): bool {
        $service = 'PluggedHost\\Queue\\Services\\StartQueueService';

        if (!class_exists($service)) {
            return false;
        }

        try {
            $url = app($service)->interceptStart($user, $server, $action);
        } catch (Throwable) {
            // Fail open, exactly as the gate does: a queue that cannot answer must not
            // be the reason a start is lost.
            return false;
        }

        if (!is_string($url) || $url === '') {
            return false;
        }

        $this->redirect($component, $url);

        $returnEarly(null);

        return true;
    }

    /**
     * navigate: false — a full page load. Both destinations carry third-party ad
     * script tags, and a wire:navigate swap would leave the previous page's scripts
     * in the document.
     */
    protected function redirect(mixed $component, string $url): void
    {
        if (is_object($component) && method_exists($component, 'redirect')) {
            $component->redirect($url, navigate: false);
        }
    }

    /**
     * Work out which server and which action a Livewire call is trying to power.
     *
     * @param  array<int, mixed>  $params
     * @return array{0: ?Server, 1: ?string}
     */
    protected function resolve(mixed $component, string $method, array $params): array
    {
        if ($component instanceof Console) {
            if ($method !== 'mountAction') {
                return [null, null];
            }

            $action = is_string($params[0] ?? null) ? $params[0] : null;

            if (!in_array($action, self::GATED_ACTIONS, true)) {
                return [null, null];
            }

            // The console is a tenant-scoped page; the tenant *is* the server.
            $server = Filament::getTenant();

            return [$server instanceof Server ? $server : null, $action];
        }

        if ($component instanceof ListServers) {
            if ($method !== '__dispatch' || ($params[0] ?? null) !== 'powerAction') {
                return [null, null];
            }

            $payload = $params[1] ?? [];

            if (!is_array($payload)) {
                return [null, null];
            }

            $action = is_string($payload['action'] ?? null) ? $payload['action'] : null;

            if (!in_array($action, self::GATED_ACTIONS, true)) {
                return [null, null];
            }

            return [$this->resolveServer($payload['server'] ?? null), $action];
        }

        return [null, null];
    }

    /**
     * The row menu's event data travels as JSON, so the server arrives as an array
     * of attributes rather than a model. Read by key and load it back, defensively:
     * the shape of Livewire's serialisation is not ours to depend on.
     */
    protected function resolveServer(mixed $value): ?Server
    {
        try {
            if ($value instanceof Server) {
                return $value;
            }

            if (is_int($value) || (is_string($value) && $value !== '')) {
                return Server::query()->find($value)
                    ?? Server::query()->where('uuid', $value)->first();
            }

            if (!is_array($value)) {
                return null;
            }

            foreach (['id', 'uuid', 'uuid_short'] as $key) {
                $candidate = $value[$key] ?? null;

                if ($candidate === null || $candidate === '') {
                    continue;
                }

                $server = $key === 'id'
                    ? Server::query()->find($candidate)
                    : Server::query()->where($key, $candidate)->first();

                if ($server instanceof Server) {
                    return $server;
                }
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }
}
