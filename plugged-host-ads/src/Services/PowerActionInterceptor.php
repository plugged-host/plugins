<?php

namespace PluggedHost\Ads\Services;

use App\Enums\ContainerStatus;
use App\Enums\SubuserPermission;
use App\Filament\App\Resources\Servers\Pages\ListServers;
use App\Filament\Server\Pages\Console;
use App\Livewire\ServerEntry;
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

            $url = $this->interceptFor($user, $server, $action);

            if ($url === null) {
                return;
            }

            $this->redirect($component, $url);

            $returnEarly(null);
        } catch (Throwable) {
            // Fall through: the power action runs as if this class did not exist.
        }
    }

    /**
     * The whole start chain for one power action, independent of how it was requested.
     *
     * Returns the URL to send the user to when limbo, the start queue or the ad gate now owns
     * the action, and null when the caller may dispatch it itself. Public because it is the
     * single definition of the chain's order: the Livewire hook above and plugged-host-ai's
     * power tool both go through it, so neither can start a server the other would have held.
     *
     * A returned URL is a promise that nothing else may dispatch the action — for the gate
     * that is because it has already sent it.
     */
    public function interceptFor(User $user, Server $server, string $action): ?string
    {
        if (!in_array($action, self::GATED_ACTIONS, true)) {
            return null;
        }

        // The server comes from the browser's Livewire payload, and this runs before the
        // component's own permission check. Someone who may not start this server gets no
        // chain at all: the component then refuses the action as it would without the plugin.
        if (!$this->mayRun($user, $server, $action)) {
            return null;
        }

        // Limbo is asked FIRST, ahead of the queue: a hibernated server is not waiting for
        // capacity, it is parked on the limbo node and must be brought back (and paid for)
        // before any start means anything. Livewire runs every before('call') listener
        // regardless of $returnEarly, so this must live in this one chain rather than as a
        // second listener (fact 8).
        $url = $this->askPlugin('PluggedHost\\Limbo\\Services\\LimboService', $user, $server, $action);
        if ($url !== null) {
            return $url;
        }

        // The start queue comes next, and it is asked before the gate's own isEnabled() check
        // on purpose: the queue is about node capacity, not about ads, and it has to keep
        // working on a panel where the ad gate is switched off entirely. It is also the only
        // one of the two that can refuse a start rather than merely delay it.
        $url = $this->askPlugin('PluggedHost\\Queue\\Services\\StartQueueService', $user, $server, $action);
        if ($url !== null) {
            return $url;
        }

        if (!$this->gate->isEnabled()) {
            return null;
        }

        if (!$this->gate->requiresGate($user, $server, requireStopped: !in_array($action, self::ALWAYS_GATED_ACTIONS, true))) {
            return null;
        }

        // Built before anything is sent: once the start has gone out, this method must hand
        // back a URL no matter what, or the caller would send the same start a second time.
        $url = StartGatePage::getUrl(panel: 'server', tenant: $server);

        // The start goes out now, not when the ads finish. The room is where the user pays for
        // the start by watching them, so it is ad time rather than a queue — and the console
        // behind it shows the container already coming up. The accepted trade: closing the tab
        // mid-wait costs the ad view, not the start.
        try {
            if ($this->gate->dispatchPower($user, $server, $action)) {
                $this->gate->markDispatched($user, $server);
                cache()->put("servers.{$server->uuid}.status", ContainerStatus::Starting, now()->addSeconds(15));
            } else {
                // wings said no, or the node went away between the gate check and here.
                // Remember what was asked for so the release replays it — a failed dispatch
                // must not cost the user the start *and* the wait.
                $this->gate->rememberStartIntent($user, $server, $action);
            }

            $this->gate->holdStartedAt($user, $server);
        } catch (Throwable) {
            // Whatever happened after the attempt, the gate owns this start now.
        }

        return $url;
    }

    /**
     * Ask plugged-host-limbo or plugged-host-queue whether this start has to go through them.
     *
     * Both share a one-method contract: `interceptStart()` returns the URL of their page when
     * the start must wait there, and null when it may go straight through. A URL also means
     * that plugin now owns the power action (limbo charges the ransom and transfers back, the
     * queue dispatches on release), so nothing after it may dispatch the action as well.
     *
     * Resolved by class-string behind class_exists() so either can be uninstalled alone, and
     * fail-open: a plugin that cannot answer must not be the reason a start is lost.
     */
    protected function askPlugin(string $service, User $user, Server $server, string $action): ?string
    {
        if (!class_exists($service)) {
            return null;
        }

        try {
            $url = app($service)->interceptStart($user, $server, $action);
        } catch (Throwable) {
            return null;
        }

        return is_string($url) && $url !== '' ? $url : null;
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
            // Case A: Dispatched event via __dispatch('powerAction', ...)
            if ($method === '__dispatch' && ($params[0] ?? null) === 'powerAction') {
                $payload = $params[1] ?? [];

                if (is_array($payload) && isset($payload[0]) && is_array($payload[0])) {
                    $payload = $payload[0];
                }

                if (is_array($payload) && isset($payload[0], $payload[1]) && !isset($payload['server'], $payload['action'])) {
                    $server = $this->resolveServer($payload[0]);
                    $action = is_string($payload[1]) ? $payload[1] : null;

                    if ($server instanceof Server && in_array($action, self::GATED_ACTIONS, true)) {
                        return [$server, $action];
                    }
                }

                if (!is_array($payload)) {
                    return [null, null];
                }

                $action = is_string($payload['action'] ?? null) ? $payload['action'] : null;

                if (!in_array($action, self::GATED_ACTIONS, true)) {
                    return [null, null];
                }

                return [$this->resolveServer($payload['server'] ?? null), $action];
            }

            // Case B: Direct method call powerAction(...)
            if ($method === 'powerAction') {
                if (isset($params[0], $params[1])) {
                    $server = $this->resolveServer($params[0]);
                    $action = is_string($params[1]) ? $params[1] : null;

                    if ($server instanceof Server && in_array($action, self::GATED_ACTIONS, true)) {
                        return [$server, $action];
                    }
                }

                $payload = $params[0] ?? $params;
                if (is_array($payload)) {
                    $action = is_string($payload['action'] ?? null) ? $payload['action'] : null;
                    if (in_array($action, self::GATED_ACTIONS, true)) {
                        return [$this->resolveServer($payload['server'] ?? null), $action];
                    }
                }

                return [null, null];
            }

            // Case C: Filament table or page actions
            if (in_array($method, ['mountAction', 'callMountedAction', 'mountTableAction', 'callMountedTableAction'], true)) {
                $action = is_string($params[0] ?? null) ? $params[0] : null;
                if (in_array($action, self::GATED_ACTIONS, true)) {
                    $server = $this->resolveServer($params[1] ?? null);
                    if ($server instanceof Server) {
                        return [$server, $action];
                    }
                }
            }

            return [null, null];
        }

        if ($component instanceof ServerEntry) {
            $server = $component->server ?? null;

            if ($server instanceof Server) {
                if ($method === 'powerAction' || in_array($method, ['mountAction', 'callMountedAction'], true)) {
                    $action = is_string($params[1] ?? null) ? $params[1] : (is_string($params[0] ?? null) ? $params[0] : null);
                    if (in_array($action, self::GATED_ACTIONS, true)) {
                        return [$server, $action];
                    }
                }

                if ($method === '__dispatch' && ($params[0] ?? null) === 'powerAction') {
                    $payload = $params[1] ?? [];
                    if (is_array($payload) && isset($payload[0]) && is_array($payload[0])) {
                        $payload = $payload[0];
                    }
                    $action = is_string($payload['action'] ?? null) ? $payload['action'] : (is_string($payload[1] ?? null) ? $payload[1] : null);
                    if (in_array($action, self::GATED_ACTIONS, true)) {
                        return [$server, $action];
                    }
                }
            }

            return [null, null];
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
                    ?? Server::query()->where('uuid', $value)->first()
                    ?? Server::query()->where('uuid_short', $value)->first();
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

    private function mayRun(User $user, Server $server, string $action): bool
    {
        $permission = match ($action) {
            'start' => SubuserPermission::ControlStart,
            'restart' => SubuserPermission::ControlRestart,
            default => null,
        };

        try {
            return $permission !== null && (bool) $user->can($permission, $server);
        } catch (Throwable) {
            return false;
        }
    }
}
