<?php

namespace PluggedHost\Devbox\Services;

use App\Models\Egg;
use App\Models\Mount;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Services\Servers\ServerCreationService;
use App\Services\Servers\ServerDeletionService;
use Filament\Facades\Filament;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PluggedHost\Core\Support\Release;
use PluggedHost\Core\Support\SystemServers;
use PluggedHost\Devbox\Models\Devbox;
use PluggedHost\Devbox\Support\DevboxAccess;
use PluggedHost\Devbox\Support\DevboxWeb;
use PluggedHost\Servers\Support\AccountAllowance;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Creating, repairing and removing devboxes.
 *
 * A devbox is a hidden server (devbox egg, owned by the system user, external_id marks it) on its
 * game server's node, with its own allocation (the SSH port) and a Mount of the game server's
 * volume at /server. The Mount is only ever created here, inside SystemServers::asSystem(), and
 * core's guard passes it to Wings only for this devbox (Devbox::volumeSource()).
 */
final class DevboxProvisioner
{
    public const EGG_UUID = '21a6b972-a167-451f-b79b-70d2675b950c';

    public const IMAGE_REPOSITORY = 'ghcr.io/plugged-host/devbox';

    /** Every node a devbox server was made on: where the reconcile looks for leftover devboxes. */
    public const NODES_KEY = 'plugged-host-devbox:nodes';

    /**
     * The image devboxes run: DEVBOX_IMAGE (a tag, a digest `…@sha256:…`, or `~…` for a local
     * image), or by default this release's tag (ghcr.io/plugged-host/devbox:2.2.0), which CI
     * publishes with every release, so a panel never runs a newer release line's devbox.
     */
    public static function image(): string
    {
        $image = trim((string) config('plugged-host-devbox.image', ''));

        return $image !== '' ? $image : self::IMAGE_REPOSITORY . ':' . Release::version();
    }

    /**
     * The devbox $user works in on $game, created (not started) when it doesn't exist yet.
     */
    public function ensure(User $user, Server $game): Devbox
    {
        $resolved = DevboxAccess::resolve($user, $game) ?? throw new DevboxException('not_allowed');

        [$kind] = $resolved;
        $payer = ($kind === 'shared' ? $game->user : $user) ?? throw new DevboxException('not_allowed');
        if ($reason = DevboxAccess::refusal($user, $game, $payer)) {
            throw new DevboxException($reason);
        }

        $owner = $kind === 'shared' ? null : (int) $user->id;

        return $this->locked($game, $owner, function () use ($game, $owner): Devbox {
            $devbox = Devbox::query()
                ->where('game_server_id', $game->id)
                ->when($owner === null, fn ($q) => $q->whereNull('user_id'), fn ($q) => $q->where('user_id', $owner))
                ->first();

            $devbox ??= Devbox::query()->create([
                'game_server_id' => $game->id,
                'user_id' => $owner,
                'slug' => Str::lower(Str::random(16)),
            ]);

            // A server that is gone, or stayed behind when the game server moved, is replaced.
            $server = $devbox->server;
            if ($server !== null && (int) $server->node_id !== (int) $game->node_id) {
                $this->removeServer($devbox);
                $server = null;
            }

            if ($server === null) {
                $this->createServer($devbox->fresh());
            }

            return $devbox->fresh();
        });
    }

    /**
     * The owner switches the shared devbox on: everyone's personal devbox on the server goes.
     */
    public function enableShared(Server $game): Devbox
    {
        $devbox = $this->locked($game, null, function () use ($game): Devbox {
            return DevboxAccess::shared($game) ?? Devbox::query()->create([
                'game_server_id' => $game->id,
                'user_id' => null,
                'slug' => Str::lower(Str::random(16)),
                'shared_rights' => ['status' => true, 'logs' => true, 'power' => false, 'console' => false],
            ]);
        });

        // One that can't go right now (Wings down, under abuse review) is retried by the
        // reconcile, which removes personal devboxes on a server whose shared one is on.
        foreach (Devbox::query()->where('game_server_id', $game->id)->whereNotNull('user_id')->get() as $personal) {
            try {
                $this->destroy($personal);
            } catch (Throwable $exception) {
                if (!$exception instanceof DevboxException) {
                    report($exception);
                }
            }
        }

        return $devbox;
    }

    public function disableShared(Server $game): void
    {
        if ($shared = DevboxAccess::shared($game)) {
            $this->destroy($shared);
        }
    }

    /**
     * Removes a devbox completely: its server (through Wings), its mount and the row. Nothing is
     * removed while the abuse checks hold a live critical finding on its server (the finding and
     * its evidence would go with it) or while Wings can't confirm the deletion: the row stays and
     * whoever asked (the reconcile, every minute) asks again. DevboxException('abuse' or
     * 'node_down') says which.
     */
    public function destroy(Devbox $devbox): void
    {
        if (DevboxAbuse::underReview($devbox)) {
            throw new DevboxException('abuse');
        }

        self::outsideTenant(function () use ($devbox): void {
            $this->removeServer($devbox);
            if (DevboxWeb::domain() !== '') {
                try {
                    app(DevboxDns::class)->remove($devbox);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
            ConnectCodes::forgetAll($devbox);
            $devbox->delete();
        });
    }

    private function createServer(Devbox $devbox): void
    {
        $game = $devbox->game ?? throw new DevboxException('missing');
        $node = $game->node ?? throw new DevboxException('missing');
        $tier = DevboxAccess::tierFor($devbox) ?? throw new DevboxException('not_allowed');
        $limits = (array) config("plugged-host-devbox.tiers.{$tier}");
        $egg = self::egg() ?? throw new DevboxException('not_set_up');
        $owner = self::systemUser() ?? throw new DevboxException('not_set_up');

        $label = $devbox->isShared() ? 'shared' : (string) $devbox->user?->username;
        $name = Str::limit("devbox {$game->uuid_short} {$label}", 180, '');

        // One allocation pick at a time per node, so two devboxes never grab the same port.
        $server = Cache::lock("plugged-host-devbox:allocate:{$node->id}", 120)->block(60, function () use ($node, $egg, $owner, $limits, $name, $game, $devbox): Server {
            $allocation = AccountAllowance::allocationOn($node);

            return app(ServerCreationService::class)->handle([
                'name' => $name,
                'description' => "plugged.host devbox for {$game->uuid_short} (system server: managed by plugged-host-devbox, do not edit)",
                'owner_id' => $owner->id,
                'egg_id' => $egg->id,
                'node_id' => $node->id,
                'allocation_id' => $allocation->id,
                'memory' => (int) ($limits['memory'] ?? 2048),
                'swap' => 0,
                'disk' => (int) ($limits['disk'] ?? 5120),
                'io' => 500,
                'cpu' => (int) ($limits['cpu'] ?? 200),
                'threads' => null,
                'oom_killer' => true,
                'startup' => 'ph serve',
                'image' => self::image(),
                'skip_scripts' => true,
                'database_limit' => 0,
                'allocation_limit' => 0,
                'backup_limit' => 0,
                'environment' => [
                    'DEVBOX_TOKEN' => '',
                    'DEVBOX_PANEL_URL' => (string) config('plugged-host-devbox.panel_url'),
                ],
                'external_id' => $devbox->externalId(),
                'start_on_completion' => false,
            ]);
        });

        $base = rtrim((string) $node->daemon_base, '/');
        $mount = SystemServers::asSystem(fn (): Mount => Mount::query()->create([
            'uuid' => Uuid::uuid4()->toString(),
            'name' => 'ph-devbox-' . $devbox->id,
            'description' => "Game server {$game->uuid_short}'s files for its devbox (plugged-host-devbox)",
            'source' => SystemServers::clean("{$base}/{$game->uuid}"),
            'target' => '/server',
            'read_only' => false,
            'user_mountable' => false,
        ]));
        $server->mounts()->attach($mount->id);

        $devbox->forceFill([
            'server_id' => $server->id,
            'mount_id' => $mount->id,
            'node_id' => $node->id,
            'token_hash' => null,
            'stopping_at' => null,
            'boot_status' => null,
            'boot_detail' => null,
        ])->save();

        $nodes = (array) Cache::get(self::NODES_KEY, []);
        if (!in_array((int) $node->id, $nodes, true)) {
            Cache::forever(self::NODES_KEY, [...$nodes, (int) $node->id]);
        }

        Log::info('plugged-host-devbox: created', ['devbox' => $devbox->id, 'server' => $server->uuid, 'game' => $game->uuid, 'tier' => $tier]);
    }

    /**
     * Deletes the devbox's server on Wings first, and only then in the panel: never by force, so
     * a devbox Wings still runs never loses the row that lets the panel stop it. Upstream's
     * deletion service carries on past most Wings errors (it only stops on a connection error), so
     * the Wings call is made and checked here; its own second call then gets a 404, which it
     * treats as gone.
     */
    private function removeServer(Devbox $devbox): void
    {
        if ($server = $devbox->server) {
            try {
                app(DaemonServerRepository::class)->setServer($server)->delete();
            } catch (RequestException $exception) {
                if ($exception->response->status() !== 404) {
                    report($exception);

                    throw new DevboxException('node_down');
                }
            } catch (ConnectionException) {
                throw new DevboxException('node_down');
            }

            app(ServerDeletionService::class)->handle($server);
        }

        if ($mount = $devbox->mount) {
            $mount->servers()->detach();
            $mount->delete();
        }

        $devbox->forceFill(['server_id' => null, 'mount_id' => null, 'token_hash' => null, 'stopping_at' => null, 'connections' => 0])->save();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function locked(Server $game, ?int $owner, callable $callback): mixed
    {
        $key = "plugged-host-devbox:ensure:{$game->id}:" . ($owner ?? 'shared');

        return Cache::lock($key, 180)->block(90, fn () => self::outsideTenant($callback));
    }

    /**
     * Runs $callback with no Filament tenant. Clicked from a game server's page, the server panel's
     * tenant is that game server, and Filament 4 then scopes every query of a tenant-owned model
     * (allocations) to it and attaches every new one to it: the devbox's new port allocation went
     * to the game server and upstream's ServerCreationService could not claim it.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private static function outsideTenant(callable $callback): mixed
    {
        $tenant = Filament::getTenant();
        if ($tenant === null) {
            return $callback();
        }

        Filament::setTenant(null, isQuiet: true);

        try {
            return $callback();
        } finally {
            Filament::setTenant($tenant, isQuiet: true);
        }
    }

    public static function egg(): ?Egg
    {
        return Egg::query()->where('uuid', self::EGG_UUID)->first();
    }

    public static function systemUser(): ?User
    {
        return User::query()->where('username', (string) config('plugged-host-devbox.system_user', 'ph-devbox'))->first();
    }
}
