<?php

namespace PluggedHost\Servers\Services;

use App\Models\Node;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Takes an overloaded node out of the deployment pool by itself, and puts it back once it has
 * calmed down, so nobody has to strip a node's location tag by hand (which also cut the node's
 * existing servers off from their location: transfers, failover and the location display all
 * read those tags).
 *
 * The line is the start queue's (START_QUEUE_THRESHOLD_PERCENT) on memory or CPU, ten points
 * lower on a small node (under 34 GB of RAM or under 9 threads), where the same percentage
 * leaves far less room. Disk stays with the storage guard (NodeCapacity::storagePressured()).
 *
 * Evaluated once a minute (plugged-host:deploy-pause), never on the request path, so a
 * location does not flicker between open and full while a node hovers around its line:
 *
 *   - over the line → paused at once;
 *   - paused → open again only after `reopen_minutes` straight at least `reopen_margin`
 *     points under the line;
 *   - no answer `unreachable_after` times in a row → paused, open again on the first answer
 *     under the line.
 *
 * The state lives in the cache, refreshed every run with a short TTL: if the scheduler stops,
 * every pause lapses and nodes deploy as before (the guards fail open, like NodeCapacity's).
 * Tags and the node's own `public` flag are never touched.
 */
class DeploymentPause
{
    private const KEY = 'plugged-host-servers:deploy-pause:';

    private const TTL_MINUTES = 15;

    public const MEMORY = 'memory';

    public const CPU = 'cpu';

    public const UNREACHABLE = 'unreachable';

    public function enabled(): bool
    {
        return (bool) config('plugged-host-servers.deploy_pause.enabled', true);
    }

    public function isPaused(?Node $node): bool
    {
        if (!$node instanceof Node || !$this->enabled()) {
            return false;
        }

        return (bool) ($this->state((int) $node->getKey())['paused'] ?? false);
    }

    /**
     * @param  Collection<int, Node>  $nodes
     * @return Collection<int, Node>
     */
    public function withoutPaused(Collection $nodes): Collection
    {
        if (!$this->enabled()) {
            return $nodes;
        }

        return $nodes->reject(fn (Node $node) => $this->isPaused($node))->values();
    }

    /**
     * The last evaluation of a node, or null when there is none (never run, or lapsed).
     *
     * @return null|array{paused: bool, reason: ?string, percent: ?float, limit: float, small: bool, memory: ?float, cpu: ?float, memory_total: ?int, threads: ?int, failures: int, since: ?int, calm_since: ?int, checked_at: int}
     */
    public function state(int $nodeId): ?array
    {
        try {
            $state = Cache::get(self::KEY . $nodeId);
        } catch (Throwable) {
            return null;
        }

        return is_array($state) ? $state : null;
    }

    /**
     * Evaluate every node and store the result.
     *
     * @return array<int, array<string, mixed>> keyed by node id
     */
    public function refresh(): array
    {
        $states = [];

        foreach (Node::query()->orderBy('id')->get() as $node) {
            try {
                $states[(int) $node->getKey()] = $this->evaluate($node, $this->reading($node));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $states;
    }

    /**
     * Apply one reading to a node's state and store it.
     *
     * @param  null|array{memory: ?float, cpu: ?float, memory_total: ?int, threads: ?int}  $reading  null = no answer
     * @return array<string, mixed>
     */
    public function evaluate(Node $node, ?array $reading, ?int $now = null): array
    {
        $now ??= time();
        $previous = $this->state((int) $node->getKey()) ?? [];
        $wasPaused = (bool) ($previous['paused'] ?? false);

        $memoryTotal = $reading['memory_total'] ?? ($previous['memory_total'] ?? null);
        $threads = $reading['threads'] ?? ($previous['threads'] ?? null);
        $small = $this->isSmall($memoryTotal, $threads);
        $limit = $this->limit($small);

        $state = [
            'paused' => false,
            'reason' => null,
            'percent' => null,
            'limit' => $limit,
            'small' => $small,
            'memory' => $reading['memory'] ?? null,
            'cpu' => $reading['cpu'] ?? null,
            'memory_total' => $memoryTotal,
            'threads' => $threads,
            'failures' => 0,
            'since' => null,
            'calm_since' => null,
            'checked_at' => $now,
        ];

        if ($reading === null) {
            $state['failures'] = (int) ($previous['failures'] ?? 0) + 1;

            if ($wasPaused) {
                // Whatever paused it, a node that stopped answering stays out.
                $state['paused'] = true;
                $state['reason'] = $previous['reason'] ?? self::UNREACHABLE;
                $state['percent'] = $previous['percent'] ?? null;
                $state['since'] = $previous['since'] ?? $now;
            } elseif ($state['failures'] >= $this->unreachableAfter()) {
                $state['paused'] = true;
                $state['reason'] = self::UNREACHABLE;
                $state['since'] = $now;
            }

            return $this->store($node, $state);
        }

        $over = $this->over($reading, $limit);

        if ($over !== null) {
            $state['paused'] = true;
            $state['reason'] = $over;
            $state['percent'] = $reading[$over];
            $state['since'] = $wasPaused ? ($previous['since'] ?? $now) : $now;

            return $this->store($node, $state);
        }

        if (!$wasPaused || ($previous['reason'] ?? null) === self::UNREACHABLE) {
            return $this->store($node, $state);
        }

        // Paused for load and now under the line: open again once it has stayed clearly under
        // it for a while.
        $worst = $this->worst($reading);
        $calm = $worst === null || $worst <= $limit - $this->reopenMargin();
        $calmSince = $calm ? ($previous['calm_since'] ?? $now) : null;

        if ($calmSince !== null && $now - $calmSince >= $this->reopenMinutes() * 60) {
            return $this->store($node, $state);
        }

        $state['paused'] = true;
        $state['reason'] = $previous['reason'];
        $state['percent'] = $reading[$previous['reason']] ?? null;
        $state['since'] = $previous['since'] ?? $now;
        $state['calm_since'] = $calmSince;

        return $this->store($node, $state);
    }

    /**
     * The start queue's line, ten points lower on a small node.
     */
    public function limit(bool $small): float
    {
        $queue = (float) config('plugged-host-queue.threshold_percent', 85);
        $queue = $queue > 0 && $queue <= 100 ? $queue : 85.0;

        return $small ? max(1.0, $queue - $this->smallOffset()) : $queue;
    }

    /** Under 34 GB of RAM or under 9 threads. Unknown sizes count as big. */
    public function isSmall(?int $memoryTotal, ?int $threads): bool
    {
        $memoryGb = (float) config('plugged-host-servers.deploy_pause.small_memory_gb', 34);
        $minThreads = (int) config('plugged-host-servers.deploy_pause.small_threads', 9);

        return ($memoryTotal !== null && $memoryTotal > 0 && $memoryTotal < $memoryGb * 1024 ** 3)
            || ($threads !== null && $threads > 0 && $threads < $minThreads);
    }

    /**
     * Memory and CPU in percent plus the node's size, or null when wings did not answer.
     *
     * Its own call with the status collector's timeouts rather than Node::statistics(), whose
     * one-second timeout and all-zeros fallback cannot tell a busy node from a dead one.
     *
     * @return null|array{memory: ?float, cpu: ?float, memory_total: ?int, threads: ?int}
     */
    protected function reading(Node $node): ?array
    {
        try {
            $statistics = Http::daemon($node)
                ->connectTimeout(2)
                ->timeout(3)
                ->get('/api/system/utilization')
                ->throw()
                ->json();
        } catch (Throwable) {
            return null;
        }

        $memoryTotal = (int) ($statistics['memory_total'] ?? 0);

        if ($memoryTotal <= 0) {
            return null;
        }

        try {
            $threads = (int) ($node->systemInformation()['cpu_count'] ?? 0);
        } catch (Throwable) {
            $threads = 0;
        }

        return [
            'memory' => $this->clamp((float) ($statistics['memory_used'] ?? 0) / $memoryTotal * 100),
            'cpu' => $this->clamp((float) ($statistics['cpu_percent'] ?? 0)),
            'memory_total' => $memoryTotal,
            'threads' => $threads > 0 ? $threads : null,
        ];
    }

    /** The first of memory and CPU over the line, or null. */
    protected function over(array $reading, float $limit): ?string
    {
        foreach ([self::MEMORY, self::CPU] as $dimension) {
            if (($reading[$dimension] ?? null) !== null && $reading[$dimension] > $limit) {
                return $dimension;
            }
        }

        return null;
    }

    protected function worst(array $reading): ?float
    {
        $values = array_filter([$reading[self::MEMORY] ?? null, $reading[self::CPU] ?? null], fn ($v) => $v !== null);

        return $values === [] ? null : (float) max($values);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function store(Node $node, array $state): array
    {
        Cache::put(self::KEY . $node->getKey(), $state, now()->addMinutes(self::TTL_MINUTES));

        return $state;
    }

    protected function smallOffset(): float
    {
        return max(0.0, (float) config('plugged-host-servers.deploy_pause.small_offset', 10));
    }

    protected function reopenMargin(): float
    {
        return max(0.0, (float) config('plugged-host-servers.deploy_pause.reopen_margin', 5));
    }

    protected function reopenMinutes(): int
    {
        return max(1, (int) config('plugged-host-servers.deploy_pause.reopen_minutes', 10));
    }

    protected function unreachableAfter(): int
    {
        return max(1, (int) config('plugged-host-servers.deploy_pause.unreachable_after', 3));
    }

    protected function clamp(float $percent): float
    {
        return max(0.0, min(100.0, round($percent, 2)));
    }
}
