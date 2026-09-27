<?php

namespace PluggedHost\Servers\Services\Transfers;

use App\Enums\NodeJwtScope;
use App\Enums\ServerState;
use App\Exceptions\Http\Server\ServerStateConflictException;
use App\Extensions\BackupAdapter\BackupAdapterService;
use App\Extensions\BackupAdapter\Schemas\WingsBackupSchema;
use App\Facades\Activity;
use App\Models\Allocation;
use App\Models\Backup;
use App\Models\Node;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServerTransfer;
use App\Models\User;
use App\Services\Nodes\NodeJWTService;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PluggedHost\Core\Support\Countries;
use PluggedHost\Servers\Exceptions\TransferException;
use PluggedHost\Servers\Models\ServerRelocation;
use PluggedHost\Servers\Services\LocationCatalog;
use RuntimeException;
use Throwable;

/**
 * Moving a server to another location without losing anything.
 *
 * The copy is wings' own transfer: the source streams a checksummed archive (and the
 * server's local backups) straight to the destination, and upstream deletes the source copy
 * only after the destination has verified it and told the panel. What upstream leaves out is
 * everything around that, and that is what this adds — driven as a small state machine by
 * TransferWatcher, one short idempotent step at a time (see ServerRelocation's states):
 *
 *  - the user picks a location; the node inside it is chosen here, by the same rules as
 *    server creation, and it keeps its port numbers where the new node has them free;
 *  - the server is marked as transferring before anything happens, so nobody starts it
 *    mid-move, then stopped cleanly (killed only after stop_timeout) instead of relying on
 *    wings' 15-second stop that refuses the whole transfer when a world takes longer to save;
 *  - wings' answer is checked (upstream ignores it and leaves a transfer "running" forever
 *    when the source refuses), progress is read live from the source's websocket;
 *  - a failed attempt releases its ports and is retried on another node of the location;
 *  - a stalled one is aborted through wings' own cancel, which fails it cleanly;
 *  - when it gives up, the server is started again where it was. Nothing on the source is
 *    ever deleted by this class. The one case it cannot settle safely on its own (the new
 *    node has a copy that wings never reported) is left for an admin as STUCK.
 */
class TransferService
{
    /** The subuser permission ("transfer" tab in the subuser editor). */
    public const PERMISSION = 'transfer.move';

    /** Per-process throttles: relocation id => step => unix time last run. */
    private array $lastRun = [];

    public function __construct(protected TransferPlanner $planner) {}

    public function enabled(): bool
    {
        return (bool) config('plugged-host-servers.transfers.enabled', true);
    }

    public function planner(): TransferPlanner
    {
        return $this->planner;
    }

    /**
     * The owner, a subuser the owner gave "transfer", or an admin who may edit the server.
     */
    public static function canUse(?User $user, Server $server): bool
    {
        if (!$user instanceof User) {
            return false;
        }

        if ((int) $server->owner_id === (int) $user->getKey() || $user->can('update server', $server)) {
            return true;
        }

        $subuser = $server->subusers()->where('user_id', $user->getKey())->first();

        return $subuser !== null && in_array(self::PERMISSION, (array) $subuser->permissions, true);
    }

    /**
     * Why this user cannot start a move of this server right now, or null when they can.
     *
     * @return null|array{0: string, 1: array<string, scalar>}
     */
    public function blockedReason(Server $server, ?User $user, bool $selfService = true): ?array
    {
        // The start queue's own offer has its own switch; this one is for the Transfer page.
        if ($selfService && !$this->enabled()) {
            return ['disabled', []];
        }

        if (!self::canUse($user, $server)) {
            return ['permission', []];
        }

        if (ServerRelocation::activeFor($server) !== null || $this->hasOpenTransfer($server)) {
            return ['busy', []];
        }

        if ($server->isSuspended()) {
            return ['suspended', []];
        }

        if (!$server->isInstalled()) {
            return ['installing', []];
        }

        if ($server->status === ServerState::RestoringBackup) {
            return ['restoring', []];
        }

        if (!$server->allocation_id) {
            return ['no_allocation', []];
        }

        if ($this->backupRunning($server)) {
            return ['backup_running', []];
        }

        $until = $this->cooldownUntil($server);

        if ($until !== null) {
            return ['cooldown', ['time' => $until->diffForHumans(syntax: Carbon::DIFF_ABSOLUTE)]];
        }

        return null;
    }

    public function cooldownUntil(Server $server): ?Carbon
    {
        $hours = max(0, (int) config('plugged-host-servers.transfers.cooldown_hours', 6));

        if ($hours === 0) {
            return null;
        }

        $last = ServerRelocation::query()
            ->where('server_id', $server->getKey())
            ->where('state', ServerRelocation::COMPLETED)
            ->whereIn('origin', [ServerRelocation::ORIGIN_USER, ServerRelocation::ORIGIN_QUEUE])
            ->latest('finished_at')
            ->value('finished_at');

        if ($last === null) {
            return null;
        }

        $until = Carbon::parse($last)->addHours($hours);

        return $until->isFuture() ? $until : null;
    }

    /**
     * Start moving a server. Validates everything the user could have got wrong, records the
     * move and hands it to the watcher; the heavy steps run there, so this returns at once.
     *
     * @param  string|null  $location  location code; null only together with $only
     * @param  array<int, int>|null  $only  node ids to choose from, in order (start queue offer)
     *
     * @throws TransferException
     */
    public function start(Server $server, User $user, ?string $location, string $origin = ServerRelocation::ORIGIN_USER, ?array $only = null, bool $startAfter = false): ServerRelocation
    {
        if (($reason = $this->blockedReason($server, $user, $origin === ServerRelocation::ORIGIN_USER)) !== null) {
            throw new TransferException(...$reason);
        }

        $location = is_string($location) && $location !== '' ? strtolower(trim($location)) : null;

        if ($location === null && $only === null) {
            throw new TransferException('invalid_location');
        }

        if ($location !== null) {
            $offer = $this->planner->locations($server)->firstWhere('code', $location);

            if (!is_array($offer)) {
                throw new TransferException('invalid_location');
            }

            if (($offer['tier'] ?? LocationCatalog::FREE) !== LocationCatalog::FREE && !($offer['unlocked'] ?? false)) {
                throw new TransferException('location_locked');
            }
        }

        if ($this->planner->destinations($server, $location, [], $only)->isEmpty()) {
            throw new TransferException('no_node');
        }

        $relocation = DB::transaction(function () use ($server, $user, $location, $origin, $only, $startAfter) {
            // Serialises double clicks: the second one waits here and then sees the first.
            Server::query()->whereKey($server->getKey())->lockForUpdate()->first();

            if (ServerRelocation::activeFor($server) !== null) {
                throw new TransferException('busy');
            }

            $relocation = new ServerRelocation([
                'server_id' => $server->getKey(),
                'user_id' => $user->getKey(),
                'origin' => $origin,
                'location' => $location,
                'from_node_id' => $server->node_id,
                'state' => ServerRelocation::PREPARING,
                'attempt' => 1,
                'max_attempts' => max(1, (int) config('plugged-host-servers.transfers.max_attempts', 3)),
                'start_after' => $startAfter,
            ]);
            $relocation->state_at = now();
            $relocation->remember('only', $only);
            $relocation->remember('from_location', $this->planner->locationOf($server->node));
            $relocation->event('requested', ['location' => $location ?? '']);
            $relocation->save();

            return $relocation;
        });

        Activity::event('server:transfer.start')
            ->subject($server)
            ->property('location', $this->locationName($location ?? $this->planner->locationOf($this->planner->destinations($server, null, [], $only)->first())))
            ->log();

        TransferWatcher::ensureRunning();

        return $relocation;
    }

    /**
     * Stop a move that has not reached the point of no return. Before the copy that is an
     * immediate rollback; during the copy it goes through wings' own cancel, which fails the
     * push and leaves the source untouched.
     */
    public function cancel(ServerRelocation $relocation, User $user): bool
    {
        return (bool) $this->locked($relocation, function (ServerRelocation $relocation) use ($user): bool {
            if (!$relocation->isCancellable()) {
                return false;
            }

            $relocation->remember('cancelled_by', $user->getKey());
            $relocation->remember('user_cancelled', true);

            if ($relocation->state === ServerRelocation::COPYING) {
                $transfer = ServerTransfer::query()->find($relocation->transfer_id);
                $server = Server::query()->find($relocation->server_id);

                if ($transfer instanceof ServerTransfer && $server instanceof Server) {
                    $this->abortRemote($relocation, $server, $transfer, 'user');

                    return true;
                }
            }

            $this->releaseAttempt($relocation);
            $relocation->event('cancelled', [], 'warning');
            $this->finish($relocation, ServerRelocation::CANCELLED, 'cancelled');

            return true;
        }, 15);
    }

    /** One step of one move. Safe to call as often as you like; the watcher calls it every second. */
    public function advance(ServerRelocation $relocation): void
    {
        $this->locked($relocation, function (ServerRelocation $relocation): void {
            if (!$relocation->isActive()) {
                return;
            }

            $server = Server::query()->find($relocation->server_id);

            if (!$server instanceof Server) {
                return; // deleted; the row goes with it (cascade)
            }

            try {
                match ($relocation->state) {
                    ServerRelocation::PREPARING => $this->prepare($relocation, $server),
                    ServerRelocation::STOPPING => $this->stopping($relocation, $server),
                    ServerRelocation::COPYING, ServerRelocation::FINALIZING => $this->copying($relocation, $server),
                    ServerRelocation::STARTING => $this->starting($relocation, $server),
                    ServerRelocation::RETRYING => $this->retrying($relocation),
                    ServerRelocation::ROLLBACK => $this->rollback($relocation, $server),
                    default => null,
                };

                if ($relocation->info('step_errors')) {
                    $relocation->remember('step_errors', 0);
                    $relocation->save();
                }
            } catch (Throwable $e) {
                report($e);

                $relocation->refresh();
                $errors = (int) $relocation->info('step_errors', 0) + 1;
                $relocation->remember('step_errors', $errors);
                $relocation->remember('last_exception', mb_substr($e->getMessage(), 0, 300));
                $relocation->save();

                // A step that keeps throwing would otherwise spin forever. Treat it as a failed
                // attempt: that path only ever releases the new node's ports.
                if ($errors >= 20) {
                    $this->attemptFailed($relocation, 'internal_error', false);
                }
            }
        });
    }

    /**
     * Transfers somebody else started (the admin panel, limbo) get the same watching: live
     * progress, and a stalled one is resolved instead of blocking the server forever. They
     * are never retried — the node was somebody's explicit choice.
     */
    public function adoptExternal(): int
    {
        $known = ServerRelocation::query()->whereNotNull('transfer_id')->pluck('transfer_id')->all();

        $orphans = ServerTransfer::query()
            ->whereNull('successful')
            ->whereNotIn('id', $known)
            ->where('created_at', '<', now()->subMinute())
            ->get();

        $adopted = 0;

        foreach ($orphans as $transfer) {
            if (ServerRelocation::activeFor((int) $transfer->server_id) !== null) {
                continue;
            }

            $relocation = new ServerRelocation([
                'server_id' => $transfer->server_id,
                'origin' => ServerRelocation::ORIGIN_EXTERNAL,
                'location' => $this->planner->locationOf($transfer->newNode),
                'from_node_id' => $transfer->old_node,
                'to_node_id' => $transfer->new_node,
                'transfer_id' => $transfer->getKey(),
                'state' => ServerRelocation::COPYING,
                'phase' => ServerRelocation::PHASE_ARCHIVE,
                'max_attempts' => 1,
            ]);
            $relocation->state_at = now();
            $relocation->progress_at = now();
            $relocation->remember('from_location', $this->planner->locationOf($transfer->oldNode));
            $relocation->remember('launched_at', $transfer->created_at?->getTimestamp() ?? now()->getTimestamp());
            $relocation->remember('backups', []);
            $relocation->event('adopted');
            $relocation->save();

            $adopted++;
        }

        return $adopted;
    }

    /**
     * A move handed to an admin as STUCK can still be settled by wings later (its success
     * call finally got through, or an admin resolved the transfer). Pick those up: a success
     * gets the usual after-move steps, a failure just closes the move.
     */
    public function reviveStuck(): int
    {
        $revived = 0;

        foreach (ServerRelocation::query()->where('state', ServerRelocation::STUCK)->whereNotNull('transfer_id')->get() as $relocation) {
            $successful = ServerTransfer::query()->whereKey($relocation->transfer_id)->value('successful');

            if ($successful === null) {
                continue;
            }

            $this->locked($relocation, function (ServerRelocation $relocation) use ($successful): void {
                if ((bool) $successful) {
                    $relocation->moveState(ServerRelocation::STARTING);
                    $relocation->remember('copied_at', now()->getTimestamp());
                    $relocation->event('copied', [], 'success');
                } else {
                    $relocation->moveState(ServerRelocation::FAILED);
                    $relocation->event('ended_failed', [], 'danger');
                }

                $relocation->finished_at = $successful ? null : now();
                $relocation->save();
            });

            $revived++;
        }

        return $revived;
    }

    // ---------------------------------------------------------------------------------
    // States
    // ---------------------------------------------------------------------------------

    protected function prepare(ServerRelocation $relocation, Server $server): void
    {
        if ((int) $server->node_id !== $relocation->from_node_id) {
            // Somewhere else already (a node failover, an admin). Nothing of ours to undo.
            $this->finish($relocation, ServerRelocation::FAILED, 'server_moved', restorePower: false);

            return;
        }

        if ($relocation->transfer_id === null && !$this->pickDestination($relocation, $server)) {
            return;
        }

        $details = $this->details($server, $relocation->fromNode);

        if ($details === null) {
            $this->attemptFailed($relocation, 'source_unreachable', false);

            return;
        }

        $state = (string) ($details['state'] ?? 'offline');

        if (!$relocation->info('power_checked')) {
            $relocation->was_running = in_array($state, ['running', 'starting'], true);
            $relocation->remember('power_checked', true);
        }

        $relocation->bytes_total = max(0, (int) data_get($details, 'utilization.disk_bytes', 0));

        if ($state !== 'offline') {
            $this->sendStop($relocation, $server);

            return;
        }

        $this->launch($relocation, $server);
    }

    protected function stopping(ServerRelocation $relocation, Server $server): void
    {
        if (!$this->due($relocation, 'stop-poll', 1)) {
            return;
        }

        $elapsed = now()->getTimestamp() - (int) $relocation->info('stop_at', now()->getTimestamp());
        $details = $this->details($server, $relocation->fromNode);

        if ($details === null) {
            if ($elapsed > 120) {
                $this->attemptFailed($relocation, 'source_unreachable', false);
            }

            return;
        }

        if (($details['state'] ?? '') === 'offline') {
            $relocation->event('stopped');
            $this->launch($relocation, $server);

            return;
        }

        $timeout = max(10, (int) config('plugged-host-servers.transfers.stop_timeout', 90));

        if ($elapsed > $timeout && !$relocation->info('killed')) {
            $this->power($relocation->fromNode, $server, 'kill');
            $relocation->remember('killed', true);
            $relocation->event('killed', [], 'warning');
            $relocation->save();

            return;
        }

        if ($elapsed > $timeout + 60) {
            $this->attemptFailed($relocation, 'stop_failed', false);
        }
    }

    protected function copying(ServerRelocation $relocation, Server $server): void
    {
        $transfer = ServerTransfer::query()->find($relocation->transfer_id);

        if (!$transfer instanceof ServerTransfer) {
            $this->attemptFailed($relocation, 'transfer_missing', false);

            return;
        }

        if ($transfer->successful === true) {
            $this->copied($relocation, $server);

            return;
        }

        if ($transfer->successful === false) {
            if ($relocation->info('user_cancelled')) {
                $this->releaseAttempt($relocation);
                $relocation->event('cancelled', [], 'warning');
                $this->finish($relocation, ServerRelocation::CANCELLED, 'cancelled');

                return;
            }

            $this->attemptFailed($relocation, $relocation->phase === ServerRelocation::PHASE_CANCELLING
                ? (string) $relocation->info('abort_reason', 'stalled')
                : 'copy_failed', true);

            return;
        }

        if ($relocation->phase === ServerRelocation::PHASE_CANCELLING) {
            $this->awaitAbort($relocation, $server, $transfer);

            return;
        }

        if ($relocation->state === ServerRelocation::COPYING && $relocation->phase === ServerRelocation::PHASE_HANDOFF) {
            $relocation->moveState(ServerRelocation::FINALIZING);
            $relocation->remember('handoff_at', now()->getTimestamp());
            $relocation->event('verifying');
            $relocation->save();

            return;
        }

        if (($reason = $this->stalled($relocation)) !== null) {
            $this->abortRemote($relocation, $server, $transfer, $reason);
        }
    }

    protected function starting(ServerRelocation $relocation, Server $server): void
    {
        if (!$relocation->info('post_done')) {
            $transfer = ServerTransfer::query()->find($relocation->transfer_id);

            if ($transfer instanceof ServerTransfer) {
                $this->afterMove($relocation, $server, $transfer);
            }

            $relocation->remember('post_done', true);
            $relocation->save();
        }

        if (!$relocation->was_running && !$relocation->start_after) {
            $this->complete($relocation, $server);

            return;
        }

        if (!$this->due($relocation, 'start', 3)) {
            return;
        }

        $server->refresh();
        $response = $this->power($server->node, $server, 'start');

        if ($response?->successful()) {
            $relocation->event('started', [], 'success');
            $this->complete($relocation, $server);

            return;
        }

        // Right after the success call the new node still flags the server as transferring
        // for a moment, and may still be creating the container; keep trying for a while.
        if (now()->getTimestamp() - (int) $relocation->info('copied_at', 0) > 120) {
            $relocation->event('start_failed', [], 'warning');
            $this->complete($relocation, $server);
        }
    }

    protected function retrying(ServerRelocation $relocation): void
    {
        if ($relocation->next_step_at !== null && $relocation->next_step_at->isFuture()) {
            return;
        }

        $relocation->attempt++;
        $relocation->transfer_id = null;
        $relocation->to_node_id = null;
        $relocation->phase = null;
        $relocation->bytes_done = 0;
        $relocation->backups_done = 0;
        $relocation->rate = 0;
        $relocation->progress_at = null;
        $relocation->socket_at = null;
        $relocation->next_step_at = null;

        foreach (['launched_at', 'handoff_at', 'abort_at', 'abort_reason', 'killed', 'stop_at', 'socket_seen'] as $key) {
            $relocation->remember($key, null);
        }

        $relocation->moveState(ServerRelocation::PREPARING);
        $relocation->event('retrying', ['attempt' => $relocation->attempt, 'max' => $relocation->max_attempts]);
        $relocation->save();
    }

    /** Putting the server back online on its old node after a move gave up or was cancelled. */
    protected function rollback(ServerRelocation $relocation, Server $server): void
    {
        if (!$this->due($relocation, 'rollback', 3)) {
            return;
        }

        $since = now()->getTimestamp() - ($relocation->state_at?->getTimestamp() ?? now()->getTimestamp());

        if ((int) $server->node_id !== $relocation->from_node_id || $this->hasOpenTransfer($server)) {
            $this->finalize($relocation);

            return;
        }

        $details = $this->details($server, $relocation->fromNode);

        if (in_array($details['state'] ?? null, ['running', 'starting'], true)) {
            $relocation->event('restarted', [], 'success');
            $this->finalize($relocation);

            return;
        }

        if ($details !== null && $this->power($relocation->fromNode, $server, 'start')?->successful()) {
            $relocation->event('restarted', [], 'success');
            $this->finalize($relocation);

            return;
        }

        // The source clears its transferring flag just after it reports the failure; keep
        // trying for two minutes, then leave the start to the user.
        if ($since > 120) {
            $relocation->event('restart_failed', [], 'warning');
            $this->finalize($relocation);
        }
    }

    // ---------------------------------------------------------------------------------
    // Steps
    // ---------------------------------------------------------------------------------

    /**
     * Pick the node for this attempt, claim its ports and create upstream's transfer row.
     * From here on Pelican treats the server as transferring: power and file actions are
     * refused, so nobody starts it in the middle of the move.
     */
    protected function pickDestination(ServerRelocation $relocation, Server $server): bool
    {
        $tried = array_map(intval(...), (array) $relocation->info('tried', []));
        $only = $relocation->info('only');

        foreach ($this->planner->destinations($server, $relocation->location, $tried, is_array($only) ? $only : null) as $node) {
            if (!$this->reachable($node)) {
                $tried[] = (int) $node->getKey();

                continue;
            }

            for ($try = 0; $try < 2; $try++) {
                $allocations = $this->planner->allocationsOn($server, $node);

                if ($allocations === null) {
                    continue 2;
                }

                try {
                    $transfer = $this->reserve($server, $node, $allocations);
                } catch (ServerStateConflictException) {
                    $relocation->remember('tried', array_values(array_unique($tried)));
                    $this->finish($relocation, ServerRelocation::FAILED, 'conflict', restorePower: false);

                    return false;
                } catch (RuntimeException) {
                    continue; // a port was taken between picking and claiming: pick again
                }

                $relocation->transfer_id = (int) $transfer->getKey();
                $relocation->to_node_id = (int) $node->getKey();
                $relocation->remember('tried', array_values(array_unique($tried)));
                $relocation->remember('ports', array_values($allocations['ports']));
                $relocation->event('destination', ['location' => $this->planner->locationOf($node) ?? '']);
                $relocation->save();

                return true;
            }
        }

        $relocation->remember('tried', array_values(array_unique($tried)));
        $this->attemptFailed($relocation, 'no_node', false);

        return false;
    }

    /**
     * Claim the destination ports and create the transfer row, in one transaction. Mirrors
     * upstream's TransferServerService::handle(), minus its two holes: a port another server
     * took in the meantime is refused here instead of silently skipped, and the source is
     * only told later, once the server is stopped.
     *
     * @param  array{primary: int, additional: array<int, int>, ports: array<int, int>}  $allocations
     *
     * @throws ServerStateConflictException|RuntimeException
     */
    protected function reserve(Server $server, Node $node, array $allocations): ServerTransfer
    {
        return DB::transaction(function () use ($server, $node, $allocations) {
            $server = Server::query()->whereKey($server->getKey())->lockForUpdate()->firstOrFail();
            $server->validateTransferState();

            $new = array_merge([$allocations['primary']], $allocations['additional']);

            $free = Allocation::withoutGlobalScopes()
                ->whereIn('id', $new)
                ->where('node_id', $node->getKey())
                ->whereNull('server_id')
                ->lockForUpdate()
                ->count();

            if ($free !== count($new)) {
                throw new RuntimeException('destination allocation already taken');
            }

            // Read the old set before claiming the new one, which also becomes "the server's".
            $old = $this->planner->currentAllocations($server);

            Allocation::withoutGlobalScopes()->whereIn('id', $new)->update(['server_id' => $server->getKey()]);

            return ServerTransfer::query()->create([
                'server_id' => $server->getKey(),
                'old_node' => $server->node_id,
                'new_node' => $node->getKey(),
                'old_allocation' => $server->allocation_id,
                'new_allocation' => $allocations['primary'],
                'old_additional_allocations' => $old->slice(1)->map(fn (Allocation $a) => (int) $a->getKey())->values()->all(),
                'new_additional_allocations' => array_values($allocations['additional']),
            ]);
        });
    }

    protected function sendStop(ServerRelocation $relocation, Server $server): void
    {
        $this->power($relocation->fromNode, $server, 'stop');

        $relocation->moveState(ServerRelocation::STOPPING);
        $relocation->remember('stop_at', now()->getTimestamp());
        $relocation->event('stopping');
        $relocation->save();
    }

    /** Tell the source node to push the server (and its local backups) to the destination. */
    protected function launch(ServerRelocation $relocation, Server $server): void
    {
        $transfer = ServerTransfer::query()->find($relocation->transfer_id);

        if (!$transfer instanceof ServerTransfer || $transfer->successful !== null) {
            $this->attemptFailed($relocation, 'transfer_missing', true);

            return;
        }

        $backups = $this->wingsBackups($server);

        $relocation->remember('backups', $backups->map(fn (Backup $b) => ['uuid' => $b->uuid, 'bytes' => (int) $b->bytes])->values()->all());
        $relocation->backups_total = $backups->count();
        $relocation->backups_done = 0;
        $relocation->backup_bytes = (int) $backups->sum('bytes');
        $relocation->bytes_done = 0;
        $relocation->rate = 0;

        try {
            $token = app(NodeJWTService::class)
                ->setExpiresAt(CarbonImmutable::now()->addMinutes(15))
                ->setScopes(NodeJwtScope::ServerTransfer)
                ->setSubject($server->uuid)
                ->handle($transfer->newNode, $server->uuid);

            $response = Http::daemon($transfer->oldNode)
                ->connectTimeout(5)
                ->timeout(30)
                ->post("/api/servers/{$server->uuid}/transfer", [
                    'url' => $transfer->newNode->getConnectionAddress() . '/api/transfers',
                    'token' => 'Bearer ' . $token->toString(),
                    'backups' => $backups->pluck('uuid')->values()->all(),
                    'server' => [
                        'uuid' => $server->uuid,
                        // We start it ourselves, after the ports and DNS are fixed up.
                        'start_on_completion' => false,
                    ],
                ]);
        } catch (Throwable $e) {
            $relocation->remember('last_error', mb_substr($e->getMessage(), 0, 300));

            // Never connected (refused, unknown host): nothing reached wings, fail fast.
            if (preg_match('/cURL error (6|7):/', $e->getMessage())) {
                $this->attemptFailed($relocation, 'launch_failed', true);

                return;
            }

            // Anything else (a read timeout) may have reached wings without us seeing the
            // answer. Releasing now could leave a push running against a transfer we gave up
            // on, so treat it like a stalled copy: cancel on the source, and let wings' own
            // failure report, or a look at the new node, settle it before the next attempt.
            $relocation->moveState(ServerRelocation::COPYING);
            $relocation->remember('launched_at', now()->getTimestamp());
            $this->abortRemote($relocation, $server, $transfer, 'launch_failed');

            return;
        }

        if ($response->status() === 409) {
            // The source already believes this server is transferring: a transfer from
            // before that never finished. Only a wings restart on that node clears it.
            $relocation->remember('last_error', mb_substr($response->body(), 0, 300));
            $this->attemptFailed($relocation, 'source_busy', false);

            return;
        }

        if (!$response->successful()) {
            $relocation->remember('last_error', 'HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 280));
            $this->attemptFailed($relocation, 'launch_failed', true);

            return;
        }

        $relocation->moveState(ServerRelocation::COPYING);
        $relocation->phase = ServerRelocation::PHASE_ARCHIVE;
        $relocation->progress_at = now();
        $relocation->remember('launched_at', now()->getTimestamp());
        $relocation->event('copying', ['backups' => $backups->count()]);
        $relocation->save();

        TransferWatcher::ensureRunning();
    }

    /** Upstream recorded the move: the server now belongs to the new node. */
    protected function copied(ServerRelocation $relocation, Server $server): void
    {
        $relocation->moveState(ServerRelocation::STARTING);
        $relocation->phase = null;
        $relocation->remember('copied_at', now()->getTimestamp());
        $relocation->event('copied', [], 'success');
        $relocation->save();

        $this->starting($relocation, $server->refresh());
    }

    /**
     * What upstream's success handler does not, and what has to happen before the server
     * starts: the new ports get the old ones' lock and notes, egg variables that held an old
     * port number follow it, and the cached status is dropped. (Subdomains are repointed in
     * complete(), after the start, so the Cloudflare calls do not add to the downtime.)
     */
    protected function afterMove(ServerRelocation $relocation, Server $server, ServerTransfer $transfer): void
    {
        $old = array_values(array_filter(array_merge([$transfer->old_allocation], $transfer->old_additional_allocations ?? [])));
        $new = array_values(array_filter(array_merge([$transfer->new_allocation], $transfer->new_additional_allocations ?? [])));

        try {
            $oldRows = Allocation::withoutGlobalScopes()->whereIn('id', $old)->get()->keyBy('id');
            $newRows = Allocation::withoutGlobalScopes()->whereIn('id', $new)->get()->keyBy('id');
            $portMap = [];

            foreach ($new as $index => $id) {
                $before = $oldRows->get($old[$index] ?? 0);
                $after = $newRows->get($id);

                if ($before instanceof Allocation && $after instanceof Allocation) {
                    $portMap[(int) $before->port] = (int) $after->port;
                }

                Allocation::withoutGlobalScopes()
                    ->whereKey($id)
                    ->where('server_id', $server->getKey())
                    ->update([
                        'is_locked' => $before instanceof Allocation ? (bool) $before->is_locked : $index === 0,
                        'notes' => $before?->notes,
                    ]);
            }

            // What the old node's ports look like once free, the way Allocation::updating would.
            Allocation::withoutGlobalScopes()->whereIn('id', $old)->whereNull('server_id')->update(['is_locked' => false, 'notes' => null]);

            $this->followPorts($relocation, $server, array_filter($portMap, fn (int $to, int $from) => $to !== $from, ARRAY_FILTER_USE_BOTH));
        } catch (Throwable $e) {
            report($e);
        }

        cache()->forget("servers.{$server->uuid}.status");
        cache()->forget("servers.{$server->uuid}.resources");

        $primary = Allocation::withoutGlobalScopes()->find($server->allocation_id);

        if ($primary instanceof Allocation) {
            $relocation->remember('address', ($primary->ip_alias ?: $server->node?->fqdn ?: $primary->ip) . ':' . $primary->port);
        }
    }

    /**
     * An egg variable named *PORT* whose value is one of the old port numbers (a txAdmin or
     * query port) is set to the matching new one; otherwise the server would start listening
     * on a port that is no longer its own.
     *
     * @param  array<int, int>  $portMap  old port => new port
     */
    protected function followPorts(ServerRelocation $relocation, Server $server, array $portMap): void
    {
        if ($portMap === []) {
            return;
        }

        foreach ($server->serverVariables()->with('variable')->get() as $variable) {
            $name = strtoupper((string) $variable->variable?->env_variable);
            $value = trim((string) $variable->variable_value);

            if (!str_contains($name, 'PORT') || !ctype_digit($value) || !isset($portMap[(int) $value])) {
                continue;
            }

            $variable->forceFill(['variable_value' => (string) $portMap[(int) $value]])->save();
            $relocation->event('port_variable', ['name' => $name, 'port' => $portMap[(int) $value]]);
        }
    }

    protected function refreshSubdomains(ServerRelocation $relocation, Server $server): void
    {
        $class = 'Boy132\\Subdomains\\Models\\Subdomain';

        if (!class_exists($class)) {
            return;
        }

        try {
            $subdomains = $class::query()->where('server_id', $server->getKey())->get();
        } catch (Throwable) {
            return;
        }

        foreach ($subdomains as $subdomain) {
            try {
                $subdomain->setRelation('server', $server);
                $subdomain->upsertOnCloudflare();
                $relocation->event('dns', ['name' => (string) ($subdomain->fqdn ?? $subdomain->name ?? '')]);
            } catch (Throwable $e) {
                Log::warning('[plugged-host-servers] subdomain update after transfer failed', ['server' => $server->getKey(), 'error' => $e->getMessage()]);
                $relocation->event('dns_failed', ['name' => (string) ($subdomain->name ?? '')], 'warning');
            }
        }
    }

    /**
     * Has this copy stopped moving? Judged by bytes while the source's websocket is being
     * read, and by a generous size-based budget when it is not (so a watcher restart or a
     * flaky socket can never make a healthy transfer look stalled).
     */
    protected function stalled(ServerRelocation $relocation): ?string
    {
        $now = now()->getTimestamp();
        $stall = max(2, (int) config('plugged-host-servers.transfers.stall_minutes', 10)) * 60;

        if ($relocation->state === ServerRelocation::FINALIZING) {
            $finalize = max(5, (int) config('plugged-host-servers.transfers.finalize_minutes', 20)) * 60;

            return $now - (int) $relocation->info('handoff_at', $now) > $finalize ? 'finalize_timeout' : null;
        }

        $launched = (int) $relocation->info('launched_at', $now);
        $last = $relocation->progress_at?->getTimestamp() ?? $launched;
        $allow = $stall;

        if ($relocation->phase === ServerRelocation::PHASE_BACKUPS) {
            // Backups show no byte counter, only "backup n done"; give the one in flight
            // twice the time it should take at the measured rate (at least 5 MB/s).
            $current = (int) data_get($relocation->info('backups', []), $relocation->backups_done . '.bytes', 0);
            $allow += 2 * (int) ceil($current / max($relocation->rate, 5 * 1024 * 1024));
        }

        $noProgress = $now - $last > $allow;
        $watching = $relocation->socket_at !== null && $now - $relocation->socket_at->getTimestamp() < 120;

        if ($watching) {
            return $noProgress ? 'stalled' : null;
        }

        $budget = $stall + (int) ceil(($relocation->bytes_total + $relocation->backup_bytes) / (5 * 1024 * 1024));

        return $noProgress && $now - $launched > $budget ? 'stalled' : null;
    }

    /**
     * Abort through wings: cancelling the source's push makes it fail and report the failure
     * itself, the one path in wings that is sure to leave the source copy alone. (Wings'
     * incoming-side cancel route has no server middleware and panics, so it is not used.)
     */
    protected function abortRemote(ServerRelocation $relocation, Server $server, ServerTransfer $transfer, string $reason): void
    {
        try {
            Http::daemon($transfer->oldNode)->connectTimeout(3)->timeout(8)->delete("/api/servers/{$server->uuid}/transfer");
        } catch (Throwable) {
            //
        }

        $relocation->phase = ServerRelocation::PHASE_CANCELLING;
        $relocation->remember('abort_at', now()->getTimestamp());
        $relocation->remember('abort_reason', $reason);
        $relocation->event($reason === 'user' ? 'cancelling' : $reason, [], 'warning');
        $relocation->save();
    }

    /**
     * Wings did not report the aborted attempt. If the new node has nothing, the attempt is
     * over and its ports can go. If it has a copy wings never reported, only a human can say
     * which side is right, so the server stays put, blocked, and an admin is told.
     */
    protected function awaitAbort(ServerRelocation $relocation, Server $server, ServerTransfer $transfer): void
    {
        $since = now()->getTimestamp() - (int) $relocation->info('abort_at', now()->getTimestamp());

        if ($since < 90 || !$this->due($relocation, 'abort-probe', 30)) {
            return;
        }

        $probe = $this->probe($transfer->newNode, $server);

        if ($probe === 'absent') {
            $this->release($transfer);

            if ($relocation->info('user_cancelled')) {
                $relocation->event('cancelled', [], 'warning');
                $this->finish($relocation, ServerRelocation::CANCELLED, 'cancelled');

                return;
            }

            $this->attemptFailed($relocation, (string) $relocation->info('abort_reason', 'stalled'), true);

            return;
        }

        if ($since > 600) {
            $relocation->remember('probe', $probe);
            $this->finish($relocation, ServerRelocation::STUCK, 'stuck', restorePower: false);
        }
    }

    /**
     * One attempt is over without the server having moved. Its ports are released; the next
     * attempt goes to another node of the location if there is one, or the move gives up.
     */
    protected function attemptFailed(ServerRelocation $relocation, string $reason, bool $retryable): void
    {
        $this->releaseAttempt($relocation);

        $tried = array_map(intval(...), (array) $relocation->info('tried', []));

        if ($relocation->to_node_id) {
            $tried[] = $relocation->to_node_id;
        }

        $relocation->remember('tried', array_values(array_unique($tried)));
        $relocation->error = $reason;
        $relocation->event('attempt_failed', ['attempt' => $relocation->attempt, 'reason' => $reason], 'warning');

        $server = Server::query()->find($relocation->server_id);
        $only = $relocation->info('only');

        $retry = $retryable
            && $server instanceof Server
            && $relocation->origin !== ServerRelocation::ORIGIN_EXTERNAL
            && $relocation->attempt < $relocation->max_attempts
            && $this->planner->destinations($server, $relocation->location, $tried, is_array($only) ? $only : null)->isNotEmpty();

        if ($retry) {
            $relocation->moveState(ServerRelocation::RETRYING);
            $relocation->phase = null;
            $relocation->next_step_at = now()->addSeconds(5 * $relocation->attempt);
            $relocation->save();

            return;
        }

        $this->finish($relocation, ServerRelocation::FAILED, $reason);
    }

    /** Undo this attempt's claim on the destination, unless upstream already recorded it as done. */
    protected function releaseAttempt(ServerRelocation $relocation): void
    {
        if (!$relocation->transfer_id) {
            return;
        }

        $transfer = ServerTransfer::query()->find($relocation->transfer_id);

        if ($transfer instanceof ServerTransfer) {
            $this->release($transfer);
        }
    }

    /**
     * Mark a transfer failed and free its destination ports, as upstream's failure callback
     * does. Never touches a transfer upstream recorded as successful, and only frees ports
     * that are still this server's.
     */
    protected function release(ServerTransfer $transfer): void
    {
        DB::transaction(function () use ($transfer) {
            /** @var ServerTransfer|null $fresh */
            $fresh = ServerTransfer::query()->whereKey($transfer->getKey())->lockForUpdate()->first();

            if (!$fresh instanceof ServerTransfer || $fresh->successful === true) {
                return;
            }

            if ($fresh->successful === null) {
                $fresh->forceFill(['successful' => false])->saveOrFail();
            }

            $server = Server::query()->find($fresh->server_id);

            if ($server instanceof Server && (int) $server->node_id === (int) $fresh->new_node) {
                return; // it lives there now; its ports stay
            }

            $ids = array_values(array_filter(array_merge([$fresh->new_allocation], $fresh->new_additional_allocations ?? [])));

            Allocation::withoutGlobalScopes()
                ->whereIn('id', $ids)
                ->where('server_id', $fresh->server_id)
                ->update(['server_id' => null, 'is_locked' => false]);
        });
    }

    /**
     * End the move. When the server was running before and is still on its old node, it goes
     * through ROLLBACK first to be started again there.
     */
    protected function finish(ServerRelocation $relocation, string $state, ?string $reason, bool $restorePower = true): void
    {
        $relocation->error = $state === ServerRelocation::CANCELLED ? null : $reason;
        $relocation->phase = null;
        $relocation->remember('final_state', $state);

        $server = Server::query()->find($relocation->server_id);

        if ($restorePower && $relocation->was_running && $server instanceof Server && (int) $server->node_id === $relocation->from_node_id) {
            $relocation->moveState(ServerRelocation::ROLLBACK);
            $relocation->event('restarting');
            $relocation->save();

            return;
        }

        $this->finalize($relocation);
    }

    protected function finalize(ServerRelocation $relocation): void
    {
        $state = (string) $relocation->info('final_state', ServerRelocation::FAILED);

        $relocation->moveState($state);
        $relocation->phase = null;
        $relocation->finished_at = now();

        $relocation->event(match ($state) {
            ServerRelocation::CANCELLED => 'ended_cancelled',
            ServerRelocation::STUCK => 'ended_stuck',
            default => 'ended_failed',
        }, [], $state === ServerRelocation::CANCELLED ? 'info' : 'danger');

        $relocation->save();

        $server = Server::query()->find($relocation->server_id);

        if ($server instanceof Server) {
            Activity::event("server:transfer.$state")
                ->subject($server)
                ->property('reason', trans('plugged-host-servers::strings.transfers.reasons.' . ($relocation->error ?? 'internal_error')))
                ->log();

            $this->notifyPeople($relocation, $server, $state);
        }

        if (in_array($state, [ServerRelocation::FAILED, ServerRelocation::STUCK], true)) {
            Log::warning("[plugged-host-servers] server transfer ended $state", [
                'relocation' => $relocation->getKey(),
                'server' => $relocation->server_id,
                'reason' => $relocation->error,
                'tried' => $relocation->info('tried'),
                'last_error' => $relocation->info('last_error'),
            ]);
            $this->notifyAdmins($relocation, $server, $state);
        }
    }

    protected function complete(ServerRelocation $relocation, Server $server): void
    {
        $this->refreshSubdomains($relocation, $server->refresh());

        $relocation->moveState(ServerRelocation::COMPLETED);
        $relocation->phase = null;
        $relocation->error = null;
        $relocation->finished_at = now();
        $relocation->event('completed', ['address' => (string) $relocation->info('address', '')], 'success');
        $relocation->save();

        Activity::event('server:transfer.completed')
            ->subject($server)
            ->property('location', $this->locationName($this->targetLocation($relocation)))
            ->log();

        $this->notifyPeople($relocation, $server, ServerRelocation::COMPLETED);
    }

    // ---------------------------------------------------------------------------------
    // Wings and helpers
    // ---------------------------------------------------------------------------------

    /**
     * Successful backups that live on the node (wings adapter). They move with the server:
     * left behind they would still be listed and could never be restored.
     *
     * @return Collection<int, Backup>
     */
    protected function wingsBackups(Server $server): Collection
    {
        $adapters = app(BackupAdapterService::class);

        return Backup::query()
            ->where('server_id', $server->getKey())
            ->where('is_successful', true)
            ->whereNotNull('completed_at')
            ->with('backupHost')
            ->get()
            ->filter(fn (Backup $backup) => $backup->backupHost === null || $adapters->get($backup->backupHost->schema) instanceof WingsBackupSchema)
            ->sortBy('uuid')
            ->values();
    }

    protected function backupRunning(Server $server): bool
    {
        return Backup::query()
            ->where('server_id', $server->getKey())
            ->whereNull('completed_at')
            ->where('created_at', '>', now()->subHours(6))
            ->exists();
    }

    protected function hasOpenTransfer(Server $server): bool
    {
        return ServerTransfer::query()->where('server_id', $server->getKey())->whereNull('successful')->exists();
    }

    /** @return array<string, mixed>|null the wings server details, or null when not available */
    protected function details(Server $server, ?Node $node): ?array
    {
        if (!$node instanceof Node) {
            return null;
        }

        try {
            $response = Http::daemon($node)->connectTimeout(3)->timeout(6)->get("/api/servers/{$server->uuid}");

            return $response->successful() ? (array) $response->json() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** 'present' | 'absent' | 'unknown': does this node have the server loaded? */
    protected function probe(?Node $node, Server $server): string
    {
        if (!$node instanceof Node) {
            return 'unknown';
        }

        try {
            $status = Http::daemon($node)->connectTimeout(3)->timeout(6)->get("/api/servers/{$server->uuid}")->status();
        } catch (Throwable) {
            return 'unknown';
        }

        return match (true) {
            $status === 404 => 'absent',
            $status >= 200 && $status < 300 => 'present',
            default => 'unknown',
        };
    }

    protected function reachable(Node $node): bool
    {
        try {
            return Http::daemon($node)->connectTimeout(3)->timeout(5)->get('/api/system')->successful();
        } catch (Throwable) {
            return false;
        }
    }

    protected function power(?Node $node, Server $server, string $action): ?Response
    {
        if (!$node instanceof Node) {
            return null;
        }

        try {
            return Http::daemon($node)->connectTimeout(3)->timeout(10)->post("/api/servers/{$server->uuid}/power", ['action' => $action]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Run $callback with the relocation freshly loaded and nobody else stepping it. The
     * watcher does not wait (it comes back in a second); a user's click waits a little.
     */
    protected function locked(ServerRelocation $relocation, callable $callback, int $wait = 0): mixed
    {
        $lock = Cache::lock('plugged-host-servers:relocation:' . $relocation->getKey(), 60);

        try {
            $acquired = $wait > 0 ? $lock->block($wait) : $lock->get();
        } catch (LockTimeoutException) {
            $acquired = false;
        }

        if (!$acquired) {
            return null;
        }

        try {
            $fresh = ServerRelocation::query()->find($relocation->getKey());

            return $fresh instanceof ServerRelocation ? $callback($fresh) : null;
        } finally {
            $lock->release();
        }
    }

    /** True at most once per $seconds for this relocation and step, in this process. */
    protected function due(ServerRelocation $relocation, string $step, int $seconds): bool
    {
        $now = microtime(true);
        $last = $this->lastRun[$relocation->getKey()][$step] ?? 0.0;

        if ($now - $last < $seconds) {
            return false;
        }

        $this->lastRun[$relocation->getKey()][$step] = $now;

        return true;
    }

    protected function notifyPeople(ServerRelocation $relocation, Server $server, string $state): void
    {
        $recipients = collect([$server->user, $relocation->user])
            ->filter(fn ($user) => $user instanceof User)
            ->unique(fn (User $user) => $user->getKey());

        foreach ($recipients as $user) {
            try {
                $locale = (string) ($user->language ?: config('app.locale', 'en'));
                $to = $this->locationName($this->targetLocation($relocation), $locale);
                $key = "plugged-host-servers::strings.transfers.notify.$state";
                $notification = Notification::make()
                    ->title(trans("$key.title", ['server' => $server->name], $locale))
                    ->body(trans("$key.body", ['server' => $server->name, 'location' => $to, 'address' => (string) $relocation->info('address', '')], $locale));

                match ($state) {
                    ServerRelocation::COMPLETED => $notification->success(),
                    ServerRelocation::CANCELLED => $notification->info(),
                    default => $notification->warning(),
                };

                $notification->sendToDatabase($user);
            } catch (Throwable) {
                //
            }
        }
    }

    protected function notifyAdmins(ServerRelocation $relocation, ?Server $server, string $state): void
    {
        try {
            $admins = User::query()->whereHas('roles', fn ($query) => $query->where('name', Role::ROOT_ADMIN))->get();
        } catch (Throwable) {
            return;
        }

        foreach ($admins as $admin) {
            try {
                Notification::make()
                    ->title("Server transfer $state: " . ($server?->name ?? "#{$relocation->server_id}"))
                    ->body(sprintf(
                        'Relocation #%d, reason: %s. Nodes tried: %s. %s',
                        $relocation->getKey(),
                        $relocation->error ?? 'unknown',
                        implode(', ', Node::query()->whereIn('id', (array) $relocation->info('tried', []))->pluck('name')->all()) ?: 'none',
                        $state === ServerRelocation::STUCK
                            ? 'The server is still marked as transferring; its data is on the old node. Check both nodes before resolving it.'
                            : 'The server stayed on its old node.',
                    ))
                    ->danger()
                    ->sendToDatabase($admin);
            } catch (Throwable) {
                //
            }
        }
    }

    /** Where the move goes: the chosen location, or for the start queue's offer the node's. */
    public function targetLocation(ServerRelocation $relocation): ?string
    {
        return $relocation->location ?? $this->planner->locationOf($relocation->toNode);
    }

    public function locationName(?string $code, ?string $locale = null): string
    {
        if ($code === null || $code === '') {
            return trans('plugged-host-servers::strings.transfers.unknown_location', [], $locale);
        }

        [$country, $tier] = app(LocationCatalog::class)->resolve($code);
        $name = Countries::name($country) ?? strtoupper($country);

        return $tier === LocationCatalog::FREE ? $name : $name . ' (' . ucfirst($tier) . ')';
    }
}
