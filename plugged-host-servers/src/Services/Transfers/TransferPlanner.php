<?php

namespace PluggedHost\Servers\Services\Transfers;

use App\Models\Allocation;
use App\Models\Node;
use App\Models\Server;
use App\Services\Allocations\AssignmentService;
use App\Services\Deployment\FindViableNodesService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use PluggedHost\Core\Support\Countries;
use PluggedHost\Servers\Models\RoleResourceLimits;
use PluggedHost\Servers\Services\LocationCatalog;
use PluggedHost\Servers\Services\NodeBalancer;
use PluggedHost\Servers\Services\NodeCapacity;
use Throwable;

/**
 * Where a server can move to: which locations to offer, which node inside a location, and
 * which ports it gets there.
 *
 * The rules are the ones server creation uses (RoleResourceLimits::findOrCreateAllocation):
 * the deployment tags, the location as an AND filter with the tiers kept apart, nodes with a
 * full disk left out unless that would empty the pool, and the coolest node by NodeBalancer
 * first. A move must never land a server somewhere creation would not have put it.
 */
class TransferPlanner
{
    /** Allocations are bound to every interface; the alias carries the reachable address. */
    public const BIND_IP = '0.0.0.0';

    public function __construct(
        protected LocationCatalog $catalog,
        protected NodeCapacity $capacity,
        protected NodeBalancer $balancer,
    ) {}

    /**
     * The location code a node belongs to (`nl`, `plus:pl`), or null for a node without a
     * country tag, which no location offers.
     */
    public function locationOf(?Node $node): ?string
    {
        if (!$node instanceof Node) {
            return null;
        }

        $tags = $this->tags($node);
        $tier = $this->catalog->nodeTier($tags);

        foreach ($tags as $tag) {
            if (Countries::isCode($tag)) {
                return $tier === LocationCatalog::FREE ? $tag : "$tier:$tag";
            }
        }

        return null;
    }

    /**
     * Every location the catalog offers, sized for this server, with `current` set on the
     * one it is in. The current location is only available when it has another node.
     * Tier unlocks are the owner's: the server is theirs, whoever presses the button.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function locations(Server $server): Collection
    {
        $current = $this->locationOf($server->node);

        try {
            $locations = $this->catalog->available((int) $server->cpu, (int) $server->memory, (int) $server->disk, $server->user);
        } catch (Throwable $e) {
            Log::warning('[plugged-host-servers] transfer locations failed', ['error' => $e->getMessage()]);

            return collect();
        }

        return $locations->map(function (array $location) use ($current) {
            $location['current'] = $location['code'] === $current;

            if ($location['current']) {
                $location['available'] = $location['available'] && (int) $location['node_count'] > 1;
            }

            return $location;
        })->values();
    }

    /**
     * Nodes this server could move to, best first.
     *
     * @param  string|null  $location  a location code, or null for "anywhere" (only with $only)
     * @param  array<int, int>  $avoid  nodes an earlier attempt failed on: tried last, not never,
     *                                  because a node that dropped a connection once is still
     *                                  better than giving up
     * @param  array<int, int>|null  $only  restrict to these nodes, in this order (the start
     *                                      queue's "quieter node" offer picks its own)
     * @return Collection<int, Node>
     */
    public function destinations(Server $server, ?string $location, array $avoid = [], ?array $only = null): Collection
    {
        try {
            $nodes = $only !== null
                ? $this->viableAmong($server, $only)
                : app(FindViableNodesService::class)->handle((int) $server->memory, (int) $server->disk, (int) $server->cpu, $this->deploymentTags());
        } catch (Throwable $e) {
            Log::warning('[plugged-host-servers] transfer node lookup failed', ['error' => $e->getMessage()]);

            return collect();
        }

        $nodes = $nodes
            ->reject(fn (Node $node) => (int) $node->getKey() === (int) $server->node_id)
            ->reject(fn (Node $node) => $node->isUnderMaintenance())
            ->values();

        if ($location !== null) {
            $nodes = $this->inLocation($nodes, $location);
        }

        $nodes = $this->withRoom($nodes);

        $ordered = $only !== null
            ? $nodes->sortBy(fn (Node $node) => array_search((int) $node->getKey(), array_map(intval(...), $only), true))->values()
            : $this->balancer->sortByLowestUsage($nodes);

        $avoid = array_map(intval(...), $avoid);

        // usort is stable, so the balancer's order survives inside each group.
        return $ordered
            ->sortBy(fn (Node $node) => in_array((int) $node->getKey(), $avoid, true) ? 1 : 0)
            ->values();
    }

    /**
     * Free allocations on $node for every allocation the server has now, all on the bind IP,
     * keeping the port numbers wherever they are free there (so a player who only has to
     * change the hostname does not also have to change the port). Missing ones are created
     * inside the deployment port ranges. Nothing is reserved here: TransferService claims
     * them in the same transaction that creates the transfer.
     *
     * @return null|array{primary: int, additional: array<int, int>, ports: array<int, int>}
     */
    public function allocationsOn(Server $server, Node $node): ?array
    {
        $current = $this->currentAllocations($server);

        if ($current->isEmpty()) {
            return null;
        }

        $ranges = RoleResourceLimits::deploymentPortRanges();
        $existing = Allocation::withoutGlobalScopes()
            ->where('node_id', $node->getKey())
            ->where('ip', self::BIND_IP)
            ->get(['id', 'port', 'server_id'])
            ->keyBy(fn (Allocation $allocation) => (int) $allocation->port);

        $picked = [];
        $claimed = [];

        // 1. The same port number, when it is free (or can be created) on the new node.
        foreach ($current as $index => $allocation) {
            $port = (int) $allocation->port;
            $there = $existing->get($port);

            if ($there instanceof Allocation) {
                if ($there->server_id === null) {
                    $picked[$index] = (int) $there->getKey();
                    $claimed[$port] = true;
                }

                continue;
            }

            if ($this->inRanges($port, $ranges)) {
                $picked[$index] = $port * -1; // negative: create this port
                $claimed[$port] = true;
            }
        }

        // 2. Anything left: a free allocation already in range, else a new free port.
        foreach ($current as $index => $allocation) {
            if (isset($picked[$index])) {
                continue;
            }

            $free = $existing->first(fn (Allocation $a, int $port) => $a->server_id === null && !isset($claimed[$port]) && $this->inRanges($port, $ranges));

            if ($free instanceof Allocation) {
                $picked[$index] = (int) $free->getKey();
                $claimed[(int) $free->port] = true;

                continue;
            }

            $port = $this->freePort($existing, $claimed, $ranges);

            if ($port === null) {
                return null;
            }

            $picked[$index] = $port * -1;
            $claimed[$port] = true;
        }

        try {
            $ids = $this->create($node, $picked);
        } catch (Throwable $e) {
            Log::warning('[plugged-host-servers] transfer allocation create failed', ['node' => $node->getKey(), 'error' => $e->getMessage()]);

            return null;
        }

        ksort($ids);
        $ids = array_values($ids);

        $ports = Allocation::withoutGlobalScopes()->whereIn('id', $ids)->pluck('port', 'id')->map(intval(...))->all();

        return [
            'primary' => (int) array_shift($ids),
            'additional' => array_map(intval(...), $ids),
            'ports' => $ports,
        ];
    }

    /**
     * The server's allocations, primary first, the rest in id order: the order the transfer
     * records them in, so old and new line up index for index.
     *
     * @return Collection<int, Allocation>
     */
    public function currentAllocations(Server $server): Collection
    {
        $all = Allocation::withoutGlobalScopes()->where('server_id', $server->getKey())->orderBy('id')->get();
        $primary = $all->firstWhere('id', $server->allocation_id);

        if (!$primary instanceof Allocation) {
            return collect();
        }

        return collect([$primary])->concat($all->reject(fn (Allocation $a) => (int) $a->getKey() === (int) $primary->getKey()))->values();
    }

    /** @return array<int, string> */
    public function deploymentTags(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) config('plugged-host-servers.user-creatable-servers.deployment_tags')))));
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, Node>
     */
    protected function viableAmong(Server $server, array $ids): Collection
    {
        return Node::query()
            ->whereIn('id', $ids)
            ->withSum('servers', 'disk')
            ->withSum('servers', 'memory')
            ->withSum('servers', 'cpu')
            ->get()
            ->filter(fn (Node $node) => $node->isViable((int) $server->memory, (int) $server->disk, (int) $server->cpu))
            ->values();
    }

    /**
     * @param  Collection<int, Node>  $nodes
     * @return Collection<int, Node>
     */
    protected function inLocation(Collection $nodes, string $location): Collection
    {
        [$country, $tier] = $this->catalog->resolve($location);

        return $nodes->filter(function (Node $node) use ($country, $tier) {
            $tags = $this->tags($node);

            return in_array($country, $tags, true) && $this->catalog->nodeTier($tags) === $tier;
        })->values();
    }

    /**
     * Leave out nodes whose disk is under real pressure, unless that leaves nothing and the
     * operator has not asked for the strict guard (the same fail-open rule creation uses).
     *
     * @param  Collection<int, Node>  $nodes
     * @return Collection<int, Node>
     */
    protected function withRoom(Collection $nodes): Collection
    {
        if ($nodes->isEmpty()) {
            return $nodes;
        }

        $roomy = $nodes->reject(fn (Node $node) => $this->capacity->storagePressured($node))->values();

        if ($roomy->isNotEmpty()) {
            return $roomy;
        }

        return $this->capacity->storageGuardIsStrict() ? $roomy : $nodes;
    }

    /** @return array<int, string> */
    protected function tags(Node $node): array
    {
        return array_values(array_filter(array_map(
            fn ($tag) => (is_string($tag) || is_numeric($tag)) ? strtolower(trim((string) $tag)) : '',
            (array) ($node->tags ?? []),
        )));
    }

    /** @param array<int, array{int, int}> $ranges */
    protected function inRanges(int $port, array $ranges): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($port >= $start && $port <= $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, Allocation>  $existing  keyed by port
     * @param  array<int, bool>  $claimed
     * @param  array<int, array{int, int}>  $ranges
     */
    protected function freePort(Collection $existing, array $claimed, array $ranges): ?int
    {
        foreach ($ranges as [$start, $end]) {
            for ($port = $start; $port <= $end; $port++) {
                if (!$existing->has($port) && !isset($claimed[$port])) {
                    return $port;
                }
            }
        }

        return null;
    }

    /**
     * Turn the picks into allocation ids, creating the negative (port-to-create) ones.
     *
     * @param  array<int, int>  $picked  index => allocation id, or -port to create
     * @return array<int, int> index => allocation id
     */
    protected function create(Node $node, array $picked): array
    {
        $ids = [];

        foreach ($picked as $index => $value) {
            if ($value > 0) {
                $ids[$index] = $value;

                continue;
            }

            $created = app(AssignmentService::class)->handle($node, [
                'allocation_ip' => self::BIND_IP,
                'allocation_ports' => [(string) ($value * -1)],
                'allocation_alias' => $node->fqdn,
            ]);

            $ids[$index] = (int) $created[0];
        }

        return $ids;
    }
}
