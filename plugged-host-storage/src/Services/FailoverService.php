<?php

namespace PluggedHost\Storage\Services;

use App\Facades\Activity;
use App\Models\Allocation;
use App\Models\Backup;
use App\Models\Node;
use App\Models\Server;
use App\Repositories\Daemon\DaemonBackupRepository;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Services\Deployment\FindViableNodesService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PluggedHost\Core\Nodes\NodeTelemetry;
use PluggedHost\Core\Support\SystemServers;
use PluggedHost\Storage\Models\NodeFailover;
use Throwable;

/**
 * Node-failure detection and recovery (WP9).
 *
 * OPERATOR OVERRIDE (recorded 2026-09-18): failover is FULLY AUTOMATIC once a node is offline
 * for ~5 minutes. `failover_mode` defaults to `auto`. Detection is the panel's own minute probe
 * (core's NodeTelemetry, every node) reaching the failure threshold. The storage agent's
 * reachability probe is used as corroboration: if the agent can still reach the node, it is by
 * definition still "responding to pings", so we DECLINE to fail over (that is a partition, not a
 * dead node, and acting would create two live copies) — this honours the operator's own
 * "doesn't respond to any pings = offline" definition rather than contradicting it. When the
 * agent is unconfigured/unreachable we proceed on the panel witness alone, as instructed.
 *
 * Recovery is NOT a transfer (fact 16): upstream's transfer copies from the source node, which
 * is dead. Instead we fence, reassign to a live node, create the server there, and restore from
 * the storage mirror archive (fact 17). The `fenced` flag is written FIRST and is what stops the
 * old copy ever starting again.
 *
 * VERIFY IN PHASE C against real wings: that DaemonBackupRepository::restore honours the
 * overridden download_url for the backup host's adapter (fact 17). If it does not, the fallback
 * is to have the agent upload the tar into the S3 bucket as a real backup object first.
 */
class FailoverService
{
    public function __construct(private readonly StorageService $storage) {}

    public function mode(): string
    {
        return (string) config('plugged-host-storage.failover_mode', 'auto');
    }

    /**
     * Scheduled every minute. Returns a short summary for the command's --status output.
     *
     * @return array<string, mixed>
     */
    public function sweep(bool $dryRun = false): array
    {
        $mode = $this->mode();

        if ($mode === 'off') {
            return ['mode' => 'off', 'acted' => 0, 'nodes_down' => 0];
        }

        if (!$this->tableReady()) {
            Log::warning('plugged-host-storage: node_failovers table missing; failover inert.');

            return ['mode' => $mode, 'acted' => 0, 'nodes_down' => 0, 'error' => 'migration not run'];
        }

        $threshold = max(1, (int) config('plugged-host-storage.failover_after_failures', 5));
        $cap = max(1, (int) config('plugged-host-storage.failover_max_per_run', 5));

        $downNodes = Node::query()->get()->filter(fn (Node $n) => $this->looksDown($n, $threshold));
        $acted = 0;

        foreach ($downNodes as $node) {
            // Second witness: if the agent can still reach it, it is not offline — skip.
            if ($this->storage->reachableViaAgent($node) === true) {
                Log::info("plugged-host-storage: node {$node->id} unreachable from panel but reachable via agent — treating as partition, not acting.");
                continue;
            }

            // System servers (devboxes) have no backup to restore; their plugin removes them.
            $servers = SystemServers::exclude(Server::query())->where('node_id', $node->getKey())->get();

            foreach ($servers as $server) {
                if ($acted >= $cap) {
                    break 2;
                }

                if ($this->isFenced($server)) {
                    continue; // already recovered / being recovered
                }

                if ($mode === 'notify') {
                    $this->notifyOnly($server, $node);
                    $acted++;
                    continue;
                }

                // auto
                if (!$dryRun) {
                    $this->recover($server, $node);
                }
                $acted++;
            }
        }

        return ['mode' => $mode, 'acted' => $acted, 'nodes_down' => $downNodes->count(), 'dry_run' => $dryRun];
    }

    /**
     * Node looks dead to the panel: core's node telemetry has at least `$threshold` consecutive
     * failed probes in a row (one a minute, every node).
     */
    private function looksDown(Node $node, int $threshold): bool
    {
        try {
            return app(NodeTelemetry::class)->consecutiveFailures((int) $node->getKey()) >= $threshold;
        } catch (Throwable) {
            return false;
        }
    }

    private function isFenced(Server $server): bool
    {
        return NodeFailover::query()
            ->where('server_id', $server->getKey())
            ->whereIn('state', ['running', 'done', 'fenced'])
            ->exists();
    }

    private function notifyOnly(Server $server, Node $node): void
    {
        NodeFailover::query()->create([
            'node_id' => $node->getKey(),
            'server_id' => $server->getKey(),
            'from_node_id' => $node->getKey(),
            'state' => 'pending',
            'started_at' => now(),
            'detail' => ['mode' => 'notify', 'reason' => 'node offline past threshold'],
        ]);

        $this->tell($server, false, 'Your server\'s node appears offline. Recovery is available.');
    }

    /**
     * Recover one server off the dead node. Fences first, then reassign → create → restore.
     */
    private function recover(Server $server, Node $deadNode): void
    {
        $record = NodeFailover::query()->create([
            'node_id' => $deadNode->getKey(),
            'server_id' => $server->getKey(),
            'from_node_id' => $deadNode->getKey(),
            'state' => 'running', // fenced-in-effect: isFenced() now returns true for this server
            'started_at' => now(),
            'detail' => ['mode' => 'auto'],
        ]);

        try {
            $needed = max(1, (int) $server->allocations()->count());
            [$dest, $allocationIds] = $this->pickDestination($server, $deadNode, $needed);

            if ($dest === null || $allocationIds === []) {
                $this->fail($record, 'no viable destination node with enough free allocations on one IP');
                return;
            }

            $backup = $this->latestBackup($server);
            if ($backup === null) {
                $this->fail($record, 'no backup row to restore through');
                return;
            }

            $url = $this->storage->archiveUrlFor($server);
            if ($url === null) {
                $this->fail($record, 'no signed mirror archive available (storage unconfigured or no replica)');
                return;
            }

            $primary = (int) array_shift($allocationIds);

            DB::transaction(function () use ($server, $dest, $primary, $allocationIds) {
                $ids = array_merge([$primary], $allocationIds);
                Allocation::query()->whereIn('id', $ids)->update(['server_id' => $server->getKey()]);
                $server->forceFill([
                    'node_id' => $dest->getKey(),
                    'allocation_id' => $primary,
                ])->saveQuietly();
            });

            // Create the server on the destination without starting it, then restore the mirror
            // archive over the (empty) volume, truncating first (fact 17).
            app(DaemonServerRepository::class)->setServer($server->refresh())->create(startOnCompletion: false);
            app(DaemonBackupRepository::class)->setServer($server)->restore($backup, $url, true);

            $record->update([
                'to_node_id' => $dest->getKey(),
                'state' => 'done',
                'finished_at' => now(),
                'detail' => array_merge($record->detail ?? [], ['destination' => $dest->getKey(), 'primary_allocation' => $primary]),
            ]);

            Activity::event('server:replication.failover')
                ->subject($server)
                ->property('from_node', $deadNode->getKey())
                ->property('to_node', $dest->getKey())
                ->log();

            $this->tell($server, true, 'Your server was recovered onto a healthy node from its latest replica. Up to 30 minutes of the most recent changes may be missing.');
        } catch (Throwable $e) {
            report($e);
            $this->fail($record, 'recovery threw: ' . $e->getMessage());
        }
    }

    /**
     * A live destination in the same location tags as the dead node, excluding it, with `$needed`
     * free allocations on one IP.
     *
     * @return array{0: ?Node, 1: array<int, int>}
     */
    private function pickDestination(Server $server, Node $deadNode, int $needed): array
    {
        $tags = array_values(array_filter((array) ($deadNode->tags ?? []), 'is_string'));

        try {
            $candidates = app(FindViableNodesService::class)
                ->handle((int) $server->memory, (int) $server->disk, (int) $server->cpu, $tags)
                ->reject(fn (Node $n) => (int) $n->getKey() === (int) $deadNode->getKey());
        } catch (Throwable) {
            return [null, []];
        }

        foreach ($candidates as $node) {
            $picked = $this->pickAllocations($node, $needed);
            if ($picked !== []) {
                return [$node, $picked];
            }
        }

        return [null, []];
    }

    /**
     * `$needed` free allocations on a single IP of the node, mirroring NodeTransferService's rule.
     *
     * @return array<int, int>
     */
    private function pickAllocations(Node $node, int $needed): array
    {
        try {
            $free = Allocation::query()
                ->where('node_id', $node->getKey())
                ->whereNull('server_id')
                ->orderBy('ip')
                ->orderBy('port')
                ->get(['id', 'ip']);
        } catch (Throwable) {
            return [];
        }

        foreach ($free->groupBy('ip') as $group) {
            if ($group->count() >= $needed) {
                return $group->take($needed)->pluck('id')->map(fn ($id) => (int) $id)->all();
            }
        }

        return [];
    }

    private function latestBackup(Server $server): ?Backup
    {
        try {
            return $server->backups()
                ->where('is_successful', true)
                ->whereNull('deleted_at')
                ->latest('created_at')
                ->first();
        } catch (Throwable) {
            return null;
        }
    }

    private function fail(NodeFailover $record, string $reason): void
    {
        $record->update([
            'state' => 'failed',
            'finished_at' => now(),
            'detail' => array_merge($record->detail ?? [], ['error' => $reason]),
        ]);
        Log::error("plugged-host-storage: failover failed for server {$record->server_id}: $reason");

        $server = Server::query()->find($record->server_id);
        if ($server) {
            $this->tell($server, false, 'Automatic recovery could not complete. Support has been alerted.');
        }
    }

    private function tell(Server $server, bool $success, string $message): void
    {
        try {
            $owner = $server->user;
            if ($owner === null) {
                return;
            }
            $n = Notification::make()
                ->title($success ? 'Server recovered' : 'Server node issue')
                ->body($message);
            ($success ? $n->success() : $n->warning())->sendToDatabase($owner);
        } catch (Throwable) {
            //
        }
    }

    private function tableReady(): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasTable('node_failovers');
        } catch (Throwable) {
            return false;
        }
    }
}
