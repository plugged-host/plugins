<?php

namespace PluggedHost\Servers\Services;

use App\Models\Node;
use Illuminate\Support\Collection;
use PluggedHost\Core\Nodes\NodeTelemetry;
use Throwable;

/**
 * Balances node allocation across viable nodes in a location.
 *
 * Instead of allocating randomly or by promised/committed allocation limits (where
 * identical nodes can have vastly different real container loads), this service
 * evaluates nodes by their rolling 5-minute average of RAM and CPU usage.
 *
 * It uses the minute-by-minute node telemetry core samples (NodeTelemetry).
 * If a node lacks snapshot history (e.g. newly joined), it falls back to live daemon
 * statistics (`NodeCapacity`). Unreachable or failing nodes are penalized so healthy
 * nodes are selected first.
 */
class NodeBalancer
{
    public function __construct(
        protected NodeCapacity $capacity
    ) {}

    /**
     * Whether load balancing by rolling usage is enabled.
     */
    public function isEnabled(): bool
    {
        return (bool) config('plugged-host-servers.balance.enabled', true);
    }

    /**
     * Window in minutes for calculating rolling usage averages.
     */
    public function windowMinutes(): int
    {
        $minutes = (int) config('plugged-host-servers.balance.window_minutes', 5);

        return $minutes > 0 ? $minutes : 5;
    }

    /**
     * Sort a collection of nodes so that the node with the lowest sustained
     * 5-minute average of RAM & CPU is first.
     *
     * @param  Collection<int, Node>  $nodes
     * @return Collection<int, Node>
     */
    public function sortByLowestUsage(Collection $nodes, ?int $minutes = null): Collection
    {
        if ($nodes->count() <= 1) {
            return $nodes;
        }

        if (!$this->isEnabled()) {
            return $nodes->shuffle();
        }

        $minutes ??= $this->windowMinutes();
        $metrics = $this->metricsForNodes($nodes, $minutes);

        // Sort by composite score ascending. For nodes with virtually identical scores
        // (within 0.05%), apply a random tie-breaker to distribute allocation across
        // equally idle nodes without dogpiling.
        return $nodes->sort(function (Node $a, Node $b) use ($metrics) {
            $scoreA = $metrics->get($a->getKey())['score'] ?? 999999.0;
            $scoreB = $metrics->get($b->getKey())['score'] ?? 999999.0;

            if (abs($scoreA - $scoreB) < 0.05) {
                return mt_rand(0, 1) === 1 ? 1 : -1;
            }

            return $scoreA <=> $scoreB;
        })->values();
    }

    /**
     * Fetch rolling metrics for multiple nodes in a single efficient query.
     *
     * @param  Collection<int, Node>|array<int, Node>  $nodes
     * @return Collection<int, array{
     *     node_id: int,
     *     cpu_used_avg: float,
     *     cpu_total_avg: float,
     *     cpu_percent: float,
     *     memory_used_bytes_avg: float,
     *     memory_used_gb_avg: float,
     *     memory_total_bytes_avg: float,
     *     memory_percent: float,
     *     score: float,
     *     samples: int,
     *     source: string
     * }> Keyed by node_id
     */
    public function metricsForNodes(Collection|array $nodes, ?int $minutes = null): Collection
    {
        $nodesCollection = $nodes instanceof Collection ? $nodes : collect($nodes);
        if ($nodesCollection->isEmpty()) {
            return collect();
        }

        $minutes ??= $this->windowMinutes();
        $nodeIds = $nodesCollection->map(fn (Node $node) => (int) $node->getKey())->all();

        $snapshotsByNode = $this->fetchRecentSnapshots($nodeIds, $minutes);

        $results = collect();
        foreach ($nodesCollection as $node) {
            $nodeId = (int) $node->getKey();
            $snapshots = $snapshotsByNode->get($nodeId, collect());

            $results->put($nodeId, $this->calculateMetrics($node, $snapshots));
        }

        return $results;
    }

    /**
     * Fetch rolling metrics for a single node.
     *
     * @return array{
     *     node_id: int,
     *     cpu_used_avg: float,
     *     cpu_total_avg: float,
     *     cpu_percent: float,
     *     memory_used_bytes_avg: float,
     *     memory_used_gb_avg: float,
     *     memory_total_bytes_avg: float,
     *     memory_percent: float,
     *     score: float,
     *     samples: int,
     *     source: string
     * }
     */
    public function metricsFor(Node $node, ?int $minutes = null): array
    {
        return $this->metricsForNodes([$node], $minutes)->get((int) $node->getKey());
    }

    /**
     * Compute CPU%, RAM% (and GB), and composite score from snapshots or fallback to live usage.
     *
     * @param  Collection<int, mixed>  $snapshots
     * @return array<string, mixed>
     */
    protected function calculateMetrics(Node $node, Collection $snapshots): array
    {
        $valid = $snapshots->filter(function ($s) {
            $metrics = is_array($s->metrics) ? $s->metrics : [];

            return ($metrics['reachable'] ?? false)
                && isset($metrics['cpu_used'], $metrics['memory_used']);
        });

        $cpuWeight = (float) config('plugged-host-servers.balance.cpu_weight', 1.0);
        $memWeight = (float) config('plugged-host-servers.balance.memory_weight', 1.0);
        $totalWeight = max(0.001, $cpuWeight + $memWeight);

        if ($valid->isNotEmpty()) {
            $avgCpuUsed = (float) $valid->avg(fn ($s) => (float) $s->metrics['cpu_used']);
            $avgCpuTotal = (float) ($valid->first()->metrics['cpu_total'] ?? 0);
            $cpuPercent = $avgCpuTotal > 0 ? ($avgCpuUsed / $avgCpuTotal) * 100.0 : 0.0;

            $avgMemBytes = (float) $valid->avg(fn ($s) => (float) $s->metrics['memory_used']);
            $avgMemTotal = (float) ($valid->first()->metrics['memory_total'] ?? 0);

            if ($avgMemTotal <= 0 && (int) $node->memory > 0) {
                $avgMemTotal = (float) ($node->memory * 1024 * 1024);
            }

            $memPercent = $avgMemTotal > 0 ? ($avgMemBytes / $avgMemTotal) * 100.0 : 0.0;
            $memGb = $avgMemBytes / (1024 * 1024 * 1024);

            $score = (($cpuPercent * $cpuWeight) + ($memPercent * $memWeight)) / $totalWeight;

            return [
                'node_id' => (int) $node->getKey(),
                'cpu_used_avg' => round($avgCpuUsed, 2),
                'cpu_total_avg' => round($avgCpuTotal, 2),
                'cpu_percent' => round($cpuPercent, 2),
                'memory_used_bytes_avg' => round($avgMemBytes, 0),
                'memory_used_gb_avg' => round($memGb, 2),
                'memory_total_bytes_avg' => round($avgMemTotal, 0),
                'memory_percent' => round($memPercent, 2),
                'score' => round($score, 4),
                'samples' => $valid->count(),
                'source' => 'snapshots',
            ];
        }

        // Fallback: try live stats through NodeCapacity for nodes with no snapshots
        $live = $this->capacity->usage($node);
        if ($live !== null) {
            $cpuPercent = (float) ($live['cpu'] ?? 0.0);
            $memPercent = (float) ($live['memory'] ?? 0.0);
            $score = (($cpuPercent * $cpuWeight) + ($memPercent * $memWeight)) / $totalWeight;

            return [
                'node_id' => (int) $node->getKey(),
                'cpu_used_avg' => 0.0,
                'cpu_total_avg' => 0.0,
                'cpu_percent' => round($cpuPercent, 2),
                'memory_used_bytes_avg' => 0.0,
                'memory_used_gb_avg' => 0.0,
                'memory_total_bytes_avg' => 0.0,
                'memory_percent' => round($memPercent, 2),
                'score' => round($score, 4),
                'samples' => 0,
                'source' => 'live',
            ];
        }

        // Complete failure / unreachable: assign penalty score
        return [
            'node_id' => (int) $node->getKey(),
            'cpu_used_avg' => 0.0,
            'cpu_total_avg' => 0.0,
            'cpu_percent' => 100.0,
            'memory_used_bytes_avg' => 0.0,
            'memory_used_gb_avg' => 0.0,
            'memory_total_bytes_avg' => 0.0,
            'memory_percent' => 100.0,
            'score' => 999999.0,
            'samples' => 0,
            'source' => 'unreachable',
        ];
    }

    /**
     * The recent samples of core's node telemetry for all requested nodes, as objects with a
     * `metrics` array (the shape calculateMetrics() reads).
     *
     * @param  array<int, int>  $nodeIds
     * @return Collection<int, Collection<int, mixed>> Keyed by node_id
     */
    protected function fetchRecentSnapshots(array $nodeIds, int $minutes): Collection
    {
        try {
            return collect(app(NodeTelemetry::class)->recent($nodeIds, $minutes))
                ->map(fn (array $samples) => collect($samples)->map(fn (array $sample) => (object) ['metrics' => $sample]));
        } catch (Throwable) {
            return collect();
        }
    }
}

