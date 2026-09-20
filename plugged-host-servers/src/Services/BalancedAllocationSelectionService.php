<?php

namespace PluggedHost\Servers\Services;

use App\Exceptions\Service\Deployment\NoViableAllocationException;
use App\Models\Allocation;
use App\Models\Node;
use App\Services\Deployment\AllocationSelectionService;

/**
 * Extends Pelican's AllocationSelectionService to allocate from the least-used node.
 *
 * Upstream Pelican's AllocationSelectionService picks an allocation randomly from any
 * viable node (`$query->inRandomOrder()->first()`). When multiple nodes are viable,
 * this service sorts those candidate nodes by lowest 5-minute average RAM & CPU usage
 * using NodeBalancer, selecting an allocation from the coolest node first.
 */
class BalancedAllocationSelectionService extends AllocationSelectionService
{
    public function handle(): Allocation
    {
        if (empty($this->nodes)) {
            return parent::handle();
        }

        // Fetch candidate nodes and sort them by lowest rolling usage
        $nodes = Node::query()->whereIn('id', $this->nodes)->get();
        $sortedNodes = app(NodeBalancer::class)->sortByLowestUsage($nodes);

        foreach ($sortedNodes as $node) {
            $allocation = $this->getAllocationForNode((int) $node->getKey(), $this->ports, $this->dedicated);

            if ($allocation instanceof Allocation) {
                return $allocation;
            }
        }

        // If no allocation was found on the sorted nodes, fall back to upstream handle()
        // which throws NoViableAllocationException or checks without restriction.
        return parent::handle();
    }

    /**
     * Find an available allocation on a specific node matching port and dedicated constraints.
     *
     * @param  array<string|int|array<int|string>>  $ports
     */
    protected function getAllocationForNode(int $nodeId, array $ports = [], bool $dedicated = false): ?Allocation
    {
        $query = Allocation::query()
            ->whereNull('server_id')
            ->where('node_id', $nodeId);

        if (!empty($ports)) {
            $query->where(function ($inner) use ($ports) {
                $whereIn = [];
                foreach ($ports as $port) {
                    if (is_array($port)) {
                        $inner->orWhereBetween('port', $port);
                        continue;
                    }

                    $whereIn[] = $port;
                }

                if (!empty($whereIn)) {
                    $inner->orWhereIn('port', $whereIn);
                }
            });
        }

        if ($dedicated) {
            $discard = Allocation::query()
                ->whereNotNull('server_id')
                ->where('node_id', $nodeId)
                ->groupBy('ip')
                ->pluck('ip')
                ->toArray();

            if (!empty($discard)) {
                $query->whereNotIn('ip', $discard);
            }
        }

        return $query->inRandomOrder()->first();
    }
}

