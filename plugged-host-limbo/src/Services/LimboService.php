<?php

namespace PluggedHost\Limbo\Services;

use App\Enums\ServerState;
use App\Facades\Activity;
use App\Models\Allocation;
use App\Models\Node;
use App\Models\Server;
use App\Models\ServerTransfer;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Services\Servers\TransferServerService;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Limbo\Filament\Server\Pages\RestoreFromLimboPage;
use PluggedHost\Limbo\Models\ServerLimboState;
use Throwable;

/**
 * Idle-server hibernation (WP5).
 *
 * Everything degrades to a logged-once no-op when the server_limbo_states table is missing
 * (the migration is handed to the operator, like TokenService's own tables), and the whole
 * feature is dark unless LIMBO_ENABLED is true.
 *
 * The idle definition has ONE seam — lastActivityAt() — so switching from panel-activity to the
 * daemon-sampling variant later is a one-method change, not a redesign.
 *
 * Marker states: stopped → hibernating → limbo → restoring → restored, plus failed (a move to
 * the limbo node that did not land; retried later). A wings transfer is asynchronous, so the
 * two moving states are only settled by reconcile(), once the panel's transfer row has an
 * outcome: nothing is suspended, unsuspended or marked restored on the strength of a transfer
 * merely having been asked for.
 */
class LimboService
{
    /** Rows reconcile() looks at. */
    private const UNSETTLED = ['hibernating', 'limbo', 'restoring'];

    private static bool $missingTableLogged = false;

    public function enabled(): bool
    {
        return (bool) config('plugged-host-limbo.enabled', false);
    }

    public function tableReady(): bool
    {
        try {
            if (Schema::hasTable('server_limbo_states')) {
                return true;
            }
        } catch (Throwable) {
            //
        }

        if (!self::$missingTableLogged) {
            self::$missingTableLogged = true;
            Log::warning('plugged-host-limbo: server_limbo_states table missing; limbo inert.');
        }

        return false;
    }

    /**
     * The single idle seam: most recent panel activity for a server, falling back to when it was
     * created. Replace the body with a daemon-sampling read to change the whole policy.
     */
    public function lastActivityAt(Server $server): ?CarbonInterface
    {
        try {
            $ts = DB::table('activity_logs')
                ->join('activity_log_subjects', 'activity_logs.id', '=', 'activity_log_subjects.activity_log_id')
                ->where('activity_log_subjects.subject_type', $server->getMorphClass())
                ->where('activity_log_subjects.subject_id', $server->getKey())
                ->max('activity_logs.timestamp');

            if ($ts !== null) {
                return Carbon::parse($ts);
            }
        } catch (Throwable) {
            //
        }

        return $server->created_at ? Carbon::parse($server->created_at) : null;
    }

    /** Hours since last activity, or null when it cannot be determined. */
    public function idleHours(Server $server): ?float
    {
        $last = $this->lastActivityAt($server);

        return $last === null ? null : $last->diffInMinutes(now()) / 60;
    }

    public function state(Server $server): ?ServerLimboState
    {
        if (!$this->tableReady()) {
            return null;
        }

        return ServerLimboState::query()->where('server_id', $server->getKey())->first();
    }

    /**
     * Parked: moved to the limbo node and waiting there for its owner. The only state the
     * restore button applies to.
     */
    public function isParked(Server $server, ?ServerLimboState $state = null): bool
    {
        $state ??= $this->state($server);

        return $state?->state === 'limbo' && $this->onLimboNode($server, $state) && $server->transfer === null;
    }

    /**
     * The PowerActionInterceptor seam (ads → limbo, first in the chain). Returns the restore
     * page URL when the server is parked in limbo, else null (start proceeds).
     */
    public function interceptStart(mixed $user, Server $server, string $action): ?string
    {
        if (!$this->tableReady()) {
            return null;
        }

        $state = $this->state($server);

        if ($state === null) {
            return null;
        }

        // A container that was only stopped (or whose move to limbo failed) is still on its
        // own node and starts normally; clear the marker so the sweep does not immediately
        // re-stop it — the click is itself activity.
        if (in_array($state->state, ['stopped', 'failed'], true)) {
            $state->delete();

            return null;
        }

        if (in_array($state->state, ['limbo', 'restoring'], true)) {
            return RestoreFromLimboPage::getUrl(panel: 'server', tenant: $server);
        }

        return null;
    }

    /**
     * How many new moves to the limbo node may start now: the in-flight cap, unless the limbo
     * node is refusing work. When the last three moves there all failed, none start for an hour
     * after the latest failure, then one at a time as a probe until one lands — so a broken limbo
     * node costs one server's exempt files an hour (see ExemptionPruner), not a sweep's worth.
     */
    public function moveBudget(int $cap): int
    {
        $inFlight = ServerLimboState::query()->where('state', 'hibernating')->count();
        $budget = max(0, $cap - $inFlight);
        $node = $this->limboNode();

        if ($budget === 0 || $node === null) {
            return $budget;
        }

        $recent = ServerTransfer::query()
            ->where('new_node', $node->getKey())
            ->whereNotNull('successful')
            ->orderByDesc('id')
            ->limit(3)
            ->get(['successful', 'updated_at']);

        if ($recent->count() < 3 || $recent->contains(fn (ServerTransfer $transfer) => (bool) $transfer->successful)) {
            return $budget;
        }

        if ($recent->first()->updated_at?->gt(now()->subHour())) {
            return 0;
        }

        return $inFlight > 0 ? 0 : 1;
    }

    /** Cost to restore, per the ransom window measured from limbo_at. */
    public function cost(ServerLimboState $state): int
    {
        $window = (int) config('plugged-host-limbo.cost_window_hours', 72);
        $early = (int) config('plugged-host-limbo.cost_early', 5);
        $late = (int) config('plugged-host-limbo.cost_late', 10);

        if ($state->limbo_at !== null && now()->greaterThan($state->limbo_at->copy()->addHours($window))) {
            return $late;
        }

        return $early;
    }

    /**
     * Stop an idle container (48h). Records a `stopped` marker and notifies the owner. Reversible
     * for free — the server is still on its own node.
     */
    public function stop(Server $server): bool
    {
        if (!$this->tableReady()) {
            return false;
        }

        try {
            // Stop first, record second: a stop wings refused must not leave a running server
            // marked as stopped. (DaemonPowerRepository does not exist in this Pelican; power
            // lives on DaemonServerRepository and throws on a refused call.)
            app(DaemonServerRepository::class)->setServer($server)->power('stop');

            ServerLimboState::query()->updateOrCreate(
                ['server_id' => $server->getKey()],
                ['state' => 'stopped', 'idle_since' => $this->lastActivityAt($server), 'stopped_at' => now()],
            );

            Activity::event('server:limbo.stop')->subject($server)->log();

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Start moving a stopped, idle server (72h) to the limbo node.
     *
     * Reuses upstream TransferServerService (the limbo node is a live wings node, so a real
     * transfer works — unlike failover's dead-node case). Origin node + allocations are recorded
     * first so restore can return it to the same node and ports.
     *
     * The server is NOT suspended here. It is parked by reconcile() once wings reports the
     * transfer as successful; a transfer that fails leaves it untouched on its own node.
     */
    public function hibernate(Server $server): bool
    {
        if (!$this->tableReady()) {
            return false;
        }

        $limboNode = $this->limboNode();

        if ($limboNode === null) {
            Log::warning('plugged-host-limbo: no limbo node found (tag ' . config('plugged-host-limbo.node_tag') . '); skipping hibernate.');

            return false;
        }

        if ((int) $server->node_id === (int) $limboNode->getKey() || $server->transfer !== null) {
            return false;
        }

        // The marker says stopped, but anything can have started it since (a schedule, a
        // start from outside the panel). Never carry a running server off to limbo; one that
        // is running loses its marker, so the sweep treats it as a fresh idle server.
        $offline = $this->isOffline($server);

        if ($offline !== true) {
            if ($offline === false) {
                ServerLimboState::query()->where('server_id', $server->getKey())->delete();
            }

            return false;
        }

        $needed = max(1, (int) $server->allocations()->count());
        $allocationIds = $this->pickAllocations($limboNode, $needed);

        if ($allocationIds === []) {
            Log::warning("plugged-host-limbo: limbo node has no free allocations for server {$server->id}.");

            return false;
        }

        $this->pruneExemptions($server);

        $state = ServerLimboState::query()->updateOrCreate(
            ['server_id' => $server->getKey()],
            [
                'state' => 'hibernating',
                'limbo_at' => null,
                'origin_node_id' => $server->node_id,
                'origin_allocation_id' => $server->allocation_id,
                'origin_allocations' => $server->allocations()->pluck('id')->map(fn ($id) => (int) $id)->all(),
            ],
        );

        $primary = (int) array_shift($allocationIds);

        if ($this->transfer($server, $limboNode, $primary, $allocationIds)) {
            return true;
        }

        // Nothing is in flight: the server never left. Back off (see reconcile()).
        $state->update(['state' => 'failed']);

        return false;
    }

    /**
     * The storage exemptions (node_modules, caches, logs, …) are not worth carrying to the
     * limbo node, and a wings transfer cannot skip them, so they are deleted first. A failure
     * here only means a bigger move.
     */
    private function pruneExemptions(Server $server): void
    {
        try {
            $paths = app(ExemptionPruner::class)->prune($server);
        } catch (Throwable $e) {
            report($e);

            return;
        }

        if ($paths !== []) {
            Log::info(sprintf('plugged-host-limbo: removed %d exempt paths from server %d before the move.', count($paths), $server->id), ['paths' => array_slice($paths, 0, 50)]);
        }
    }

    /**
     * Restore a parked server: charge the ransom, unsuspend, and move it back to its origin
     * node (its old ports when they are still free), else to a node sharing the origin's tags.
     * The transfer is settled by reconcile(): a failed one is refunded and re-parked.
     * Owner-only is enforced by the page and the API route.
     *
     * @return array{ok: bool, reason?: string, cost?: int}
     */
    public function restore(mixed $user, Server $server, ?string $location = null): array
    {
        return $this->withoutTenant(fn (): array => $this->restoreUnscoped($user, $server, $location));
    }

    /**
     * Whether the server can go back where it came from (its origin node, or a node with the
     * same tags). When it cannot, the owner picks one of restoreLocations().
     */
    public function canRestoreHome(Server $server): bool
    {
        return $this->withoutTenant(function () use ($server): bool {
            $state = $this->state($server);

            return $state !== null && $this->restoreDestination($server, $state) !== null;
        });
    }

    /**
     * The owner's unlocked locations with room for this server, from plugged-host-servers'
     * transfer planner (the same list the Transfer page offers).
     *
     * @return array<int, array<string, mixed>>
     */
    public function restoreLocations(Server $server): array
    {
        $planner = $this->planner();

        if ($planner === null) {
            return [];
        }

        return $this->withoutTenant(function () use ($planner, $server): array {
            try {
                return $planner->locations($server)
                    ->filter(fn (array $location): bool => ($location['available'] ?? false)
                        && ($location['unlocked'] ?? true)
                        && !($location['current'] ?? false))
                    ->values()
                    ->all();
            } catch (Throwable $e) {
                report($e);

                return [];
            }
        });
    }

    /**
     * On the server panel, Filament scopes every query on a server resource's model
     * (allocations, backups, …) to the open server: free ports on other nodes, and upstream's
     * own port reservation for the transfer, would come back empty. Run without that scope
     * and put the tenant back afterwards.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withoutTenant(callable $callback): mixed
    {
        $tenant = Filament::getTenant();
        Filament::setTenant(null, isQuiet: true);

        try {
            return $callback();
        } finally {
            Filament::setTenant($tenant, isQuiet: true);
        }
    }

    /** @return array{ok: bool, reason?: string, cost?: int} */
    private function restoreUnscoped(mixed $user, Server $server, ?string $location): array
    {
        if (!$this->tableReady()) {
            return ['ok' => false, 'reason' => 'unavailable'];
        }

        $server->refresh();
        $state = $this->state($server);

        if ($state === null || !$this->isParked($server, $state)) {
            return ['ok' => false, 'reason' => 'not_in_limbo'];
        }

        $cost = $this->cost($state);
        $destination = $this->restoreDestination($server, $state);

        if ($destination === null && $location !== null) {
            $destination = $this->locationDestination($server, $location);

            if ($destination === null) {
                return ['ok' => false, 'reason' => 'location_full', 'cost' => $cost];
            }
        }

        if ($destination === null) {
            return ['ok' => false, 'reason' => 'no_destination', 'cost' => $cost];
        }

        // Charge first, via economy by class-string. spend() returns false (not throws) on an
        // insufficient balance and re-reads under a lock, so a stale button cannot overdraw.
        if (!$this->spendTokens($user, $cost)) {
            return ['ok' => false, 'reason' => 'insufficient_tokens', 'cost' => $cost];
        }

        $state->update(['state' => 'restoring', 'restore_cost' => $cost]);

        // Unsuspend before the move so the destination wings builds it as a normal server and
        // it can be started the moment the transfer lands.
        $this->setSuspended($server, false);

        [$node, $primary, $additional] = $destination;

        if (!$this->transfer($server, $node, $primary, $additional)) {
            $this->refundTokens($user, $cost);
            $this->setSuspended($server, true);
            $state->update(['state' => 'limbo', 'restore_cost' => null]);

            return ['ok' => false, 'reason' => 'transfer_failed', 'cost' => $cost];
        }

        Activity::event('server:limbo.restore')->subject($server)->property('cost', $cost)->log();
        $this->watchTransfer($server);

        return ['ok' => true, 'cost' => $cost];
    }

    /**
     * Settle every move once wings has an outcome for it, and unpark servers that never left.
     * Run by the sweep every minute, whether or not the sweep itself is enabled, so a move in
     * flight when limbo is switched off still lands (or is undone) properly.
     *
     * @return array<int, string> one line per action taken (or, with $dryRun, that would be)
     */
    public function reconcile(bool $dryRun = false): array
    {
        if (!$this->tableReady()) {
            return [];
        }

        $lines = [];

        $rows = ServerLimboState::query()
            ->whereIn('state', self::UNSETTLED)
            // A row written this minute may be waiting for its transfer to be created.
            ->where('updated_at', '<', now()->subMinute())
            ->get();

        foreach ($rows as $state) {
            try {
                $server = Server::query()->find($state->server_id);

                if (!$server instanceof Server || $server->transfer !== null) {
                    continue;
                }

                $line = match ($state->state) {
                    'hibernating', 'limbo' => $this->settleHibernation($server, $state, $dryRun),
                    'restoring' => $this->settleRestore($server, $state, $dryRun),
                    default => null,
                };

                if ($line !== null) {
                    $lines[] = sprintf('%-9s %s (#%d)', $line, $server->uuid, $server->id);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $lines;
    }

    private function settleHibernation(Server $server, ServerLimboState $state, bool $dryRun): ?string
    {
        if ($this->onLimboNode($server, $state)) {
            if ($state->state === 'limbo' && $server->isSuspended()) {
                return null;
            }

            if (!$dryRun) {
                // Park it: Suspended makes isInConflictState() true, greys the server out and
                // refuses power actions with no new code.
                $this->setSuspended($server, true);

                if ($state->state === 'hibernating') {
                    $state->update(['state' => 'limbo', 'limbo_at' => now()]);
                    Activity::event('server:limbo.hibernate')->subject($server)->property('limbo_node', $server->node_id)->log();
                }
            }

            return 'PARKED';
        }

        // Still on its own node: the transfer failed. Hand the server back as it was — the
        // pre-fix code suspended it the moment the transfer was asked for.
        if (!$dryRun) {
            if ($server->isSuspended()) {
                $this->setSuspended($server, false);
            }

            $state->update(['state' => 'failed', 'limbo_at' => null]);
            Log::warning("plugged-host-limbo: move of server {$server->id} to the limbo node failed; left on node {$server->node_id}.");
        }

        return 'UNPARKED';
    }

    private function settleRestore(Server $server, ServerLimboState $state, bool $dryRun): string
    {
        if (!$this->onLimboNode($server, $state)) {
            if (!$dryRun) {
                if ($server->isSuspended()) {
                    $this->setSuspended($server, false);
                }

                $state->update(['state' => 'restored', 'restored_at' => now()]);
            }

            return 'RESTORED';
        }

        // The move back failed: refund the owner (who paid — restore is owner-only) and re-park.
        if (!$dryRun) {
            $cost = (int) $state->restore_cost;
            $this->refundTokens($server->user, $cost);
            $this->setSuspended($server, true);
            $state->update(['state' => 'limbo', 'restore_cost' => null]);
            Log::warning("plugged-host-limbo: restore of server {$server->id} failed; refunded {$cost} tokens.");
        }

        return 'REFUNDED';
    }

    /**
     * Whether the server sits on the limbo node rather than where it came from. The recorded
     * origin decides first, so a renamed limbo tag cannot make every parked server look lost.
     */
    private function onLimboNode(Server $server, ServerLimboState $state): bool
    {
        if ($state->origin_node_id !== null && (int) $server->node_id === (int) $state->origin_node_id) {
            return false;
        }

        $limbo = $this->limboNode();

        return $limbo === null || (int) $server->node_id === (int) $limbo->getKey();
    }

    /**
     * Where a restore goes: the origin node with the server's original allocations when those
     * are all still free (same address as before hibernation), else free ones on the origin,
     * else a node that shares the origin's tags.
     *
     * @return array{0: Node, 1: int, 2: array<int, int>}|null
     */
    private function restoreDestination(Server $server, ServerLimboState $state): ?array
    {
        $origin = $this->originNode($state);
        $limbo = $this->limboNode();
        $needed = max(1, count($state->origin_allocations ?? []));

        $nodes = collect($origin ? [$origin] : []);

        // Same tags exactly: same location and tier, so a restore never upgrades a free server
        // onto a paid node or moves it to another country.
        $tags = $origin ? $this->tagSet($origin) : [];

        if ($tags !== []) {
            $nodes = $nodes->merge(
                Node::query()->get()->filter(fn (Node $node) => $node->getKey() !== $origin->getKey()
                    && $node->getKey() !== $limbo?->getKey()
                    && $this->tagSet($node) === $tags)
            );
        }

        foreach ($nodes as $node) {
            if ($node->isUnderMaintenance() || !$node->isViable((int) $server->memory, (int) $server->disk, (int) $server->cpu)) {
                continue;
            }

            $ids = $node->is($origin) ? $this->originalAllocations($state) : [];
            $ids = $ids !== [] ? $ids : $this->pickAllocations($node, $needed);

            if ($ids !== []) {
                return [$node, (int) array_shift($ids), $ids];
            }
        }

        return null;
    }

    /**
     * A node in $location with room and ports for the server, when $location is one of the
     * owner's (the planner creates missing ports, keeping the numbers where they are free).
     *
     * @return array{0: Node, 1: int, 2: array<int, int>}|null
     */
    private function locationDestination(Server $server, string $location): ?array
    {
        $planner = $this->planner();

        if ($planner === null || !in_array($location, array_column($this->restoreLocations($server), 'code'), true)) {
            return null;
        }

        try {
            foreach ($planner->destinations($server, $location) as $node) {
                $allocations = $planner->allocationsOn($server, $node);

                if ($allocations !== null) {
                    return [$node, (int) $allocations['primary'], array_map(intval(...), $allocations['additional'])];
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        return null;
    }

    /** plugged-host-servers' transfer planner (locations, nodes, ports), when installed. */
    private function planner(): ?object
    {
        $planner = 'PluggedHost\\Servers\\Services\\Transfers\\TransferPlanner';

        return Plugins::available($planner) ? app($planner) : null;
    }

    /** @return array<int, string> */
    private function tagSet(Node $node): array
    {
        $tags = array_values(array_unique(array_filter(array_map(strval(...), (array) $node->tags))));
        sort($tags);

        return $tags;
    }

    /** @return array<int, int> the original allocations, primary first, if every one is still free */
    private function originalAllocations(ServerLimboState $state): array
    {
        $ids = array_map(intval(...), $state->origin_allocations ?? []);

        if ($ids === [] || $state->origin_node_id === null) {
            return [];
        }

        $free = Allocation::query()
            ->whereIn('id', $ids)
            ->where('node_id', $state->origin_node_id)
            ->whereNull('server_id')
            ->count();

        if ($free !== count($ids)) {
            return [];
        }

        $primary = (int) $state->origin_allocation_id;

        return in_array($primary, $ids, true)
            ? array_values(array_merge([$primary], array_diff($ids, [$primary])))
            : $ids;
    }

    /**
     * Ask for the transfer. True when one is now in flight — also when the notify call to
     * wings threw after the transfer row was written, since wings may well have taken it;
     * reconcile() settles it either way. False when nothing is moving (node not viable, or
     * refused before a row existed).
     */
    private function transfer(Server $server, Node $node, int $primary, array $additional): bool
    {
        try {
            if (app(TransferServerService::class)->handle($server, (int) $node->getKey(), $primary, $additional)) {
                return true;
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $server->refresh()->transfer !== null;
    }

    /**
     * Park or unpark, and tell wings (as upstream's SuspensionService does, minus its admin UI
     * notifications). A wings that cannot be reached picks the state up on its next sync.
     */
    private function setSuspended(Server $server, bool $suspended): void
    {
        $server->forceFill(['status' => $suspended ? ServerState::Suspended->value : null])->saveQuietly();

        try {
            app(DaemonServerRepository::class)->setServer($server)->sync();
        } catch (Throwable $e) {
            Log::info("plugged-host-limbo: wings sync for server {$server->id} failed: {$e->getMessage()}");
        }
    }

    /** Whether wings reports the container offline; null when wings cannot be asked. */
    private function isOffline(Server $server): ?bool
    {
        try {
            $details = app(DaemonServerRepository::class)->setServer($server)->getDetails();
        } catch (Throwable) {
            return null;
        }

        $state = $details['state'] ?? null;

        return is_string($state) ? $state === 'offline' : null;
    }

    // --- seams to storage + economy -----------------------------------------

    /**
     * Hand the move to plugged-host-servers' transfer watcher now, so the owner sees its progress
     * on the restore page (the watcher only adopts other plugins' transfers after a minute, and a
     * restore is often done by then).
     */
    private function watchTransfer(Server $server): void
    {
        $service = 'PluggedHost\\Servers\\Services\\Transfers\\TransferService';
        $watcher = 'PluggedHost\\Servers\\Services\\Transfers\\TransferWatcher';

        if (!Plugins::available($service) || !method_exists($service, 'adopt')) {
            return;
        }

        try {
            $transfer = $server->refresh()->transfer;

            if ($transfer !== null && app($service)->adopt($transfer) !== null && Plugins::available($watcher)) {
                $watcher::ensureRunning();
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function limboNode(): ?Node
    {
        $storage = 'PluggedHost\\Storage\\Services\\StorageService';

        if (Plugins::available($storage)) {
            try {
                return app($storage)->limboNode();
            } catch (Throwable) {
                //
            }
        }

        return null;
    }

    private function originNode(ServerLimboState $state): ?Node
    {
        return $state->origin_node_id ? Node::query()->find($state->origin_node_id) : null;
    }

    private function spendTokens(mixed $user, int $cost): bool
    {
        $service = 'PluggedHost\\Economy\\Services\\TokenService';

        if (!Plugins::available($service)) {
            return false;
        }

        try {
            return (bool) app($service)->spend($user, $cost, 'limbo_restore', 'Limbo restore', ['reason' => 'limbo restore']);
        } catch (Throwable) {
            return false;
        }
    }

    private function refundTokens(mixed $user, int $cost): void
    {
        $service = 'PluggedHost\\Economy\\Services\\TokenService';

        if ($cost <= 0 || !Plugins::available($service)) {
            return;
        }

        try {
            app($service)->add($user, $cost, 'limbo_restore_refund', 'Limbo restore refund', ['reason' => 'limbo restore refund']);
        } catch (Throwable) {
            //
        }
    }

    /** @return array<int, int> */
    private function pickAllocations(Node $node, int $needed): array
    {
        try {
            $free = Allocation::query()
                ->where('node_id', $node->getKey())
                ->whereNull('server_id')
                ->orderBy('ip')->orderBy('port')
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
}
