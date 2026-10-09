<?php

namespace PluggedHost\Core\Nodes;

use App\Models\Node;
use App\Repositories\Daemon\DaemonSystemRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The panel's own view of its nodes, sampled once a minute (plugged-host:sample-nodes): can the
 * panel reach wings, how fast, and how loaded the node is.
 *
 * One sampler for everything that needs it: servers balances new servers and shows location
 * latency from it, storage's failover counts consecutive failures from it, and the status
 * page reads its node figures from it. Until 2.2 the status plugin probed the nodes and the
 * others read its tables, so removing status silently disabled failover (and status only
 * probed the nodes published on the status page).
 *
 * The last SAMPLES readings per node live in the cache, not a table: every reader looks at
 * minutes, not days. An emptied cache restarts the windows; failover then needs its full run
 * of failures again before it acts, which is the safe direction.
 *
 * A sample: at, reachable, response_time_ms, cpu_used, cpu_total (threads x 100), memory_used,
 * memory_total, disk_used, disk_total. The load figures are null when wings did not answer.
 */
final class NodeTelemetry
{
    public const SAMPLES = 30;

    private const KEY = 'plugged-host:node-telemetry:';

    /**
     * Probe one node now and record the result.
     *
     * @return array<string, mixed>
     */
    public function sample(Node $node): array
    {
        $sample = [
            'at' => time(),
            'reachable' => false,
            'response_time_ms' => null,
            'cpu_used' => null,
            'cpu_total' => null,
            'memory_used' => null,
            'memory_total' => null,
            'disk_used' => null,
            'disk_total' => null,
        ];

        $started = microtime(true);

        try {
            $statistics = Http::daemon($node)
                ->connectTimeout(2)
                ->timeout(3)
                ->get('/api/system/utilization')
                ->throw()
                ->json();

            $sample['response_time_ms'] = (int) round((microtime(true) - $started) * 1000);
            $sample['reachable'] = true;

            $threads = $this->threads($node);

            if ($threads > 0 && (int) ($statistics['memory_total'] ?? 0) > 0) {
                $sample['cpu_used'] = round(max(0, (float) ($statistics['cpu_percent'] ?? 0)) * $threads, 2);
                $sample['cpu_total'] = $threads * 100;
                $sample['memory_used'] = max(0, (int) ($statistics['memory_used'] ?? 0));
                $sample['memory_total'] = max(0, (int) ($statistics['memory_total'] ?? 0));
                $sample['disk_used'] = max(0, (int) ($statistics['disk_used'] ?? 0));
                $sample['disk_total'] = max(0, (int) ($statistics['disk_total'] ?? 0));
            }
        } catch (Throwable) {
            // Unreachable: recorded as such.
        }

        $samples = $this->samples((int) $node->getKey());
        $samples[] = $sample;

        Cache::put(self::KEY . $node->getKey(), array_slice($samples, -self::SAMPLES), now()->addHours(2));

        return $sample;
    }

    /**
     * Newest last.
     *
     * @return list<array<string, mixed>>
     */
    public function samples(int $nodeId): array
    {
        $samples = Cache::get(self::KEY . $nodeId);

        return is_array($samples) ? array_values($samples) : [];
    }

    /**
     * The newest sample, if it is younger than $maxAgeSeconds.
     *
     * @return null|array<string, mixed>
     */
    public function latest(int $nodeId, int $maxAgeSeconds = 150): ?array
    {
        $samples = $this->samples($nodeId);
        $latest = end($samples);

        return is_array($latest) && (int) ($latest['at'] ?? 0) >= time() - $maxAgeSeconds ? $latest : null;
    }

    /**
     * The samples of the last $minutes minutes per node, newest first.
     *
     * @param  array<int, int>  $nodeIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function recent(array $nodeIds, int $minutes): array
    {
        $since = time() - $minutes * 60;
        $result = [];

        foreach ($nodeIds as $nodeId) {
            $result[(int) $nodeId] = array_values(array_reverse(array_filter(
                $this->samples((int) $nodeId),
                fn (array $sample): bool => (int) ($sample['at'] ?? 0) >= $since,
            )));
        }

        return $result;
    }

    /**
     * How many of the newest samples in a row found the node unreachable. 0 when the newest
     * reached it, and 0 without any samples (no evidence is not evidence of death).
     */
    public function consecutiveFailures(int $nodeId): int
    {
        $failures = 0;

        foreach (array_reverse($this->samples($nodeId)) as $sample) {
            if ($sample['reachable'] ?? false) {
                break;
            }

            $failures++;
        }

        return $failures;
    }

    /**
     * Median panel→node response time over the last $count successful probes, per node; null
     * for a node without one.
     *
     * @param  array<int, int>  $nodeIds
     * @return array<int, ?int>
     */
    public function medianLatency(array $nodeIds, int $count = 15): array
    {
        $result = [];

        foreach ($nodeIds as $nodeId) {
            $times = array_values(array_filter(array_map(
                fn (array $sample) => $sample['response_time_ms'] ?? null,
                array_slice($this->samples((int) $nodeId), -$count),
            ), 'is_int'));

            if ($times === []) {
                $result[(int) $nodeId] = null;

                continue;
            }

            sort($times);
            $middle = intdiv(count($times), 2);

            $result[(int) $nodeId] = count($times) % 2 === 1
                ? $times[$middle]
                : (int) round(($times[$middle - 1] + $times[$middle]) / 2);
        }

        return $result;
    }

    /**
     * The node's thread count from wings' /api/system, kept for an hour: it is what turns
     * wings' cpu_percent into cpu_used, and asking every minute doubled the calls per node.
     */
    private function threads(Node $node): int
    {
        $key = self::KEY . $node->getKey() . ':threads';
        $threads = (int) Cache::get($key, 0);

        if ($threads > 0) {
            return $threads;
        }

        $threads = max(0, (int) ((new DaemonSystemRepository())->setNode($node)->getSystemInformation()['cpu_count'] ?? 0));

        if ($threads > 0) {
            Cache::put($key, $threads, now()->addHour());
        }

        return $threads;
    }
}
