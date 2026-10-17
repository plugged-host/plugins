<?php

namespace PluggedHost\Devbox\Console\Commands;

use App\Models\Node;
use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use PluggedHost\Devbox\Models\Devbox;
use PluggedHost\Devbox\Services\DevboxAbuse;
use PluggedHost\Devbox\Services\DevboxException;
use PluggedHost\Devbox\Services\DevboxPower;
use PluggedHost\Devbox\Services\DevboxProvisioner;
use PluggedHost\Devbox\Support\DevboxAccess;
use Throwable;

/**
 * Every minute: stop devboxes nobody is connected to (idle_minutes), that ran max_hours, whose
 * people lost access (Plus, permission, an abuse finding) or whose game server's disk is full;
 * finish stops Wings has not carried out yet (asked again, killed after DevboxPower::KILL_AFTER);
 * remove devboxes whose game server is gone or moved to another node (storage failover moves
 * servers without an event), personal ones on a server whose shared devbox is on, and those
 * unused for cleanup_days, which also frees their disk, port and the RAM Pelican's node math
 * reserves for them. The devbox can't do any of this itself: Wings would restart it.
 *
 * It also asks Wings (GET /api/servers, one call per node that has or had a devbox) what really
 * runs: any devbox container (the devbox egg) running without a devbox that may run, a row, or a
 * panel server at all is killed. So a stop that never reached Wings, or a row removed by hand,
 * can't leave a shell on someone's files.
 */
class DevboxReconcileCommand extends Command
{
    protected $signature = 'plugged-host:devbox-reconcile {--dry-run : Say what would happen, change nothing}';

    protected $description = 'Stop idle devboxes, finish stops, kill stray devbox containers and remove orphaned, moved or unused devboxes.';

    public const TICK_KEY = 'plugged-host-devbox:reconcile-tick';

    /** Wings states in which a container runs (and so can be signed in to). */
    private const ACTIVE_STATES = ['running', 'starting'];

    public function handle(DevboxPower $power, DevboxProvisioner $provisioner): int
    {
        if (!DevboxAccess::tableExists()) {
            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $ready = DevboxAccess::ready();
        $wings = $this->wings();

        if (!$ready) {
            // Switched off in the settings (or abuse protection is): stop whatever still runs,
            // finish those stops, kill strays; change nothing else.
            foreach (Devbox::query()->whereNotNull('token_hash')->with('server')->get() as $devbox) {
                $this->line("devbox #{$devbox->id}: stop (devboxes are switched off)");
                if (!$dry) {
                    $this->quietly(fn () => $devbox->stopping_at === null ? $power->stop($devbox) : $power->settle($devbox, $this->known($devbox, $wings)));
                }
            }
            $this->killStrays($wings, $dry);

            return self::SUCCESS;
        }

        if (!$dry) {
            Cache::put(self::TICK_KEY, time(), now()->addDay());
        }
        $idle = max(1, (int) config('plugged-host-devbox.idle_minutes', 15));
        $maxHours = max(1, (int) config('plugged-host-devbox.max_hours', 12));
        $cleanupDays = max(1, (int) config('plugged-host-devbox.cleanup_days', 14));

        foreach (Devbox::query()->with(['game.node', 'server', 'user'])->get() as $devbox) {
            try {
                $this->reconcile($devbox, $power, $provisioner, $dry, $idle, $maxHours, $cleanupDays, $wings);
            } catch (DevboxException $exception) {
                // Known and retried next minute: Wings down, a devbox kept for an abuse review.
                $this->warn("devbox #{$devbox->id}: not now ({$exception->reason})");
            } catch (Throwable $exception) {
                report($exception);
                $this->warn("devbox #{$devbox->id}: {$exception->getMessage()}");
            }
        }

        $this->killStrays($wings, $dry);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array{state: string, egg: string, disk: ?int, node: int}>  $wings
     */
    private function reconcile(Devbox $devbox, DevboxPower $power, DevboxProvisioner $provisioner, bool $dry, int $idle, int $maxHours, int $cleanupDays, array $wings): void
    {
        $game = $devbox->game;

        $remove = match (true) {
            !$game instanceof Server => 'its game server is gone',
            $devbox->server !== null && (int) $devbox->server->node_id !== (int) $game->node_id => 'its game server moved to another node',
            !$devbox->isShared() && $devbox->user === null => 'its person is gone',
            !$devbox->isShared() && DevboxAccess::shared($game) !== null => 'the server\'s shared devbox is on',
            ($devbox->last_used_at ?? $devbox->created_at)?->lt(now()->subDays($cleanupDays)) ?? false => "unused for {$cleanupDays} days",
            default => null,
        };

        // One under abuse review is kept (stopped below) until an admin dismisses or resolves its
        // findings: they, the evidence, would go with its server.
        if ($remove !== null && !DevboxAbuse::underReview($devbox)) {
            $this->line("devbox #{$devbox->id}: remove ({$remove})");
            if (!$dry) {
                $provisioner->destroy($devbox);
            }

            return;
        }

        $entry = $this->entry($devbox, $wings);

        if ($devbox->isStopping()) {
            $this->line("devbox #{$devbox->id}: finish stopping (Wings: " . ($entry['state'] ?? 'not listed') . ')');
            if (!$dry) {
                $power->settle($devbox, $this->known($devbox, $wings));
            }

            return;
        }

        if ($devbox->token_hash === null || !$game instanceof Server) {
            return; // stopped
        }

        $gameEntry = $wings[(string) $game->uuid] ?? null;

        $stop = match (true) {
            DevboxAccess::people($devbox)->isEmpty() => 'nobody may use it any more',
            DevboxAccess::blockedReason($game) === 'suspended' => 'its game server is suspended',
            (bool) $devbox->server?->isSuspended() => 'it is suspended',
            DevboxAbuse::underReview($devbox) => 'an abuse finding on it',
            $devbox->started_at?->lt(now()->subHours($maxHours)) ?? false => "ran {$maxHours} hours",
            $devbox->connections === 0 && ($devbox->last_used_at ?? $devbox->started_at)?->lt(now()->subMinutes($idle)) => "idle for {$idle} minutes",
            // Started but never reported back (image pull failed, node down): give it 10 minutes.
            $devbox->booted_at === null && $devbox->started_at?->lt(now()->subMinutes(10)) => 'never came up',
            // Its writes to /server count against the game server's disk, which Wings only polices
            // for the game server itself. Wings' figure; see DevboxPower::gameDiskFull().
            $gameEntry !== null && $power->gameDiskFull($game, $gameEntry['disk']) => 'its game server\'s disk is full',
            // Stopped behind the panel's back (node reboot, Wings' crash detection gave up).
            $entry !== null && !in_array($entry['state'], self::ACTIVE_STATES, true) && $entry['state'] !== 'stopping'
                && $devbox->booted_at !== null && ($devbox->last_seen_at ?? $devbox->booted_at)->lt(now()->subMinutes(3)) => 'Wings has it stopped',
            default => null,
        };

        if ($stop !== null) {
            $this->line("devbox #{$devbox->id}: stop ({$stop})");
            if (!$dry) {
                $power->stop($devbox);
            }
        }
    }

    /**
     * Devbox containers Wings runs that nothing in the panel lets run: no panel server, no devbox
     * row, or a devbox that is stopped. Killed, not stopped: nobody may be in them.
     *
     * @param  array<string, array{state: string, egg: string, disk: ?int, node: int}>  $wings
     */
    private function killStrays(array $wings, bool $dry): void
    {
        $candidates = array_filter($wings, fn (array $entry): bool => $entry['egg'] === DevboxProvisioner::EGG_UUID
            && in_array($entry['state'], self::ACTIVE_STATES, true));
        if ($candidates === []) {
            return;
        }

        $servers = Server::query()->whereIn('uuid', array_keys($candidates))->get()->keyBy('uuid');
        $devboxes = Devbox::query()->whereIn('server_id', $servers->pluck('id')->all())->get()->keyBy('server_id');
        $nodes = Node::query()->whereIn('id', array_unique(array_column($candidates, 'node')))->get()->keyBy('id');

        foreach ($candidates as $uuid => $entry) {
            $server = $servers->get($uuid);
            $devbox = $server ? $devboxes->get($server->id) : null;

            $why = match (true) {
                $server === null => 'no server in the panel',
                $devbox === null => 'no devbox row',
                !$devbox->isRunning() && !$devbox->isStopping() => 'its devbox is stopped',
                (int) $server->node_id !== (int) $entry['node'] => 'it runs on another node than the panel says',
                default => null,
            };
            $node = $nodes->get($entry['node']);

            if ($why === null || $node === null) {
                continue;
            }

            $this->warn("devbox container {$uuid} on {$node->name}: kill ({$why})");
            if ($dry) {
                continue;
            }

            try {
                (new DaemonServerRepository())->setNode($node)->getHttpClient()
                    ->connectTimeout(3)
                    ->timeout(10)
                    ->post("/api/servers/{$uuid}/power", ['action' => 'kill']);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * What Wings runs on every node that has or had a devbox, one GET /api/servers per node,
     * keyed by server uuid. Nodes that don't answer are left out (their devboxes are asked again
     * next minute).
     *
     * @return array<string, array{state: string, egg: string, disk: ?int, node: int}>
     */
    private function wings(): array
    {
        $egg = DevboxProvisioner::egg();
        $ids = array_merge(
            Devbox::query()->whereNotNull('node_id')->distinct()->pluck('node_id')->all(),
            $egg ? Server::query()->where('egg_id', $egg->id)->distinct()->pluck('node_id')->all() : [],
            (array) Cache::get(DevboxProvisioner::NODES_KEY, []),
        );
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (Node::query()->whereIn('id', $ids)->get() as $node) {
            try {
                $list = (new DaemonServerRepository())->setNode($node)->getHttpClient()
                    ->connectTimeout(3)
                    ->timeout(10)
                    ->get('/api/servers')
                    ->json();
            } catch (Throwable) {
                $this->warn("node {$node->name}: Wings did not answer");

                continue;
            }

            foreach (is_array($list) ? $list : [] as $entry) {
                $uuid = (string) ($entry['configuration']['uuid'] ?? '');
                if ($uuid === '') {
                    continue;
                }

                $out[$uuid] = [
                    'state' => (string) ($entry['state'] ?? ''),
                    'egg' => (string) ($entry['configuration']['egg']['id'] ?? ''),
                    'disk' => isset($entry['utilization']['disk_bytes']) ? (int) $entry['utilization']['disk_bytes'] : null,
                    'node' => (int) $node->id,
                ];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, array{state: string, egg: string, disk: ?int, node: int}>  $wings
     * @return null|array{state: string, egg: string, disk: ?int, node: int}
     */
    private function entry(Devbox $devbox, array $wings): ?array
    {
        $uuid = (string) ($devbox->server?->uuid ?? '');

        return $uuid !== '' ? ($wings[$uuid] ?? null) : null;
    }

    /**
     * Wings' state of the devbox from the listing: null when its node wasn't listed (settle()
     * then asks Wings itself), 'missing' when the node answered without it.
     *
     * @param  array<string, array{state: string, egg: string, disk: ?int, node: int}>  $wings
     */
    private function known(Devbox $devbox, array $wings): ?string
    {
        $server = $devbox->server;
        if ($server === null) {
            return null;
        }

        if ($entry = $wings[(string) $server->uuid] ?? null) {
            return $entry['state'] !== '' ? $entry['state'] : null;
        }

        $listed = array_filter($wings, fn (array $entry): bool => $entry['node'] === (int) $server->node_id) !== [];

        return $listed ? 'missing' : null;
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
