<?php

namespace PluggedHost\Storage\Services;

use App\Enums\ServerState;
use App\Facades\Activity;
use App\Models\ActivityLog;
use App\Models\Allocation;
use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Node;
use App\Models\Server;
use App\Repositories\Daemon\DaemonBackupRepository;
use App\Repositories\Daemon\DaemonServerRepository;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PluggedHost\Storage\Models\NodeFailover;
use RuntimeException;
use Throwable;

/**
 * Node-failure detection and recovery (WP9).
 *
 * OPERATOR OVERRIDE (recorded 2026-09-18): failover is FULLY AUTOMATIC once a node is offline
 * for ~5 minutes. `failover_mode` defaults to `auto`. Detection is the panel's own minute probe
 * (NodeHealth, the status plugin) reaching the failure threshold. The storage agent's
 * reachability probe is used as corroboration: if the agent can still reach the node, it is by
 * definition still "responding to pings", so we DECLINE to fail over (that is a partition, not a
 * dead node, and acting would create two live copies) — this honours the operator's own
 * "doesn't respond to any pings = offline" definition rather than contradicting it. When the
 * agent is unconfigured/unreachable we proceed on the panel witness alone, as instructed.
 *
 * Recovery is NOT a transfer (fact 16): upstream's transfer copies from the source node, which
 * is dead. Each server goes through a small state machine, one step per minute sweep:
 *
 *   creating   the record is written first (that is the fence: the server is never picked
 *              again), the server is moved in the panel to a node the transfer planner would
 *              move it to (same location, else the same tier anywhere in the deploy pool) with
 *              its ports, and wings creates it there and runs the egg's install, so the runtime
 *              the mirror leaves out (FiveM's alpine/, node_modules, venvs) is laid down fresh.
 *   restoring  once the install has reported back, wings streams the mirror archive from the
 *              storage agent over the installed files (s3 adapter: the only one that downloads;
 *              a temporary backup row carries the uuid wings reports the result against).
 *   done       wings reported a successful restore: the temporary row goes, subdomains are
 *              repointed and the owner is told the new address.
 *
 * Until 2026-10-10 none of this could succeed: the destination had to share a tag with the dead
 * node (nl2 is the only `nl` node), the backup lookup asked for a `deleted_at` column backups no
 * longer have, a `wings`-adapter restore ignores the download URL, and the agent's archive was
 * neither gzip-typed nor sent within wings' 30 s header timeout.
 */
class FailoverService
{
    public const CREATING = 'creating';

    public const RESTORING = 'restoring';

    /** States that mean "this server is taken care of": never start another recovery for it. */
    private const FENCED_STATES = ['running', 'fenced', 'done', self::CREATING, self::RESTORING];

    private const BACKUP_NAME = 'Recovery from the storage mirror';

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

        // Recoveries already under way move on first, whatever the nodes look like now.
        $advanced = $dryRun ? 0 : $this->advance();

        $threshold = max(1, (int) config('plugged-host-storage.failover_after_failures', 5));
        $cap = max(1, (int) config('plugged-host-storage.failover_max_per_run', 5));
        $maxInFlight = max(1, (int) config('plugged-host-storage.failover_max_in_flight', 5));

        $downNodes = Node::query()->get()->filter(fn (Node $n) => $this->looksDown($n, $threshold));
        $inFlight = NodeFailover::query()->whereIn('state', [self::CREATING, self::RESTORING])->count();
        $acted = 0;

        foreach ($downNodes as $node) {
            // Second witness: if the agent can still reach it, it is not offline — skip.
            if ($this->storage->reachableViaAgent($node) === true) {
                Log::info("plugged-host-storage: node {$node->id} unreachable from panel but reachable via agent — treating as partition, not acting.");
                continue;
            }

            $servers = Server::query()->where('node_id', $node->getKey())->orderBy('id')->get();

            foreach ($servers as $server) {
                if ($acted >= $cap || ($mode === 'auto' && $inFlight >= $maxInFlight)) {
                    break 2;
                }

                // Suspended servers stay where they are: wings lifts its suspension at the end
                // of a restore, and nobody is waiting to play on them.
                if ($server->status === ServerState::Suspended || $this->isFenced($server) || $this->failedRecently($server) || $server->transfer !== null) {
                    continue; // taken care of, backing off after a failure, or mid-transfer
                }

                if ($mode === 'notify') {
                    if (!$this->notifiedRecently($server)) {
                        $this->notifyOnly($server, $node);
                        $acted++;
                    }
                    continue;
                }

                if ($dryRun) {
                    $acted++;
                    continue;
                }

                if ($this->start($server, $node)) {
                    $acted++;
                    $inFlight++;
                }
            }
        }

        return [
            'mode' => $mode,
            'acted' => $acted,
            'nodes_down' => $downNodes->count(),
            'in_flight' => $inFlight,
            'advanced' => $advanced,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * Rehearse a recovery on one server whose node is healthy: the same path a dead node's
     * servers take, plus removing the old copy from its (live) node once the restore is done.
     */
    public function drill(Server $server): ?NodeFailover
    {
        if ($this->isFenced($server) || $server->transfer !== null) {
            return null;
        }

        $node = $server->node;
        if ($node instanceof Node) {
            $this->start($server, $node, drill: true);
        }

        return NodeFailover::query()->where('server_id', $server->getKey())->latest('id')->first();
    }

    /**
     * Pick a failed recovery up again where it stopped, for one that already moved the server
     * (it is on the destination, installed, and only the restore is missing).
     */
    public function resume(NodeFailover $record): bool
    {
        $server = Server::query()->find($record->server_id);

        if ($record->state !== 'failed' || $record->to_node_id === null || !$server instanceof Server
            || (int) $server->node_id !== (int) $record->to_node_id) {
            return false;
        }

        $detail = $record->detail ?? [];
        unset($detail['error'], $detail['backup_id'], $detail['restore_failures'], $detail['restore_attempts'], $detail['notified']);
        $record->update(['state' => self::CREATING, 'finished_at' => null, 'detail' => $detail]);

        return true;
    }

    /**
     * Node looks dead to the panel: NodeHealth reports at least `$threshold` consecutive probe
     * failures. Resolved by class-string so status stays independently removable; with no status
     * plugin there is no panel witness and failover cannot fire.
     */
    private function looksDown(Node $node, int $threshold): bool
    {
        $class = 'PluggedHost\\Status\\Services\\NodeHealth';

        if (!class_exists($class)) {
            return false;
        }

        try {
            return app($class)->consecutiveFailures((int) $node->getKey()) >= $threshold;
        } catch (Throwable) {
            return false;
        }
    }

    /** A node that missed even its latest probe is no place to recover a server to. */
    private function missedLastProbe(Node $node): bool
    {
        return $this->looksDown($node, 1);
    }

    private function failedRecently(Server $server): bool
    {
        return NodeFailover::query()
            ->where('server_id', $server->getKey())
            ->where('state', 'failed')
            ->where('finished_at', '>', now()->subMinutes(30))
            ->where('detail', 'like', '%"v":2%') // failures of the pre-2026-10-10 code do not count
            ->exists();
    }

    private function notifiedRecently(Server $server): bool
    {
        return NodeFailover::query()
            ->where('server_id', $server->getKey())
            ->where('state', 'pending')
            ->where('started_at', '>', now()->subDay())
            ->exists();
    }

    private function isFenced(Server $server): bool
    {
        return NodeFailover::query()
            ->where('server_id', $server->getKey())
            ->whereIn('state', self::FENCED_STATES)
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
     * Step one: fence, move the server in the panel, and have wings create and install it on
     * the destination. Nothing in the panel changes unless there is a mirror to restore and a
     * node to put the server on. A create wings refuses is undone, so the server is back on its
     * old node exactly as it was and is tried again after the back-off.
     */
    private function start(Server $server, Node $fromNode, bool $drill = false): bool
    {
        $record = NodeFailover::query()->create([
            'node_id' => $fromNode->getKey(),
            'server_id' => $server->getKey(),
            'from_node_id' => $fromNode->getKey(),
            'state' => self::CREATING,
            'started_at' => now(),
            'detail' => ['mode' => $drill ? 'drill' : 'auto', 'v' => 2],
        ]);

        $info = $this->storage->backupInfo($server->uuid);
        if (!is_array($info) || !($info['exists'] ?? false)) {
            $this->fail($record, $info === null ? 'storage agent unreachable' : 'no completed mirror copy of this server', notify: false);

            return false;
        }

        $planner = $this->planner();
        if ($planner === null) {
            $this->fail($record, 'plugged-host-servers transfer planner unavailable', notify: false);

            return false;
        }

        [$dest, $plan] = $this->pickDestination($server, $fromNode, $planner);
        if ($dest === null || $plan === null) {
            $this->fail($record, 'no viable destination node', notify: false);

            return false;
        }

        $oldIds = $planner->currentAllocations($server)->map(fn (Allocation $a) => (int) $a->getKey())->values()->all();
        $newIds = array_merge([$plan['primary']], $plan['additional']);
        $original = [
            'node_id' => (int) $server->node_id,
            'allocation_id' => (int) $server->allocation_id,
            'status' => $server->status?->value,
            'allocations' => $oldIds,
        ];

        try {
            $portMap = $this->move($server, $dest, $oldIds, $newIds);
        } catch (Throwable $e) {
            report($e);
            $this->fail($record, 'could not move the server in the panel: ' . $e->getMessage(), notify: false);

            return false;
        }

        $record->update([
            'to_node_id' => $dest->getKey(),
            'detail' => array_merge($record->detail ?? [], [
                'destination' => $dest->getKey(),
                'original' => $original,
                'allocations' => $newIds,
                'ports' => $portMap,
                'mirror_mtime' => $info['mtime'] ?? null,
            ]),
        ]);

        try {
            app(DaemonServerRepository::class)->setServer($server->refresh())->create(startOnCompletion: false);
        } catch (Throwable $e) {
            report($e);
            $this->undoMove($server, $original, $newIds);
            $this->fail($record, "wings on {$dest->name} refused the create: " . $e->getMessage(), notify: false);

            return false;
        }

        Log::info("plugged-host-storage: failover of server {$server->id} from node {$fromNode->id} to node {$dest->id} started.");

        return true;
    }

    /**
     * Same location as the old node first, then the same tier anywhere in the deployment pool:
     * a recovery never lands a server somewhere creation would not have put it. The limbo node
     * and nodes that missed their last probe are left out; overloaded ones only when nothing
     * else has room.
     *
     * @return array{0: ?Node, 1: ?array{primary: int, additional: array<int, int>, ports: array<int, int>}}
     */
    private function pickDestination(Server $server, Node $fromNode, object $planner): array
    {
        $catalog = app('PluggedHost\\Servers\\Services\\LocationCatalog');
        $tier = $catalog->nodeTier((array) ($fromNode->tags ?? []));
        $limbo = $this->storage->limboNode();

        $usable = fn (Collection $nodes) => $nodes
            ->reject(fn (Node $n) => (int) $n->getKey() === (int) $fromNode->getKey())
            ->reject(fn (Node $n) => $limbo !== null && (int) $n->getKey() === (int) $limbo->getKey())
            ->filter(fn (Node $n) => $catalog->nodeTier((array) ($n->tags ?? [])) === $tier)
            ->reject(fn (Node $n) => $this->missedLastProbe($n))
            ->values();

        $location = $planner->locationOf($fromNode);
        $tiers = [];

        try {
            if ($location !== null) {
                $tiers[] = $usable($planner->destinations($server, $location));
            }
            $tiers[] = $usable($planner->destinations($server, null));

            // Overloaded nodes take no new servers, but a recovery that finds nothing else
            // still goes there rather than nowhere.
            if ($location !== null) {
                $tiers[] = $usable($planner->destinations($server, $location, [], null, true));
            }
            $tiers[] = $usable($planner->destinations($server, null, [], null, true));
        } catch (Throwable $e) {
            report($e);

            return [null, null];
        }

        foreach ($tiers as $candidates) {
            foreach ($candidates as $node) {
                $plan = $planner->allocationsOn($server, $node);
                if ($plan !== null) {
                    return [$node, $plan];
                }
            }
        }

        return [null, null];
    }

    /**
     * Hand the server its new node and ports in one transaction. The old ports are freed the way
     * a finished transfer frees them; lock flags and notes follow index for index, and an egg
     * variable holding an old port number (txAdmin, query port) follows it to the new one.
     *
     * @param  array<int, int>  $oldIds  primary first
     * @param  array<int, int>  $newIds  primary first
     * @return array<int, int> old port => new port
     */
    private function move(Server $server, Node $dest, array $oldIds, array $newIds): array
    {
        return DB::transaction(function () use ($server, $dest, $oldIds, $newIds) {
            $oldRows = Allocation::withoutGlobalScopes()->whereIn('id', $oldIds)->get()->keyBy('id');
            $claimed = Allocation::withoutGlobalScopes()->whereIn('id', $newIds)->whereNull('server_id')->lockForUpdate()->get()->keyBy('id');

            if ($claimed->count() !== count($newIds)) {
                throw new RuntimeException('destination ports were taken meanwhile');
            }

            $portMap = [];
            foreach ($newIds as $index => $id) {
                $before = $oldRows->get($oldIds[$index] ?? 0);
                Allocation::withoutGlobalScopes()->whereKey($id)->update([
                    'server_id' => $server->getKey(),
                    'is_locked' => $before instanceof Allocation ? (bool) $before->is_locked : $index === 0,
                    'notes' => $before?->notes,
                ]);

                if ($before instanceof Allocation) {
                    $portMap[(int) $before->port] = (int) $claimed->get($id)->port;
                }
            }

            // Core's triggers: the server must point at its new primary before the old one is freed.
            $server->forceFill([
                'node_id' => $dest->getKey(),
                'allocation_id' => $newIds[0],
                'status' => ServerState::Installing,
            ])->saveQuietly();

            Allocation::withoutGlobalScopes()->whereIn('id', $oldIds)->where('server_id', $server->getKey())
                ->update(['server_id' => null, 'is_locked' => false, 'notes' => null]);

            $this->followPorts($server, array_filter($portMap, fn (int $to, int $from) => $to !== $from, ARRAY_FILTER_USE_BOTH));

            return $portMap;
        });
    }

    /**
     * @param  array{node_id: int, allocation_id: int, status: ?string, allocations: array<int, int>}  $original
     * @param  array<int, int>  $newIds
     */
    private function undoMove(Server $server, array $original, array $newIds): void
    {
        try {
            DB::transaction(function () use ($server, $original, $newIds) {
                Allocation::withoutGlobalScopes()->whereIn('id', $original['allocations'])->whereNull('server_id')
                    ->update(['server_id' => $server->getKey()]);

                $server->forceFill([
                    'node_id' => $original['node_id'],
                    'allocation_id' => $original['allocation_id'],
                    'status' => $original['status'],
                ])->saveQuietly();

                Allocation::withoutGlobalScopes()->whereIn('id', $newIds)->where('server_id', $server->getKey())
                    ->update(['server_id' => null, 'is_locked' => false, 'notes' => null]);
            });
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @param array<int, int> $portMap old port => new port */
    private function followPorts(Server $server, array $portMap): void
    {
        if ($portMap === []) {
            return;
        }

        foreach ($server->serverVariables()->with('variable')->get() as $variable) {
            $name = strtoupper((string) $variable->variable?->env_variable);
            $value = trim((string) $variable->variable_value);

            if (str_contains($name, 'PORT') && ctype_digit($value) && isset($portMap[(int) $value])) {
                $variable->forceFill(['variable_value' => (string) $portMap[(int) $value]])->save();
            }
        }
    }

    /**
     * Move every recovery under way one step on. Returns how many changed state.
     */
    private function advance(): int
    {
        $changed = 0;

        foreach (NodeFailover::query()->whereIn('state', [self::CREATING, self::RESTORING])->orderBy('id')->get() as $record) {
            try {
                $before = $record->state;
                $this->step($record);
                $changed += (int) ($record->refresh()->state !== $before);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $changed;
    }

    private function step(NodeFailover $record): void
    {
        $server = Server::query()->find($record->server_id);
        $detail = $record->detail ?? [];

        if (!$server instanceof Server) {
            $this->fail($record, 'server was deleted during recovery', notify: false);

            return;
        }

        if ((int) $server->node_id !== (int) $record->to_node_id) {
            $this->fail($record, 'server was moved by someone else during recovery', notify: false);

            return;
        }

        $age = $record->started_at ? $record->started_at->diffInMinutes(now()) : 0;

        if ($record->state === self::CREATING) {
            // The install callback clears `installing`. A wings that never reports back gets
            // 90 minutes; then the mirror goes on anyway — the data matters more than the egg.
            if ($server->status === ServerState::Installing && $age < 90) {
                return;
            }

            $this->beginRestore($record, $server);

            return;
        }

        // RESTORING: wings' restore callback clears `restoring_backup`.
        if ($server->status === ServerState::RestoringBackup) {
            $since = isset($detail['restore_started_at']) ? now()->getTimestamp() - (int) $detail['restore_started_at'] : 0;
            if ($since > 12 * 3600) {
                $this->dropRecoveryBackup($detail);
                $server->forceFill(['status' => null])->saveQuietly();
                $this->fail($record, 'restore did not finish within 12 hours');
            }

            return;
        }

        $succeeded = $this->restoreSucceeded((int) ($detail['backup_id'] ?? 0));
        $this->dropRecoveryBackup($detail);

        if ($succeeded) {
            $this->finish($record, $server);

            return;
        }

        $failures = (int) ($detail['restore_failures'] ?? 0) + 1;
        if ($failures >= 3) {
            $record->update(['detail' => array_merge($detail, ['restore_failures' => $failures])]);
            $this->fail($record, 'wings reported the restore as failed ' . $failures . ' times');

            return;
        }

        // Try the restore again on the next sweep.
        $record->update([
            'state' => self::CREATING,
            'detail' => array_merge($detail, ['backup_id' => null, 'restore_failures' => $failures, 'last_error' => 'wings reported the restore as failed']),
        ]);
    }

    /**
     * Ask wings to stream the mirror over the freshly installed server. `stream=1` makes the
     * agent answer at once while tar runs (wings waits 30 s for headers); the temporary backup
     * row is what wings' completion callback is matched against.
     */
    private function beginRestore(NodeFailover $record, Server $server): void
    {
        $detail = $record->detail ?? [];
        $attempts = (int) ($detail['restore_attempts'] ?? 0) + 1;

        $url = $this->storage->archiveUrlFor($server);
        if ($url === null) {
            $this->fail($record, 'storage agent unconfigured');

            return;
        }

        $backup = null;

        try {
            $host = $server->node->backupHosts()->first() ?? BackupHost::doesntHave('nodes')->firstOrFail();
            $backup = Backup::query()->create([
                'server_id' => $server->getKey(),
                'uuid' => Str::uuid()->toString(),
                'name' => self::BACKUP_NAME,
                'ignored_files' => [],
                'backup_host_id' => $host->getKey(),
                'is_locked' => true,
                'is_scheduled' => false,
            ]);

            $server->forceFill(['status' => ServerState::RestoringBackup])->saveQuietly();

            app(DaemonBackupRepository::class)->setServer($server)->getHttpClient()->post(
                "/api/servers/{$server->uuid}/backup/{$backup->uuid}/restore",
                [
                    'adapter' => 's3',
                    'truncate_directory' => false,
                    'download_url' => $url . '&stream=1',
                ],
            );
        } catch (Throwable $e) {
            if ($backup instanceof Backup) {
                Backup::query()->whereKey($backup->getKey())->delete();
            }
            $server->forceFill(['status' => null])->saveQuietly();

            $detail = array_merge($detail, ['restore_attempts' => $attempts, 'last_error' => Str::limit($e->getMessage(), 300)]);
            if ($attempts >= 30) {
                $record->update(['detail' => $detail]);
                $this->fail($record, 'wings would not start the restore: ' . Str::limit($e->getMessage(), 200));

                return;
            }

            // The agent answers 503 while it is busy with other archives: try again next minute.
            $record->update(['detail' => $detail]);

            return;
        }

        $record->update([
            'state' => self::RESTORING,
            'detail' => array_merge($detail, [
                'backup_id' => $backup->getKey(),
                'restore_attempts' => $attempts,
                'restore_started_at' => now()->getTimestamp(),
            ]),
        ]);
    }

    private function restoreSucceeded(int $backupId): bool
    {
        if ($backupId <= 0) {
            return false;
        }

        return ActivityLog::query()
            ->where('event', 'server:backup.restore-complete')
            ->whereIn('id', DB::table('activity_log_subjects')->select('activity_log_id')->where('subject_type', 'backup')->where('subject_id', $backupId))
            ->exists();
    }

    /** @param array<string, mixed> $detail */
    private function dropRecoveryBackup(array $detail): void
    {
        $id = (int) ($detail['backup_id'] ?? 0);
        if ($id > 0) {
            Backup::query()->whereKey($id)->where('name', self::BACKUP_NAME)->delete();
        }
    }

    private function finish(NodeFailover $record, Server $server): void
    {
        $detail = $record->detail ?? [];
        $server->refresh();

        cache()->forget("servers.{$server->uuid}.status");
        cache()->forget("servers.{$server->uuid}.resources");
        $this->refreshSubdomains($server);

        if (($detail['mode'] ?? null) === 'drill') {
            $this->removeOldCopy($server, (int) $record->from_node_id);
        }

        $primary = Allocation::withoutGlobalScopes()->find($server->allocation_id);
        $address = $primary instanceof Allocation
            ? ($primary->ip_alias ?: $server->node?->fqdn ?: $primary->ip) . ':' . $primary->port
            : null;

        $record->update([
            'state' => 'done',
            'finished_at' => now(),
            'detail' => array_merge($detail, ['address' => $address]),
        ]);

        Activity::event('server:replication.failover')
            ->subject($server)
            ->property('from_node', $record->from_node_id)
            ->property('to_node', $record->to_node_id)
            ->log();

        $since = isset($detail['mirror_mtime']) ? ' Files are as they were at ' . \Carbon\Carbon::parse($detail['mirror_mtime'])->format('Y-m-d H:i') . ' UTC.' : '';
        $this->tell($server, true, 'Your server\'s node went offline, so it was recovered onto a healthy node from its latest copy.'
            . ($address ? " Its new address is {$address}." : '') . $since . ' Start it whenever you are ready.');

        Log::info("plugged-host-storage: failover of server {$server->id} to node {$record->to_node_id} done.");
    }

    private function refreshSubdomains(Server $server): void
    {
        $class = 'Boy132\\Subdomains\\Models\\Subdomain';

        if (!class_exists($class)) {
            return;
        }

        try {
            foreach ($class::query()->where('server_id', $server->getKey())->get() as $subdomain) {
                try {
                    $subdomain->setRelation('server', $server);
                    $subdomain->upsertOnCloudflare();
                } catch (Throwable $e) {
                    Log::warning('plugged-host-storage: subdomain update after failover failed', ['server' => $server->getKey(), 'error' => $e->getMessage()]);
                }
            }
        } catch (Throwable) {
            //
        }
    }

    /** Drill only: the old node is alive, so its copy is deleted once the new one is whole. */
    private function removeOldCopy(Server $server, int $fromNodeId): void
    {
        $node = Node::query()->find($fromNodeId);
        if (!$node instanceof Node) {
            return;
        }

        try {
            app(DaemonServerRepository::class)->setServer($server)->setNode($node)->delete();
        } catch (Throwable $e) {
            Log::warning("plugged-host-storage: drill could not remove the old copy of server {$server->id} on node {$fromNodeId}: " . $e->getMessage());
        }
    }

    private function planner(): ?object
    {
        $class = 'PluggedHost\\Servers\\Services\\Transfers\\TransferPlanner';

        try {
            return class_exists($class) ? app($class) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function fail(NodeFailover $record, string $reason, bool $notify = true): void
    {
        $record->update([
            'state' => 'failed',
            'finished_at' => now(),
            'detail' => array_merge($record->detail ?? [], ['error' => $reason]),
        ]);
        Log::error("plugged-host-storage: failover failed for server {$record->server_id}: $reason");

        // Failures before anything changed are retried after a back-off and tell nobody. A
        // recovery that moved the server and then failed is worth one message a day at most.
        $notifiedBefore = NodeFailover::query()
            ->where('server_id', $record->server_id)
            ->where('state', 'failed')
            ->whereKeyNot($record->getKey())
            ->where('finished_at', '>', now()->subDay())
            ->whereJsonContains('detail->notified', true)
            ->exists();

        $server = Server::query()->find($record->server_id);
        if ($notify && $server && !$notifiedBefore) {
            $record->update(['detail' => array_merge($record->detail ?? [], ['notified' => true])]);
            $this->tell($server, false, 'Your server\'s node went offline and recovering it onto a healthy node did not complete. Support has been alerted.');
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
