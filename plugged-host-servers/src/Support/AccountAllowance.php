<?php

namespace PluggedHost\Servers\Support;

use App\Exceptions\Service\Deployment\NoViableAllocationException;
use App\Exceptions\Service\Deployment\NoViableNodeException;
use App\Models\Allocation;
use App\Models\Egg;
use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use App\Services\Allocations\AssignmentService;
use App\Services\Deployment\FindViableNodesService;
use App\Services\Servers\ServerCreationService;
use Illuminate\Database\Eloquent\Collection;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Economy\Contracts\ComputePlans;
use PluggedHost\Servers\Services\LocationCatalog;
use PluggedHost\Servers\Services\NodeBalancer;
use PluggedHost\Servers\Services\NodeCapacity;
use PluggedHost\Servers\Support\PremiumAccess;

/**
 * What an account may use across all its servers, and the one way a user's server is created
 * within it (createServer + the allocation helpers).
 *
 * The allowance is the account's plan (economy's ComputePlans: set on this panel, or on
 * noprofit.space with plugged-host-noprofit): the base tier (default, or the Discord tier while
 * its Discord account is in the server) plus resources bought in the store. There are no panel-side limits on
 * top: no role limits, no server count -- how many servers fit is decided by the plan alone.
 * Without a plan (not linked, or not synced yet -- the SSO sign-in pulls it) the user has no
 * allowance, and the create pages say so.
 */
final class AccountAllowance
{
    public function __construct(
        public readonly int $cpu,
        public readonly int $memory,
        public readonly int $disk,
    ) {}

    /** @var array<int, ?self> */
    private static array $forUserMemo = [];

    /** @var array<int, array{cpu: int, memory: int, disk: int, count: int}> */
    private static array $usageMemo = [];

    public static function forgetMemo(?User $user = null): void
    {
        if ($user) {
            $id = (int) $user->getKey();
            unset(self::$forUserMemo[$id], self::$usageMemo[$id]);

            return;
        }

        self::$forUserMemo = [];
        self::$usageMemo = [];
    }

    /** @return array{cpu: int, memory: int, disk: int, count: int} */
    public static function getUsage(?User $user): array
    {
        if (!$user) {
            return ['cpu' => 0, 'memory' => 0, 'disk' => 0, 'count' => 0];
        }

        $id = (int) $user->getKey();
        if (isset(self::$usageMemo[$id])) {
            return self::$usageMemo[$id];
        }

        $row = Server::query()
            ->where('owner_id', $id)
            ->toBase()
            ->selectRaw('COUNT(*) AS count')
            ->selectRaw('COALESCE(SUM(cpu), 0) AS cpu')
            ->selectRaw('COALESCE(SUM(memory), 0) AS memory')
            ->selectRaw('COALESCE(SUM(disk), 0) AS disk')
            ->first();

        return self::$usageMemo[$id] = [
            'count' => (int) ($row?->count ?? 0),
            'cpu' => (int) ($row?->cpu ?? 0),
            'memory' => (int) ($row?->memory ?? 0),
            'disk' => (int) ($row?->disk ?? 0),
        ];
    }

    public static function forUser(?User $user): ?self
    {
        if (!$user) {
            return null;
        }

        $id = (int) $user->getKey();
        if (array_key_exists($id, self::$forUserMemo)) {
            return self::$forUserMemo[$id];
        }

        $plan = Plugins::enabled('plugged-host-economy') ? app(ComputePlans::class)->plan($user) : null;
        if ($plan === null) {
            return self::$forUserMemo[$id] = null;
        }

        // 0 reads as "unlimited" everywhere on the panel; on noprofit.space it means nothing
        // at all, so a zero plan is kept at the smallest real limit instead. PlusPlus adds its
        // CPU while it runs (MembershipService::cpuBonus; plusplus:expire takes it back off the
        // servers when it ends).
        return self::$forUserMemo[$id] = new self(
            cpu: max(1, (int) $plan['cpu'] + self::cpuBonus($user)),
            memory: max(1, (int) $plan['memory']),
            disk: max(1, (int) $plan['disk']),
        );
    }

    private static function cpuBonus(User $user): int
    {
        $membership = 'PluggedHost\\Economy\\Services\\MembershipService';

        try {
            return Plugins::available($membership) ? (int) app($membership)->cpuBonus($user) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    public function getCpuLeft(?User $user): ?int
    {
        if (!$user) {
            return null;
        }

        $usage = self::getUsage($user);

        return $this->resourceLeft($this->cpu, $usage['cpu']);
    }

    public function getMemoryLeft(?User $user): ?int
    {
        if (!$user) {
            return null;
        }

        $usage = self::getUsage($user);

        return $this->resourceLeft($this->memory, $usage['memory']);
    }

    public function getDiskLeft(?User $user): ?int
    {
        if (!$user) {
            return null;
        }

        $usage = self::getUsage($user);

        return $this->resourceLeft($this->disk, $usage['disk']);
    }

    private function resourceLeft(int $limit, int $used): ?int
    {
        return $limit > 0 ? max(0, $limit - $used) : null;
    }

    /**
     * How far past their plan a user's existing servers already reach.
     *
     * `getCpuLeft()` and friends clamp at 0, which is right for "how much more may
     * you allocate" but hides the case this reports: a plan that shrank *below*
     * what is already allocated -- the Discord tier lost (left the server or
     * unlinked), a tier lowered on noprofit.space, or resources repossessed. The panel
     * does not retroactively resize servers, so such a user sits at e.g. 20 GB used
     * against a 16 GB plan until they delete or shrink something.
     *
     * That state is survivable by design -- the Resources tab on ServerSettings bounds
     * a resize at `$server->{resource} + left`, so an over-quota owner can always keep
     * or lower their current values, just never raise them -- but it must be visible
     * rather than presented as "buy more to continue".
     *
     * @return array{cpu: int, memory: int, disk: int} positive overage per resource, 0 when inside the plan
     */
    public function getOverage(?User $user): array
    {
        if (!$user) {
            return ['cpu' => 0, 'memory' => 0, 'disk' => 0];
        }

        $usage = self::getUsage($user);

        return [
            'cpu' => $this->resourceOverage($this->cpu, $usage['cpu']),
            'memory' => $this->resourceOverage($this->memory, $usage['memory']),
            'disk' => $this->resourceOverage($this->disk, $usage['disk']),
        ];
    }

    public function isOverQuota(?User $user): bool
    {
        return array_sum($this->getOverage($user)) > 0;
    }

    private function resourceOverage(int $limit, int $used): int
    {
        // 0 means unlimited for cpu/memory/disk, so nothing can exceed it.
        return $limit > 0 ? max(0, $used - $limit) : 0;
    }

    public function canCreateServer(?User $user, int $cpu, int $memory, int $disk): bool
    {
        if (!$user) {
            return false;
        }

        $usage = self::getUsage($user);

        if (!$this->resourceCanBeAllocated($this->cpu, $usage['cpu'], $cpu)) {
            return false;
        }

        if (!$this->resourceCanBeAllocated($this->memory, $usage['memory'], $memory)) {
            return false;
        }

        return $this->resourceCanBeAllocated($this->disk, $usage['disk'], $disk);
    }

    private function resourceCanBeAllocated(int $limit, int $used, int $requested): bool
    {
        return $limit === 0 || ($requested > 0 && $used + $requested <= $limit);
    }

    public static function isCfxEgg(Egg $egg): bool
    {
        $tags = is_array($egg->tags) ? $egg->tags : (is_string($egg->tags) ? json_decode($egg->tags, true) : []);
        if (!is_array($tags)) {
            return false;
        }

        return collect($tags)
            ->filter(fn ($t) => is_string($t) || is_numeric($t))
            ->map(fn ($tag) => strtolower(trim((string) $tag)))
            ->contains('cfx');
    }

    /** @param array<string, mixed> $variables */
    public function createServer(User $user, string $name, int|Egg $egg, int $cpu, int $memory, int $disk, array $variables = [], ?string $location = null, ?string $startup = null, ?string $image = null): Server|false
    {
        if (!$this->canCreateServer($user, $cpu, $memory, $disk)) {
            return false;
        }

        // A Plus location needs plugged.host Plus, a Premium one PlusPlus. This is the
        // server-side authority — the create page's card state is only presentation.
        if ($location !== null
            && !PremiumAccess::isUnlocked($user, app(LocationCatalog::class)->resolve($location)[1])) {
            return false;
        }

        if (!$egg instanceof Egg) {
            $egg = Egg::findOrFail($egg);
        }

        $isCfx = self::isCfxEgg($egg);

        $environment = [];
        foreach ($egg->variables as $variable) {
            if ($variable->env_variable === 'TXHOST_TXA_PORT') {
                continue;
            }

            $isEditable = (bool) ($variable->user_editable ?? false);
            $environment[$variable->env_variable] = ($isEditable && isset($variables[$variable->env_variable]))
                ? $variables[$variable->env_variable]
                : ($variable->default_value ?? '');
        }

        $data = [
            'name' => $name,
            'owner_id' => $user->id,
            'egg_id' => $egg->id,
            'cpu' => $cpu,
            'memory' => $memory,
            'disk' => $disk,
            'swap' => 0,
            'io' => 500,
            'environment' => $environment,
            'skip_scripts' => false,
            'start_on_completion' => true,
            'oom_killer' => false,
            'database_limit' => config('plugged-host-servers.user-creatable-servers.database_limit', 0),
            'allocation_limit' => max(
                (int) config('plugged-host-servers.user-creatable-servers.allocation_limit', 0),
                $isCfx ? 2 : 1
            ),
            'backup_limit' => config('plugged-host-servers.user-creatable-servers.backup_limit', 0),
        ];

        // Pass startup/image explicitly so ServerCreationService's
        // `$data['startup'] ??= Arr::first($egg->startup_commands)` fallback does not
        // silently pick the first command — a Forge server would otherwise boot with
        // the jar startup line. Only override when the caller supplied a concrete value.
        if (is_string($startup) && $startup !== '') {
            $data['startup'] = $startup;
        }

        if (is_string($image) && $image !== '') {
            $data['image'] = $image;
        }

        $location = is_string($location) && $location !== '' ? strtolower(trim($location)) : null;

        if ($isCfx) {
            [$primaryAllocation, $secondaryAllocation] = $this->findOrCreateAllocationsForCfx(
                (int) $data['memory'],
                (int) $data['disk'],
                (int) $data['cpu'],
                $location
            );

            $primaryAllocation->update(['is_locked' => true]);
            $secondaryAllocation->update(['is_locked' => true]);

            $data['node_id'] = $primaryAllocation->node_id;
            $data['allocation_id'] = $primaryAllocation->id;
            $data['allocation_additional'] = [$secondaryAllocation->id];
            $data['environment']['TXHOST_TXA_PORT'] = (string) $secondaryAllocation->port;
        } else {
            $allocation = $this->findOrCreateAllocation((int) $data['memory'], (int) $data['disk'], (int) $data['cpu'], $location);
            $allocation->update(['is_locked' => true]);
            $data['node_id'] = $allocation->node_id;
            $data['allocation_id'] = $allocation->id;
        }

        /** @var ServerCreationService $service */
        $service = app(ServerCreationService::class);

        return $service->handle($data);
    }

    /**
     * Narrow a viable-node collection to those carrying the requested location tag.
     *
     * Location must be applied as an AND filter on top of the deployment-tag result,
     * NOT merged into the tags passed to FindViableNodesService — that service matches
     * a node if its tags intersect *any* passed tag, so adding the location there would
     * widen the pool instead of restricting it.
     *
     * @param  Collection<int, Node>  $nodes
     * @return Collection<int, Node>
     */
    private function filterNodesByLocation(Collection $nodes, ?string $location): Collection
    {
        if ($location === null) {
            return $nodes;
        }

        $catalog = app(LocationCatalog::class);
        [$country, $tier] = $catalog->resolve($location);

        return $nodes->filter(function (Node $node) use ($country, $tier, $catalog) {
            $tags = collect($node->tags ?? [])->map(fn ($tag) => strtolower(trim((string) $tag)));

            if (!$tags->contains($country)) {
                return false;
            }

            // WP13: the tiers never bleed into each other — a free `pl` deploy never lands on
            // paid hardware, and a `plus:pl` / `premium:de` deploy only lands on that tier's nodes.
            return $catalog->nodeTier($tags) === $tier;
        })->values();
    }

    /**
     * Drop nodes whose disk is under real pressure right now.
     *
     * `FindViableNodesService` already checked that the *committed* sums leave room,
     * which is a different question: a node can be 40% allocated and 96% full because
     * of what the containers actually wrote. A server placed there fills the disk for
     * everyone on it, so it comes out of the pool before a port is ever picked.
     *
     * Fails open by design. The measurement comes from `Node::statistics()`, which
     * answers all-zeros when wings does not reply within its one-second timeout, and
     * an unreachable node must not be *excluded* on the strength of a reading we did
     * not get. Emptying an otherwise usable pool would also turn a monitoring blip
     * into "no nodes available" for every user at once, so the whole filter is
     * discarded rather than applied when it would leave nothing — unless the operator
     * has set NODES_STORAGE_GUARD_STRICT, which says a node with no reading is a node
     * we refuse to deploy to.
     *
     * @param  Collection<int, Node>  $nodes
     * @return Collection<int, Node>
     */
    private function filterNodesByStorage(Collection $nodes): Collection
    {
        if ($nodes->isEmpty()) {
            return $nodes;
        }

        $capacity = app(NodeCapacity::class);

        $roomy = $nodes->reject(fn (Node $node) => $capacity->storagePressured($node))->values();

        if ($roomy->isNotEmpty()) {
            return $roomy;
        }

        return $capacity->storageGuardIsStrict() ? $roomy : $nodes;
    }

    /** @return array{Allocation, Allocation} */
    private function findOrCreateAllocationsForCfx(int $memory, int $disk, int $cpu, ?string $location = null): array
    {
        $tags = array_filter(explode(',', (string) config('plugged-host-servers.user-creatable-servers.deployment_tags')));
        $nodes = app(FindViableNodesService::class)->handle($memory, $disk, $cpu, $tags);
        $nodes = $this->filterNodesByLocation($nodes, $location);
        $nodes = $this->filterNodesByStorage($nodes);

        throw_if($nodes->isEmpty(), new NoViableNodeException(trans('exceptions.deployment.no_viable_nodes')));

        $ranges = self::deploymentPortRanges();
        /** @var Node $node */
        foreach (app(NodeBalancer::class)->sortByLowestUsage($nodes) as $node) {
            $ip = $this->nodeAllocationIp($node);
            if ($ip === null) {
                continue;
            }

            $available = Allocation::withoutGlobalScopes()
                ->whereNull('server_id')
                ->where('node_id', $node->id)
                ->where('ip', $ip)
                ->where(function ($query) use ($ranges) {
                    foreach ($ranges as [$start, $end]) {
                        $query->orWhereBetween('port', [$start, $end]);
                    }
                })
                ->orderBy('port')
                ->get();

            // 1. Look for an existing unassigned pair where second = first + 1
            foreach ($available as $first) {
                $second = $available->firstWhere('port', $first->port + 1);
                if ($second) {
                    return [$first, $second];
                }
            }

            // 2. Look for an unassigned allocation where first + 1 is free to create
            $used = Allocation::withoutGlobalScopes()
                ->where('node_id', $node->id)
                ->where('ip', $ip)
                ->pluck('port')
                ->map(fn ($p) => (int) $p)
                ->flip()
                ->all();

            foreach ($available as $first) {
                $nextPort = $first->port + 1;
                $inRange = false;
                foreach ($ranges as [$start, $end]) {
                    if ($nextPort >= $start && $nextPort <= $end) {
                        $inRange = true;
                        break;
                    }
                }
                if ($inRange && !isset($used[$nextPort])) {
                    $ids = app(AssignmentService::class)->handle($node, [
                        'allocation_ip' => $ip,
                        'allocation_ports' => [(string) $nextPort],
                        'allocation_alias' => $node->fqdn,
                    ]);

                    if (!empty($ids)) {
                        $second = Allocation::withoutGlobalScopes()->findOrFail($ids[0]);

                        return [$first, $second];
                    }
                }
            }

            // 3. Find a new consecutive pair [p, p + 1]
            $consecutive = $this->findAvailableConsecutivePorts($node, $ip, $ranges, 2);
            if (count($consecutive) === 2) {
                $ids = app(AssignmentService::class)->handle($node, [
                    'allocation_ip' => $ip,
                    'allocation_ports' => array_map('strval', $consecutive),
                    'allocation_alias' => $node->fqdn,
                ]);

                if (count($ids) >= 2) {
                    $first = Allocation::withoutGlobalScopes()->findOrFail($ids[0]);
                    $second = Allocation::withoutGlobalScopes()->findOrFail($ids[1]);

                    return [$first, $second];
                }
            }

            // Fallback: any available pair
            if ($available->count() >= 2) {
                return [$available[0], $available[1]];
            }
        }

        throw new NoViableAllocationException(trans('exceptions.deployment.no_viable_allocations'));
    }

    /**
     * @param  array<int, array{int, int}>  $ranges
     * @return int[]
     */
    private function findAvailableConsecutivePorts(Node $node, string $ip, array $ranges, int $count = 2): array
    {
        $used = Allocation::withoutGlobalScopes()
            ->where('node_id', $node->id)
            ->where('ip', $ip)
            ->pluck('port')
            ->map(fn ($port) => (int) $port)
            ->flip()
            ->all();

        foreach ($ranges as [$start, $end]) {
            for ($port = $start; $port <= $end - ($count - 1); $port++) {
                $match = true;
                for ($i = 0; $i < $count; $i++) {
                    if (isset($used[$port + $i])) {
                        $match = false;
                        $port += $i;
                        break;
                    }
                }
                if ($match) {
                    $res = [];
                    for ($i = 0; $i < $count; $i++) {
                        $res[] = $port + $i;
                    }

                    return $res;
                }
            }
        }

        return [];
    }

    /**
     * @param  array<int, array{int, int}>  $ranges
     * @param  int[]  $excludePorts
     */
    private function findAvailablePortExcluding(Node $node, string $ip, array $ranges, array $excludePorts = []): ?int
    {
        $ports = $this->findAvailablePorts($node, $ip, $ranges, 1, $excludePorts);

        return $ports[0] ?? null;
    }

    /**
     * @param  array<int, array{int, int}>  $ranges
     * @param  int[]  $excludePorts
     * @return int[]
     */
    private function findAvailablePorts(Node $node, string $ip, array $ranges, int $count = 1, array $excludePorts = []): array
    {
        $used = Allocation::withoutGlobalScopes()
            ->where('node_id', $node->id)
            ->where('ip', $ip)
            ->pluck('port')
            ->map(fn ($port) => (int) $port)
            ->flip()
            ->all();

        foreach ($excludePorts as $ex) {
            $used[(int) $ex] = true;
        }

        $results = [];
        foreach ($ranges as [$start, $end]) {
            for ($port = $start; $port <= $end; $port++) {
                if (!isset($used[$port])) {
                    $results[] = $port;
                    $used[$port] = true;
                    if (count($results) >= $count) {
                        return $results;
                    }
                }
            }
        }

        return $results;
    }

    private function findOrCreateAllocation(int $memory, int $disk, int $cpu, ?string $location = null): Allocation
    {
        $tags = array_filter(explode(',', (string) config('plugged-host-servers.user-creatable-servers.deployment_tags')));
        $nodes = app(FindViableNodesService::class)->handle($memory, $disk, $cpu, $tags);
        $nodes = $this->filterNodesByLocation($nodes, $location);
        $nodes = $this->filterNodesByStorage($nodes);

        throw_if($nodes->isEmpty(), new NoViableNodeException(trans('exceptions.deployment.no_viable_nodes')));

        $ranges = self::deploymentPortRanges();
        /** @var Node $node */
        foreach (app(NodeBalancer::class)->sortByLowestUsage($nodes) as $node) {
            $ip = $this->nodeAllocationIp($node);
            if ($ip === null) {
                continue;
            }

            $allocation = $this->findAvailableAllocation($node, $ip, $ranges);
            if ($allocation) {
                return $allocation;
            }

            $port = $this->findAvailablePort($node, $ip, $ranges);
            if ($port === null) {
                continue;
            }

            $ids = app(AssignmentService::class)->handle($node, [
                'allocation_ip' => $ip,
                'allocation_ports' => [(string) $port],
                'allocation_alias' => $node->fqdn,
            ]);

            if (!empty($ids)) {
                return Allocation::withoutGlobalScopes()->findOrFail($ids[0]);
            }
        }

        throw new NoViableAllocationException(trans('exceptions.deployment.no_viable_allocations'));
    }

    /**
     * The port ranges new allocations may be created in (UCS_DEPLOYMENT_PORTS, else the
     * panel's client allocation range, else the whole legal range). Public for the transfer
     * planner, which creates allocations on the destination node by the same rule.
     *
     * @return array<int, array{int, int}>
     */
    public static function deploymentPortRanges(): array
    {
        $configured = array_filter(array_map('trim', explode(',', (string) config('plugged-host-servers.user-creatable-servers.deployment_ports'))));
        $ranges = [];

        foreach ($configured as $port) {
            if (preg_match('/^([0-9]+)-([0-9]+)$/', $port, $matches)) {
                $start = (int) $matches[1];
                $end = (int) $matches[2];
            } elseif (preg_match('/^[0-9]+$/', $port)) {
                $start = $end = (int) $port;
            } else {
                continue;
            }

            if ($start < AssignmentService::PORT_FLOOR || $end > AssignmentService::PORT_CEIL || $start > $end) {
                continue;
            }

            $ranges[] = [$start, $end];
        }

        if ($ranges) {
            return $ranges;
        }

        $start = config('panel.client_features.allocations.range_start');
        $end = config('panel.client_features.allocations.range_end');
        if (is_numeric($start) && is_numeric($end)) {
            $start = max(AssignmentService::PORT_FLOOR, (int) $start);
            $end = min(AssignmentService::PORT_CEIL, (int) $end);
            if ($start <= $end) {
                return [[$start, $end]];
            }
        }

        return [[AssignmentService::PORT_FLOOR, AssignmentService::PORT_CEIL]];
    }

    /** @param array<int, array{int, int}> $ranges */
    private function findAvailableAllocation(Node $node, string $ip, array $ranges): ?Allocation
    {
        return Allocation::withoutGlobalScopes()
            ->whereNull('server_id')
            ->where('node_id', $node->id)
            ->where('ip', $ip)
            ->where(function ($query) use ($ranges) {
                foreach ($ranges as [$start, $end]) {
                    $query->orWhereBetween('port', [$start, $end]);
                }
            })
            ->inRandomOrder()
            ->first();
    }

    /** @param array<int, array{int, int}> $ranges */
    private function findAvailablePort(Node $node, string $ip, array $ranges): ?int
    {
        $used = Allocation::withoutGlobalScopes()
            ->where('node_id', $node->id)
            ->where('ip', $ip)
            ->pluck('port')
            ->map(fn ($port) => (int) $port)
            ->flip();

        foreach ($ranges as [$start, $end]) {
            $available = [];
            for ($port = $start; $port <= $end; $port++) {
                if (!$used->has($port)) {
                    $available[] = $port;
                }
            }

            if ($available) {
                return $available[array_rand($available)];
            }
        }

        return null;
    }

    /**
     * The IP that allocations created for a freshly-provisioned server bind to.
     *
     * Always 0.0.0.0: wings binds every interface, and the address a player actually dials is
     * carried by the allocation *alias* (the node's fqdn, e.g. de1.plugged.host), set on every
     * created allocation below. Resolving the node's hostname to a concrete address instead — the
     * old behaviour — pinned each server to a single IP (79.76.124.124), which is what had to be
     * changed by hand on every new server, and which breaks the moment the node is renumbered.
     *
     * Returned as a nullable string to keep the two callers' "skip this node" contract, but with a
     * constant it never actually returns null.
     */
    private function nodeAllocationIp(Node $node): ?string
    {
        return '0.0.0.0';
    }
}
