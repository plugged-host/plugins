<?php

namespace PluggedHost\Core\Power;

use App\Enums\SubuserPermission;
use App\Filament\App\Resources\Servers\Pages\ListServers;
use App\Filament\Server\Pages\Console;
use App\Livewire\ServerEntry;
use App\Models\Server;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use PluggedHost\Core\Support\Plugins;
use Throwable;

/**
 * Stops a start before wings hears about it, and asks every plugin that may hold a start
 * whether it has to wait.
 *
 * Both of the panel's start surfaces round-trip to PHP first:
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
 * So the interception point is Livewire's `call` hook, which runs before the method and can
 * cancel it outright via `$returnEarly()`. Registered with `Livewire\before('call', …)` rather
 * than `Livewire::listen()` on purpose: `SupportEvents` handles `__dispatch` from an ordinary
 * `on('call')` listener and *invokes the listener method itself*, so a listener registered
 * after it would run only once the power signal had already gone out. It cannot be bypassed
 * from devtools: calling `$wire.mountAction('start')` by hand arrives at the same hook.
 *
 * There is exactly one hook, here, and the gates (StartGate) are asked in order. That is not
 * tidiness: Livewire's `EventBus::trigger()` runs every `before('call')` listener whether or
 * not an earlier one called `$returnEarly`, so two interceptors would each act on a start the
 * other had already taken. Today's gates, registered by their own plugins:
 *
 *   10  plugged-host-limbo   a hibernated server is restored (and paid for) before anything
 *   20  plugged-host-queue   the node is over capacity: wait in the start queue
 *   30  plugged-host-ads     the ad-funded waiting room in front of oversized starts
 *
 * The first gate that returns a URL owns the start; nothing after it may dispatch it. Lived in
 * plugged-host-ads as PowerActionInterceptor until 2.2, which meant switching ads off also
 * switched off the start queue and limbo's restore.
 *
 * It fails open. An unresolvable server, a gate that throws, a disabled plugin: the start
 * proceeds. A bug in a waiting room must never be the reason someone cannot start a server.
 */
final class StartChain
{
    /**
     * Power actions that bring a container up, and are therefore gated. `restart` counts even
     * on a running server: it takes the container down and brings it back up. `stop` and
     * `kill` are never gated.
     */
    public const GATED_ACTIONS = ['start', 'restart'];

    /** @var array<int, list<array{plugin: string, gate: class-string<StartGate>}>> */
    private array $gates = [];

    /**
     * @param  class-string<StartGate>  $gate  resolved from the container when asked
     */
    public function register(string $plugin, int $order, string $gate): void
    {
        $this->gates[$order][] = ['plugin' => $plugin, 'gate' => $gate];
    }

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
            // Fall through: the power action runs as if there were no chain.
        }
    }

    /**
     * The whole start chain for one power action, independent of how it was requested.
     *
     * Returns the URL to send the user to when a gate now owns the action, and null when the
     * caller may dispatch it itself. The Livewire hook above and plugged-host-ai's power tool
     * both go through here, so neither can start a server the other would have held.
     */
    public function interceptFor(User $user, Server $server, string $action): ?string
    {
        if (!in_array($action, self::GATED_ACTIONS, true)) {
            return null;
        }

        // The server comes from the browser's Livewire payload, and this runs before the
        // component's own permission check. Someone who may not start this server gets no
        // chain at all: the component then refuses the action as it would without it.
        if (!$this->mayRun($user, $server, $action)) {
            return null;
        }

        ksort($this->gates);

        foreach ($this->gates as $gates) {
            foreach ($gates as $entry) {
                if (!Plugins::enabled($entry['plugin'])) {
                    continue;
                }

                try {
                    $url = app($entry['gate'])->interceptStart($user, $server, $action);
                } catch (Throwable $e) {
                    report($e);

                    continue;
                }

                if (is_string($url) && $url !== '') {
                    return $url;
                }
            }
        }

        return null;
    }

    /**
     * navigate: false — a full page load. Both destinations carry third-party ad
     * script tags, and a wire:navigate swap would leave the previous page's scripts
     * in the document.
     */
    private function redirect(mixed $component, string $url): void
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
    private function resolve(mixed $component, string $method, array $params): array
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
    private function resolveServer(mixed $value): ?Server
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
