<?php

namespace PluggedHost\Devbox\Http;

use App\Enums\NodeJwtScope;
use App\Facades\Activity;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Services\Nodes\NodeJWTService;
use App\Services\Servers\GetUserPermissionsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PluggedHost\Core\Power\StartChain;
use PluggedHost\Devbox\Http\Concerns\ResolvesDevbox;
use PluggedHost\Devbox\Models\Devbox;
use PluggedHost\Devbox\Support\DevboxAccess;
use Throwable;

/**
 * What `ph` (people and tools inside a devbox) may do with the devbox's game server: its status,
 * power, and a Wings websocket token for the console and logs.
 *
 * Rights: in a personal devbox, the person's own permissions on the game server; in a shared one,
 * what the owner allowed for everyone in it (the token is readable by all of them, so it can't be
 * more). start and restart go through core's StartChain like the panel's Start button (queue, ads,
 * limbo), so the websocket token never carries them.
 */
final class GameController
{
    use ResolvesDevbox;

    /** What a websocket token may carry at most: console, logs, stop. Never start/restart. */
    private const SOCKET_PERMISSIONS = ['websocket.connect', 'control.console', 'control.stop'];

    public function status(Request $request): JsonResponse
    {
        [$devbox, $game, $error] = $this->resolve($request, 'status', 30);
        if ($error) {
            return $error;
        }

        if (!$this->may($devbox, $game, 'status')) {
            return response()->json(['error' => 'not allowed'], 403);
        }

        try {
            $details = app(DaemonServerRepository::class)->setServer($game)->getDetails(3);
        } catch (Throwable) {
            $details = [];
        }

        return response()->json([
            'name' => $game->name,
            'id' => $game->uuid_short,
            'state' => $details['state'] ?? ($game->status ?? 'unknown'),
            'memory_bytes' => $details['utilization']['memory_bytes'] ?? null,
            'memory_limit_mib' => $game->memory,
            'cpu_percent' => $details['utilization']['cpu_absolute'] ?? null,
            'disk_bytes' => $details['utilization']['disk_bytes'] ?? null,
            'disk_limit_mib' => $game->disk,
        ]);
    }

    public function power(Request $request): JsonResponse
    {
        [$devbox, $game, $error] = $this->resolve($request, 'power', 10);
        if ($error) {
            return $error;
        }

        $action = (string) $request->input('action', '');
        if (!in_array($action, ['start', 'stop', 'restart', 'kill'], true)) {
            return response()->json(['error' => 'action must be start, stop, restart or kill'], 422);
        }

        if (!$this->may($devbox, $game, $action)) {
            return response()->json(['error' => "not allowed to {$action} this server"], 403);
        }

        $actor = $this->actor($devbox, $game);

        if (in_array($action, StartChain::GATED_ACTIONS, true) && $actor !== null) {
            try {
                $url = app(StartChain::class)->interceptFor($actor, $game, $action);
            } catch (Throwable $exception) {
                report($exception); // fail open, like the panel's Start button
                $url = null;
            }

            if (is_string($url) && $url !== '') {
                return response()->json([
                    'ok' => false,
                    'continue_at' => $url,
                    'message' => "The {$action} is waiting in the panel (start queue, waiting room or restore). Continue it there.",
                ]);
            }
        }

        app(DaemonServerRepository::class)->setServer($game)->power($action);
        $this->activity($devbox, $game, $actor, 'server:devbox.power', ['action' => $action]);

        return response()->json(['ok' => true, 'message' => "Sent {$action}."]);
    }

    public function websocket(Request $request): JsonResponse
    {
        [$devbox, $game, $error] = $this->resolve($request, 'websocket', 20);
        if ($error) {
            return $error;
        }

        $actor = $this->actor($devbox, $game);
        $permissions = $this->socketPermissions($devbox, $game, $actor);
        if ($actor === null || !in_array('websocket.connect', $permissions, true)) {
            return response()->json(['error' => 'not allowed to read this server\'s console'], 403);
        }

        $node = $game->node;
        $token = app(NodeJWTService::class)
            ->setExpiresAt(CarbonImmutable::now()->addMinutes(10))
            ->setScopes(NodeJwtScope::Websocket)
            ->setUser($actor)
            ->setClaims([
                'server_uuid' => $game->uuid,
                'permissions' => $permissions,
            ])
            ->handle($node, $actor->id . $game->uuid);

        $socket = str_replace(['https://', 'http://'], ['wss://', 'ws://'], $node->getConnectionAddress());

        return response()->json([
            'token' => $token->toString(),
            'socket' => $socket . sprintf('/api/servers/%s/ws', $game->uuid),
            'origin' => rtrim((string) config('app.url'), '/'),
            'permissions' => $permissions,
        ]);
    }

    /**
     * @return array{0: ?Devbox, 1: ?Server, 2: ?JsonResponse}
     */
    private function resolve(Request $request, string $action, int $perMinute): array
    {
        $devbox = $this->devbox($request);
        if ($devbox === null) {
            return [null, null, response()->json(['error' => 'unauthorized'], 401)];
        }
        if (!$this->allow($devbox, "game-{$action}", $perMinute)) {
            return [null, null, response()->json(['error' => 'too many requests'], 429)];
        }

        $game = $devbox->game;
        if ($game === null || DevboxAccess::people($devbox)->isEmpty()) {
            return [null, null, response()->json(['error' => 'not allowed'], 403)];
        }

        return [$devbox, $game, null];
    }

    /**
     * Who acts on the game server: the person of a personal devbox, the owner for a shared one.
     */
    private function actor(Devbox $devbox, Server $game): ?User
    {
        return $devbox->isShared() ? $game->user : $devbox->user;
    }

    private function may(Devbox $devbox, Server $game, string $action): bool
    {
        if ($devbox->isShared()) {
            $rights = (array) ($devbox->shared_rights ?? []);

            return match ($action) {
                'status' => (bool) ($rights['status'] ?? true),
                default => (bool) ($rights['power'] ?? false),
            };
        }

        $user = $devbox->user;
        if ($user === null) {
            return false;
        }

        if ($action === 'status') {
            return true;
        }

        $needed = match ($action) {
            'start' => 'control.start',
            'restart' => 'control.restart',
            default => 'control.stop',
        };

        return $this->holds($game, $user, $needed);
    }

    /**
     * @return list<string>
     */
    private function socketPermissions(Devbox $devbox, Server $game, ?User $actor): array
    {
        if ($devbox->isShared()) {
            $rights = (array) ($devbox->shared_rights ?? []);

            return array_values(array_filter([
                ($rights['logs'] ?? true) || ($rights['console'] ?? false) ? 'websocket.connect' : null,
                ($rights['console'] ?? false) ? 'control.console' : null,
                ($rights['power'] ?? false) ? 'control.stop' : null,
            ]));
        }

        if ($actor === null) {
            return [];
        }

        return array_values(array_filter(self::SOCKET_PERMISSIONS, fn (string $permission) => $this->holds($game, $actor, $permission)));
    }

    /**
     * The person's own permission on the game server: owner has all, a subuser what their row
     * says. Not User::can(), which an admin's override would satisfy.
     */
    private function holds(Server $game, User $user, string $permission): bool
    {
        if ((int) $game->owner_id === (int) $user->id) {
            return true;
        }

        $permissions = app(GetUserPermissionsService::class)->handle($game, $user);

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function activity(Devbox $devbox, Server $game, ?User $actor, string $event, array $properties): void
    {
        try {
            $log = Activity::event($event)->subject($game)->property('shared', $devbox->isShared());
            foreach ($properties as $key => $value) {
                $log->property($key, $value);
            }
            if ($actor) {
                $log->actor($actor);
            }
            $log->log();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
