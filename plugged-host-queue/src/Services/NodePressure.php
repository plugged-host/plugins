<?php

namespace PluggedHost\Queue\Services;

use App\Models\Node;
use PluggedHost\Core\Support\Plugins;
use Throwable;

/**
 * How full a node is, as the queue asks it.
 *
 * A thin adapter over plugged-host-servers' `NodeCapacity`, which owns the live
 * measurement (wings' /api/system/utilization, via `Node::statistics()`) and the disk
 * line. The memory/CPU line is the queue's own (`limit()`), and servers' DeploymentPause
 * reads the same setting, so the allocator that keeps new servers off a busy node and
 * the queue that holds a start back on one agree about which nodes those are.
 *
 * Resolved by class name rather than imported so the queue does not break when servers
 * is not loaded. With it missing there is no measurement, `pressure()` answers null, and
 * every caller treats that as "no reason to hold a start back" — the same fail-open
 * direction `NodeCapacity` itself takes for a node that does not answer.
 *
 * Memory and CPU by default, and deliberately not disk. Memory and CPU come back down on
 * their own, so a line waiting on them moves. Disk does not: a node at 96% full is still
 * at 96% full in ten minutes, so waiting on it would be a line that never advances until
 * the cap. Disk pressure is handled by moving servers and new deployments elsewhere
 * instead — the allocator for new ones, `NodeTransferService` for existing ones.
 */
class NodePressure
{
    protected const CAPACITY = 'PluggedHost\\Servers\\Services\\NodeCapacity';

    protected const DEPLOYMENT_PAUSE = 'PluggedHost\\Servers\\Services\\DeploymentPause';

    /** @var array<int, string> */
    protected const DIMENSIONS = ['memory', 'cpu', 'disk'];

    /**
     * Live utilisation percentages, or null when there is no reading.
     *
     * @return null|array{memory: ?float, cpu: ?float, disk: ?float}
     */
    public function usage(?Node $node): ?array
    {
        $capacity = $this->capacity();

        if ($capacity === null || !$node instanceof Node) {
            return null;
        }

        try {
            $usage = $capacity->usage($node);
        } catch (Throwable) {
            return null;
        }

        return is_array($usage) ? $usage : null;
    }

    /**
     * The dimension that puts this node over the line, or null when nothing does.
     *
     * Null is also the answer for a node that could not be measured, which is why
     * this returns the reason rather than a boolean: the caller needs it for the
     * waiting page's explanation, and "no reason" and "no reading" both mean nobody
     * waits.
     */
    public function pressure(?Node $node): ?string
    {
        $usage = $this->usage($node);

        if ($usage === null) {
            return null;
        }

        $limit = $this->limit();

        foreach ($this->dimensions() as $dimension) {
            if (($usage[$dimension] ?? null) !== null && $usage[$dimension] > $limit) {
                return $dimension;
            }
        }

        return null;
    }

    public function isUnderPressure(?Node $node): bool
    {
        return $this->pressure($node) !== null;
    }

    /** Is this node's disk over the placement line? servers' threshold, servers' answer. */
    public function storagePressured(?Node $node): bool
    {
        $capacity = $this->capacity();

        if ($capacity === null || !$node instanceof Node) {
            return false;
        }

        try {
            return (bool) $capacity->storagePressured($node);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Has plugged-host-servers' DeploymentPause taken this node out of the deployment pool?
     * False without servers, or when the pause cannot be read.
     */
    public function deploymentPaused(?Node $node): bool
    {
        if (!$node instanceof Node || !Plugins::available(self::DEPLOYMENT_PAUSE)) {
            return false;
        }

        try {
            return (bool) app(self::DEPLOYMENT_PAUSE)->isPaused($node);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The worst of the queueing dimensions, as a percentage, for ranking candidate
     * nodes against each other. 100.0 for a node with no reading, so an unmeasurable
     * node sorts last and is never picked as somewhere to move a server to.
     */
    public function worstPercent(?Node $node): float
    {
        $usage = $this->usage($node);

        if ($usage === null) {
            return 100.0;
        }

        $worst = 0.0;

        foreach ($this->dimensions() as $dimension) {
            $value = $usage[$dimension] ?? null;

            if ($value !== null) {
                $worst = max($worst, (float) $value);
            }
        }

        return $worst;
    }

    /**
     * The percentage above which a node is too busy to take another start.
     *
     * The queue's own line (START_QUEUE_THRESHOLD_PERCENT, default 85). plugged-host-servers'
     * DeploymentPause reads the same setting (ten points lower on small nodes) to take a node
     * out of new servers, so the two agree about which nodes are busy. A value outside 1-100 is
     * a typo, not an intention, and falls back to 85 there and here alike.
     */
    public function limit(): float
    {
        $configured = (float) config('plugged-host-queue.threshold_percent', 85);

        return $configured > 0 && $configured <= 100 ? $configured : 85.0;
    }

    /**
     * Which dimensions queue a start. Config, but filtered against what a reading
     * actually contains — a typo would otherwise silently mean "nothing is ever
     * under pressure", and the honest fallback for an unusable list is the default.
     *
     * @return array<int, string>
     */
    public function dimensions(): array
    {
        $raw = config('plugged-host-queue.pressure.dimensions', 'memory,cpu');

        $configured = is_array($raw)
            ? $raw
            : explode(',', (string) $raw);

        $clean = array_values(array_intersect(
            array_map(fn ($value) => strtolower(trim((string) $value)), $configured),
            self::DIMENSIONS,
        ));

        return $clean === [] ? ['memory', 'cpu'] : $clean;
    }

    /**
     * servers' capacity service, or null when plugged-host-servers is not loaded.
     *
     * Not memoised: this class is resolved per request and the container already
     * caches nothing here worth keeping — `NodeCapacity` is stateless and its own
     * measurements are cached inside `Node::statistics()`.
     */
    protected function capacity(): mixed
    {
        if (!Plugins::available(self::CAPACITY)) {
            return null;
        }

        try {
            return app(self::CAPACITY);
        } catch (Throwable) {
            return null;
        }
    }
}
