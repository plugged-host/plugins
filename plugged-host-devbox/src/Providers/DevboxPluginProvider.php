<?php

namespace PluggedHost\Devbox\Providers;

use App\Enums\TablerIcon;
use App\Models\Server;
use App\Models\ServerTransfer;
use App\Models\Subuser;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use PluggedHost\Core\Doctor\Doctor;
use PluggedHost\Core\Support\SystemServers;
use PluggedHost\Devbox\Console\Commands\DevboxReconcileCommand;
use PluggedHost\Devbox\Http\AgentController;
use PluggedHost\Devbox\Http\GameController;
use PluggedHost\Devbox\Livewire\OpenInVsCode;
use PluggedHost\Devbox\Models\Devbox;
use PluggedHost\Devbox\Services\DevboxPower;
use PluggedHost\Devbox\Services\DevboxProvisioner;
use PluggedHost\Devbox\Support\DevboxAccess;
use Throwable;

/**
 * Devboxes: the subuser permission, the reconcile schedule, the endpoints `ph serve` calls, the
 * mount core's guard lets through for each devbox, and cleanup when what a devbox belongs to goes.
 */
class DevboxPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        // "VS Code" in the subuser editor. Registered unconditionally and never hidden: a group
        // with no form field is silently stripped from the subuser when the owner saves them.
        Subuser::registerCustomPermissions('devbox', ['connect'], 'plugged-host-devbox::strings.permissions', TablerIcon::BrandVscode);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(DevboxReconcileCommand::class)
                ->everyMinute()
                ->withoutOverlapping(10)
                ->runInBackground()
                ->name('plugged-host-devbox:reconcile');
        });
    }

    public function boot(): void
    {
        Livewire::component('plugged-host-devbox.open-in-vscode', OpenInVsCode::class);

        // The one volume a devbox server may mount (core drops every other mount into a volume).
        SystemServers::allowVolumeSourceUsing(function (Server $server): ?string {
            if (!DevboxAccess::ready() || !str_starts_with((string) $server->external_id, Devbox::EXTERNAL_PREFIX)) {
                return null;
            }

            return Devbox::query()->where('server_id', $server->id)->first()?->volumeSource();
        });

        SystemServers::describeUsing(function (Server $server): ?array {
            if (!DevboxAccess::ready()) {
                return null;
            }

            $devbox = Devbox::query()->where('server_id', $server->id)->first();

            return $devbox === null ? null : [
                'kind' => 'devbox',
                'user_id' => $devbox->user_id ?? $devbox->game?->owner_id,
                'server_id' => $devbox->game_server_id,
            ];
        });

        // A running devbox holds its game server: load balancing doesn't move it from under someone.
        SystemServers::holdUsing(fn (Server $game): bool => DevboxAccess::ready()
            && Devbox::query()->where('game_server_id', $game->id)->whereNotNull('token_hash')->exists());

        // The system user owns every devbox server; it must not get "your server is installed"
        // mail (its address doesn't exist). Returning false cancels the notification.
        Event::listen(NotificationSending::class, function (NotificationSending $event): ?bool {
            return $event->notifiable instanceof User
                && $event->notifiable->username === (string) config('plugged-host-devbox.system_user', 'ph-devbox')
                ? false
                : null;
        });

        $this->registerAgentRoutes();
        $this->registerCleanup();
        $this->registerDoctorChecks();
    }

    private function registerAgentRoutes(): void
    {
        Route::prefix('/plugged-host/devbox')
            ->withoutMiddleware([
                \App\Http\Middleware\PreventRequestForgery::class,
                \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
            ])
            ->group(function (): void {
                Route::post('/boot', [AgentController::class, 'boot'])->name('plugged-host.devbox.agent.boot');
                Route::post('/heartbeat', [AgentController::class, 'heartbeat'])->name('plugged-host.devbox.agent.heartbeat');
                Route::post('/auth', [AgentController::class, 'auth'])->name('plugged-host.devbox.agent.auth');

                // `ph` inside the devbox, acting on its game server.
                Route::post('/game/status', [GameController::class, 'status'])->name('plugged-host.devbox.game.status');
                Route::post('/game/power', [GameController::class, 'power'])->name('plugged-host.devbox.game.power');
                Route::post('/game/websocket', [GameController::class, 'websocket'])->name('plugged-host.devbox.game.websocket');
            });
    }

    /**
     * What a devbox belongs to going away takes the devbox with it right away, not at the next
     * reconcile: a removed subuser must not keep a shell on the server's files for a minute.
     */
    private function registerCleanup(): void
    {
        // Queued after the commit: a game server's deletion never waits on its devboxes' Wings
        // calls, and nothing is removed if the deletion rolls back.
        Server::deleting(function (Server $server): void {
            if (!DevboxAccess::ready() || str_starts_with((string) $server->external_id, Devbox::EXTERNAL_PREFIX)) {
                return;
            }

            self::destroyLater(Devbox::query()->where('game_server_id', $server->id)->pluck('id')->all());
        });

        User::deleting(function (User $user): void {
            if (!DevboxAccess::ready()) {
                return;
            }

            self::destroyLater(Devbox::query()->where('user_id', $user->id)->pluck('id')->all());
        });

        $revoke = function (Subuser $subuser): void {
            if (!DevboxAccess::ready()) {
                return;
            }

            $game = $subuser->server;
            $user = $subuser->user;
            if ($game === null || $user === null || ($subuser->exists && DevboxAccess::isMember($user, $game))) {
                return;
            }

            foreach (Devbox::query()->where('game_server_id', $game->id)->where('user_id', $user->id)->get() as $devbox) {
                $this->quietly(fn () => app(DevboxPower::class)->stop($devbox));
            }
        };

        Subuser::deleted($revoke);
        Subuser::updated($revoke);

        // A game server that starts moving (any path: owner, admin, load balancing, limbo) loses
        // its devboxes' sessions now; they are rebuilt on the new node the next time they open.
        ServerTransfer::created(function (ServerTransfer $transfer): void {
            if (!DevboxAccess::ready()) {
                return;
            }

            foreach (Devbox::query()->where('game_server_id', $transfer->server_id)->whereNotNull('token_hash')->get() as $devbox) {
                $this->quietly(fn () => app(DevboxPower::class)->stop($devbox));
            }
        });
    }

    private function registerDoctorChecks(): void
    {
        Doctor::check('plugged-host-devbox', 'Egg and system user', function (): array {
            if (!DevboxAccess::ready()) {
                return Doctor::warn('not ready: run the migrations (and DEVBOX_ENABLED must be on)');
            }

            $missing = array_filter([
                DevboxProvisioner::egg() === null ? 'the devbox egg' : null,
                DevboxProvisioner::systemUser() === null ? 'the system user' : null,
            ]);

            return $missing === []
                ? Doctor::ok('egg and ' . config('plugged-host-devbox.system_user') . ' in place')
                : Doctor::fail('missing ' . implode(' and ', $missing) . ': run php artisan plugged-host:devbox-setup');
        });

        Doctor::check('plugged-host-devbox', 'Reconcile (idle stop)', function (): array {
            $tick = (int) Cache::get(DevboxReconcileCommand::TICK_KEY, 0);

            return $tick > time() - 180
                ? Doctor::ok('last run ' . Doctor::ago($tick))
                : Doctor::fail('last run ' . Doctor::ago($tick) . ': is the scheduler running?');
        });

        Doctor::check('plugged-host-devbox', 'Nodes mount game files', function (): array {
            if (!DevboxAccess::ready()) {
                return Doctor::ok('not in use');
            }

            $missing = Devbox::query()->where('boot_status', 'mount_missing')->with('node')->get()
                ->map(fn (Devbox $devbox) => $devbox->node?->name)->filter()->unique()->values();

            return $missing->isEmpty()
                ? Doctor::ok('no devbox reported a missing /server')
                : Doctor::fail('/server missing on ' . $missing->implode(', ') . ': run docs/deploy/node-scan/update-nodes.mjs --devbox on them');
        });
    }

    /**
     * @param  list<int>  $ids
     */
    private static function destroyLater(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        dispatch(function () use ($ids): void {
            foreach (Devbox::query()->whereIn('id', $ids)->get() as $devbox) {
                try {
                    app(DevboxProvisioner::class)->destroy($devbox);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        })->afterCommit();
    }

    private function quietly(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
