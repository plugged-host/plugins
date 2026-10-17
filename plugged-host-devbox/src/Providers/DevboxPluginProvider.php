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
use PluggedHost\Abuse\Models\AbuseFinding;
use PluggedHost\Core\Doctor\Doctor;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Core\Support\SystemServers;
use PluggedHost\Devbox\Console\Commands\DevboxReconcileCommand;
use PluggedHost\Devbox\Http\AgentController;
use PluggedHost\Devbox\Http\GameController;
use PluggedHost\Devbox\Http\WebController;
use PluggedHost\Devbox\Livewire\OpenInVsCode;
use PluggedHost\Devbox\Models\Devbox;
use PluggedHost\Devbox\Services\DevboxAbuse;
use PluggedHost\Devbox\Services\DevboxException;
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
        $this->registerWebRoutes();
        $this->registerCleanup();
        $this->registerAbuseHook();
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

                // VS Code in the browser: what the edge (nginx) asks, without the panel session.
                Route::get('/web/login', [WebController::class, 'login'])->name('plugged-host.devbox.web.login');
                Route::get('/web/authorize', [WebController::class, 'authorize'])->name('plugged-host.devbox.web.authorize');
            });
    }

    /**
     * Where the edge sends a browser without a devbox cookie: the panel session decides.
     */
    private function registerWebRoutes(): void
    {
        Route::middleware('web')->get('/plugged-host/devbox/web/open', [WebController::class, 'reopen'])
            ->name('plugged-host.devbox.web.open');
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

        // A subuser who loses access (removed, or a permission taken) loses it now: their own
        // devbox stops, and a shared one they were in starts over with a new token and nothing
        // of theirs left running in it (they shared its Linux user).
        $revoke = function (Subuser $subuser): void {
            if (!DevboxAccess::ready()) {
                return;
            }

            $game = $subuser->server;
            $user = $subuser->user;
            if ($game === null || $user === null || ($subuser->exists && DevboxAccess::isMember($user, $game))) {
                return;
            }

            foreach (Devbox::query()->where('game_server_id', $game->id)->where('user_id', $user->id)->whereNotNull('token_hash')->get() as $devbox) {
                $this->quietly(fn () => app(DevboxPower::class)->stop($devbox));
            }

            if (self::wasMember($subuser)) {
                $this->restartShared($game);
            }
        };

        Subuser::deleted($revoke);
        Subuser::updated($revoke);

        // The game server changed hands: whoever is no longer a member loses their devbox, and
        // the shared one starts over (the new owner's Plus pays for it now, or it stops). Suspended:
        // every devbox on it stops now, not at the next reconcile.
        Server::updated(function (Server $server): void {
            $owner = $server->wasChanged('owner_id');
            $suspended = $server->wasChanged('status') && $server->isSuspended();
            if ((!$owner && !$suspended) || SystemServers::is($server) || !DevboxAccess::ready()) {
                return;
            }

            $power = app(DevboxPower::class);
            foreach (Devbox::query()->where('game_server_id', $server->id)->whereNotNull('token_hash')->whereNull('stopping_at')->with('user')->get() as $devbox) {
                if ($suspended) {
                    $this->quietly(fn () => $power->stop($devbox));
                } elseif ($devbox->isShared()) {
                    $this->quietly(fn () => $power->restart($devbox));
                } elseif ($devbox->user === null || !DevboxAccess::isMember($devbox->user, $server)) {
                    $this->quietly(fn () => $power->stop($devbox));
                }
            }
        });

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

    /**
     * Abuse protection is a requirement (plugin.json), but only hooked while it is enabled: a
     * critical finding on a devbox takes devboxes away from its person (Services\DevboxAbuse).
     */
    private function registerAbuseHook(): void
    {
        if (!Plugins::enabled('plugged-host-abuse') || !Plugins::available(AbuseFinding::class)) {
            return;
        }

        AbuseFinding::saved(function (AbuseFinding $finding): void {
            try {
                app(DevboxAbuse::class)->onFinding($finding);
            } catch (Throwable $exception) {
                // Never in the way of the abuse checks themselves.
                report($exception);
            }
        });
    }

    /**
     * Whether a subuser had what a devbox needs before this change (before the update, or when
     * deleted): only then can they have been in the shared devbox.
     */
    private static function wasMember(Subuser $subuser): bool
    {
        $before = $subuser->exists ? $subuser->getOriginal('permissions') : $subuser->permissions;

        if (is_string($before)) {
            $before = json_decode($before, true);
        }

        return DevboxAccess::grants(is_array($before) ? $before : []);
    }

    private function restartShared(Server $game): void
    {
        $shared = DevboxAccess::shared($game);
        if ($shared !== null && $shared->isRunning()) {
            $this->quietly(fn () => app(DevboxPower::class)->restart($shared));
        }
    }

    private function registerDoctorChecks(): void
    {
        Doctor::check('plugged-host-devbox', 'Egg and system user', function (): array {
            if (!DevboxAccess::ready()) {
                return Doctor::warn('not ready: needs DEVBOX_ENABLED on, plugged-host-abuse enabled and the migrations (018, 020) run');
            }

            $missing = array_filter([
                DevboxProvisioner::egg() === null ? 'the devbox egg' : null,
                DevboxProvisioner::systemUser() === null ? 'the system user' : null,
            ]);

            return $missing === []
                ? Doctor::ok('egg and ' . config('plugged-host-devbox.system_user') . ' in place')
                : Doctor::fail('missing ' . implode(' and ', $missing) . ': run php artisan plugged-host:devbox-setup');
        });

        Doctor::check('plugged-host-devbox', 'Image', function (): array {
            if (!DevboxAccess::ready()) {
                return Doctor::ok('not in use (devboxes are off)');
            }

            $image = DevboxProvisioner::image();

            return str_contains($image, '@sha256:')
                ? Doctor::ok($image)
                : Doctor::warn("{$image}: a tag; DEVBOX_IMAGE=" . DevboxProvisioner::IMAGE_REPOSITORY . '@sha256:… pins the exact build');
        });

        Doctor::check('plugged-host-devbox', 'Reconcile (idle stop)', function (): array {
            if (!DevboxAccess::ready()) {
                return Doctor::ok('not in use (devboxes are off)');
            }

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
                } catch (DevboxException) {
                    // Wings down or kept for an abuse review: the reconcile removes it later.
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
