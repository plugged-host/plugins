<?php

namespace PluggedHost\Queue\Services;

use App\Models\Node;
use PluggedHost\Core\Support\Plugins;
use Throwable;

/**
 * How full a node is, as the queue asks it.
 *
 * A thin adapter over plugged-host-core's `NodeCapacity`, which owns the live
 * measurement (wings' /api/system/utilization, via `Node::statistics()`) and the two
 * thresholds. Reading them from there rather than keeping a second copy is the whole
 * point: the allocator that keeps new servers off a full node and the queue that
 * holds starts back on a busy one have to agree about which nodes those are.
 *
 * Resolved by class name rather than imported so the queue does not hard-depend on
 * core being loaded. With core missing there is no measurement, `pressure()` answers
 * null, and every caller treats that as "no reason to make anybody wait" — the same
 * fail-open direction core itself takes for a node that does not answer.
 *
 * The dimensions are narrower than core's `isHot()`, and deliberately. Memory and CPU
 * come back down on their own, so a line waiting on them moves. Disk does not: a node
 * at 96% full is still at 96% full in ten minutes, so queueing on it would be a line
 * that never advances and a customer who cannot reach their server. Disk pressure is
 * handled by moving servers and new deployments elsewhere instead — core's allocator
 * for new ones, `NodeTransferService` for existing ones.
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

    /** Is this node's disk over the placement line? Core's threshold, core's answer. */
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
     * WP11: this is the queue's OWN threshold (START_QUEUE_THRESHOLD_PERCENT, default 85),
     * deliberately decoupled from the allocator's emergency placement ceiling (core's
     * hot_percent, 95). Normal fleet nodes sit under 95 almost always, which is why the old
     * shared ceiling meant the queue effectively never engaged. Falls back to core's hotPercent
     * only when the queue threshold is not configured at all.
     */
    public function limit(): float
    {
        $configured = config('plugged-host-queue.threshold_percent', null);

        if ($configured !== null && $configured !== '') {
            return (float) $configured;
        }

        $capacity = $this->capacity();

        if ($capacity === null) {
            return 85.0;
        }

        try {
            return (float) $capacity->hotPercent();
        } catch (Throwable) {
            return 85.0;
        }
    }

    /** Whether the node has a live utilisation reading at all (WP11 paced-fallback decision). */
    public function measurable(?Node $node): bool
    {
        return $this->usage($node) !== null;
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
     * Core's capacity service, or null when core is not loaded.
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
