<?php

namespace PluggedHost\Queue\Services;

use App\Exceptions\Http\Server\ServerStateConflictException;
use App\Models\Allocation;
use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use App\Services\Deployment\FindViableNodesService;
use App\Services\Servers\TransferServerService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use PluggedHost\Core\Support\Plugins;
use Throwable;

/**
 * The other way out of the line: move the server to a node that is not busy.
 *
 * Waiting only helps if the node cools down. For a node that is over the line every
 * evening it does not, and the honest fix is to stop keeping the server there. This
 * finds somewhere better and starts an ordinary panel transfer to it — the same
 * upstream `TransferServerService` an admin uses, so wings does the copy, the
 * allocations are reserved up front, and `ServerTransfer` records it.
 *
 * Never automatic. A transfer changes the server's IP and port and takes it offline
 * while the data directory copies, so it is offered on the waiting page and happens
 * because the user pressed a button. It is also owner-only: a subuser with start
 * permission has no business relocating somebody else's server.
 */
class NodeTransferService
{
    /**
     * plugged-host-servers' transfer orchestrator (live progress, retries on another node,
     * automatic start, rollback). By class-string so the queue still works without it.
     */
    protected const ORCHESTRATOR = 'PluggedHost\\Servers\\Services\\Transfers\\TransferService';

    public const RESULT_OK = 'ok';

    /** The offer should not have been visible: disabled, too big, or no pass. */
    public const RESULT_UNAVAILABLE = 'unavailable';

    /** Nothing cooler to move to right now. */
    public const RESULT_NO_NODE = 'no_node';

    /** The server cannot be transferred in its current state (installing, restoring, already moving). */
    public const RESULT_CONFLICT = 'conflict';

    /** Wings or the panel refused it. */
    public const RESULT_FAILED = 'failed';

    public function __construct(
        protected NodePressure $pressure,
        protected PriorityPassService $passes,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('plugged-host-queue.transfer.enabled', true);
    }

    /**
     * Should this user see the offer for this server at all?
     *
     * Deliberately cheap — no node measurement, no allocation counting. It answers
     * "is this server the kind of thing we move", and the page calls `target()` for
     * the expensive half only once this is true.
     */
    public function isOfferable(?Server $server, ?User $user): bool
    {
        if (!$this->isEnabled() || !$server instanceof Server || !$user instanceof User) {
            return false;
        }

        if ((int) $server->owner_id !== (int) $user->getKey()) {
            return false;
        }

        if ($this->requiresPriority() && !$this->passes->isActive($user)) {
            return false;
        }

        if (!$this->sizeAllows($server)) {
            return false;
        }

        // A server with no primary allocation cannot be transferred by upstream at all
        // — `handle()` skips the allocation reservation and wings arrives to nothing.
        // Core's allocation invariant means this should not happen; the check is here
        // because the consequence of being wrong is a stranded server.
        if (!$server->allocation_id) {
            return false;
        }

        try {
            $server->validateTransferState();
        } catch (Throwable) {
            return false;
        }

        if ($this->orchestrated()) {
            try {
                return app(self::ORCHESTRATOR)->blockedReason($server, $user, 'queue') === null;
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }

    /** Is plugged-host-servers' orchestrator there to run the move? */
    public function orchestrated(): bool
    {
        return Plugins::available(self::ORCHESTRATOR);
    }

    public function requiresPriority(): bool
    {
        return (bool) config('plugged-host-queue.transfer.require_priority', true);
    }

    /**
     * Is the data directory small enough that copying it is genuinely the fast way out?
     *
     * `disk = 0` is unlimited, which we can never bound, so it is always too big.
     * A `max_disk` of 0 or less means no size limit is configured — the unlimited
     * server is still excluded, everything else passes.
     */
    public function sizeAllows(?Server $server): bool
    {
        if (!$server instanceof Server) {
            return false;
        }

        $disk = (int) $server->disk;

        if ($disk <= 0) {
            return false;
        }

        $max = (int) config('plugged-host-queue.transfer.max_disk', 3072);

        return $max <= 0 || $disk < $max;
    }

    /**
     * The node we would move this server to, or null when there is nowhere better.
     *
     * Ranked by the same measurement the queue holds starts on, so the answer is
     * "the least busy node that can take it" and not merely "a different node".
     */
    public function target(?Server $server): ?Node
    {
        if (!$server instanceof Server) {
            return null;
        }

        $candidates = $this->candidates($server);

        if ($candidates->isEmpty()) {
            return null;
        }

        // The orchestrator creates missing ports on the destination itself.
        if ($this->orchestrated()) {
            return $candidates->first();
        }

        $needed = $this->allocationsNeeded($server);

        foreach ($candidates as $node) {
            if ($this->pickAllocations($node, $needed) !== null) {
                return $node;
            }
        }

        return null;
    }

    /**
     * Viable, public, cooler nodes, best first.
     *
     * `FindViableNodesService` is upstream's own answer to "can this node hold a
     * server this size", overallocation included, so the pool starts there rather
     * than from a hand-rolled sum. What this adds is the queue's own two rules: not
     * a node that is itself over the line, and not one whose disk is over core's
     * placement threshold — moving somebody from a busy node to a full one is not a
     * fix, it is the next ticket.
     *
     * @return Collection<int, Node>
     */
    public function candidates(Server $server): Collection
    {
        try {
            $nodes = app(FindViableNodesService::class)->handle(
                (int) $server->memory,
                (int) $server->disk,
                (int) $server->cpu,
                $this->locationTags($server),
            );
        } catch (Throwable $e) {
            $this->log('viable node lookup failed', $e);

            return collect();
        }

        return $nodes
            ->reject(fn (Node $node) => (int) $node->getKey() === (int) $server->node_id)
            ->filter(fn (Node $node) => $this->sameOffer($server, $node))
            ->reject(fn (Node $node) => $node->isUnderMaintenance())
            ->reject(fn (Node $node) => $this->pressure->storagePressured($node))
            ->reject(fn (Node $node) => $this->pressure->isUnderPressure($node))
            ->sortBy(fn (Node $node) => $this->pressure->worstPercent($node))
            ->values();
    }

    /**
     * Only a node of the same location and tier (a free server must not land on a Plus or
     * Premium node) that new servers may be deployed to. Tags alone are not enough:
     * FindViableNodesService keeps any node sharing one of them, and paid nodes carry the
     * country tag too. With plugged-host-servers missing there is no tier to keep.
     */
    protected function sameOffer(Server $server, Node $node): bool
    {
        $planner = 'PluggedHost\\Servers\\Services\\Transfers\\TransferPlanner';

        if (!Plugins::available($planner)) {
            return true;
        }

        $planner = app($planner);
        $here = $planner->locationOf($server->node);
        $deployable = $planner->deploymentTags();

        return $here !== null
            && $planner->locationOf($node) === $here
            && ($deployable === [] || array_intersect($deployable, (array) ($node->tags ?? [])) !== []);
    }

    /**
     * The tags that keep a transfer inside the same location.
     *
     * `FindViableNodesService` treats tags as "any of these", so handing it the
     * current node's tags is exactly "somewhere this node also is". An untagged node
     * has no location we can prove, and with `same_location` on the offer is then
     * withheld rather than widened — silently moving somebody to another continent to
     * save them a two-minute wait is not a trade we get to make for them.
     *
     * @return array<int, string>
     */
    protected function locationTags(Server $server): array
    {
        if (!(bool) config('plugged-host-queue.transfer.same_location', true)) {
            return [];
        }

        $tags = array_values(array_filter(array_map(
            fn ($tag) => trim((string) $tag),
            (array) ($server->node?->tags ?? []),
        )));

        // An empty tag list would mean "anywhere" to FindViableNodesService, so the
        // no-location case has to be turned into a value that matches nothing.
        return $tags === [] ? ['plugged-host-queue:no-location'] : $tags;
    }

    /** Primary plus one for each extra port the server already has. */
    public function allocationsNeeded(Server $server): int
    {
        try {
            return max(1, (int) $server->allocations()->count());
        } catch (Throwable) {
            return 1;
        }
    }

    /**
     * `$needed` free allocations on one IP, or null.
     *
     * One IP on purpose. Upstream would accept a set spread across several, but a
     * server whose ports then live on two different addresses is a support ticket the
     * customer did not ask for, and refusing simply means this node is not offered.
     *
     * @return null|array<int, int>
     */
    protected function pickAllocations(Node $node, int $needed): ?array
    {
        try {
            $free = Allocation::query()
                ->where('node_id', $node->getKey())
                ->whereNull('server_id')
                ->orderBy('ip')
                ->orderBy('port')
                ->get(['id', 'ip']);
        } catch (Throwable $e) {
            $this->log('allocation lookup failed', $e);

            return null;
        }

        foreach ($free->groupBy('ip') as $group) {
            if ($group->count() >= $needed) {
                return $group->take($needed)->pluck('id')->map(intval(...))->all();
            }
        }

        return null;
    }

    /**
     * Move the server. Returns one of the RESULT_* constants.
     *
     * Everything is re-checked here rather than trusted from the render that drew the
     * button: the pass can have run out and the node can have filled up between the
     * page loading and the click.
     */
    public function execute(?Server $server, ?User $user): string
    {
        if (!$this->isOfferable($server, $user)) {
            return self::RESULT_UNAVAILABLE;
        }

        if ($this->orchestrated()) {
            return $this->executeOrchestrated($server, $user);
        }

        $needed = $this->allocationsNeeded($server);
        $node = null;
        $allocations = null;

        foreach ($this->candidates($server) as $candidate) {
            $picked = $this->pickAllocations($candidate, $needed);

            if ($picked !== null) {
                $node = $candidate;
                $allocations = $picked;

                break;
            }
        }

        if (!$node instanceof Node || $allocations === null || $allocations === []) {
            return self::RESULT_NO_NODE;
        }

        $primary = (int) array_shift($allocations);

        try {
            $moved = (bool) app(TransferServerService::class)->handle(
                $server,
                (int) $node->getKey(),
                $primary,
                $allocations,
            );
        } catch (ServerStateConflictException $e) {
            // The server changed under us — installing, restoring, or another transfer
            // already running.
            $this->log('transfer refused, server state conflict', $e);

            return self::RESULT_CONFLICT;
        } catch (Throwable $e) {
            $this->log('transfer refused', $e);

            return self::RESULT_FAILED;
        }

        if (!$moved) {
            // The node stopped being viable between `candidates()` and now. Not an
            // error, just a race the user should be told about plainly.
            return self::RESULT_NO_NODE;
        }

        Log::info('[plugged-host-queue] server transfer started from the start queue', [
            'server_id' => $server->getKey(),
            'from_node' => $server->node_id,
            'to_node' => $node->getKey(),
        ]);

        return self::RESULT_OK;
    }

    /**
     * Hand the move to plugged-host-servers: it picks among our candidates in our order,
     * reports progress on the Transfer page, retries on the next candidate if a node fails,
     * and starts the server when it lands (the user was waiting to start it).
     */
    protected function executeOrchestrated(Server $server, User $user): string
    {
        $candidates = $this->candidates($server)->map(fn (Node $node) => (int) $node->getKey())->values()->all();

        if ($candidates === []) {
            return self::RESULT_NO_NODE;
        }

        try {
            app(self::ORCHESTRATOR)->start($server, $user, null, 'queue', $candidates, true);
        } catch (Throwable $e) {
            $reason = property_exists($e, 'reason') ? (string) $e->reason : '';

            if ($reason === '') {
                $this->log('transfer refused', $e);
            }

            return match ($reason) {
                'no_node' => self::RESULT_NO_NODE,
                'busy', 'installing', 'restoring', 'suspended', 'backup_running' => self::RESULT_CONFLICT,
                '' => self::RESULT_FAILED,
                default => self::RESULT_UNAVAILABLE,
            };
        }

        Log::info('[plugged-host-queue] server transfer handed to the orchestrator from the start queue', [
            'server_id' => $server->getKey(),
            'from_node' => $server->node_id,
            'candidates' => $candidates,
        ]);

        return self::RESULT_OK;
    }

    protected function log(string $message, Throwable $e): void
    {
        Log::warning("[plugged-host-queue] {$message}", ['error' => $e->getMessage()]);
    }
}
