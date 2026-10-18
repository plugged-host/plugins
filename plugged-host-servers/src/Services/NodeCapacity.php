<?php

namespace PluggedHost\Servers\Services;

use App\Models\Node;
use Throwable;

/**
 * How full a node is *right now*, as opposed to how much has been promised.
 *
 * Pelican already answers the promised question: `Node::isViable()` and
 * `FindViableNodesService` add up what every server on a node is allowed to use and
 * compare that against the node's limits. That is the right check for "may this
 * server be created here", and the wrong one for every question this class answers,
 * because a node can be 40% allocated and 96% full — allocation is a budget, and
 * containers do not spend it evenly.
 *
 * The live numbers come from `Node::statistics()`, which calls wings'
 * /api/system/utilization with a one-second timeout and caches the result for 5-30
 * seconds. Two properties of that method shape everything here:
 *
 * It returns **all zeros** when the request fails, times out, or the node is down.
 * Zeros are indistinguishable from a genuinely idle node, so a zero total is treated
 * as "no reading" and every guard fails **open**. That direction is deliberate: the
 * cost of wrongly allowing a start on a hot node is a slow server, and the cost of
 * wrongly blocking one is a user who cannot reach their own server because a
 * monitoring call timed out.
 *
 * It is cached per node per request-ish window, so calling this repeatedly in a loop
 * over nodes costs one HTTP round trip per node at most every 5 seconds — cheap
 * enough to use inside a deployment path, which is why there is no second cache here.
 *
 * The storage line lives in config rather than as a constant because several plugins
 * ask the same question (the allocator, transfers, the start queue's move offer, the
 * devbox) and they must agree on the answer. Memory and CPU are judged elsewhere: the
 * start queue reads `usage()` against its own line, and DeploymentPause against the
 * same line from core's telemetry.
 */
class NodeCapacity
{
    /** Percentage of disk above which a node stops accepting new servers. */
    public function storagePercent(): float
    {
        return $this->percentSetting('storage_percent', 90.0);
    }

    /**
     * Whether a node we could not measure should be refused rather than allowed.
     *
     * Off by default. On, an unreachable wings means no deployments to that node,
     * which is defensible on a fleet where every node is expected to answer and
     * dangerous on one where a single flaky node would empty the pool.
     */
    public function storageGuardIsStrict(): bool
    {
        return (bool) config('plugged-host-servers.nodes.storage_guard_strict', false);
    }

    /** Is this node's disk over the storage line? */
    public function storagePressured(?Node $node): bool
    {
        $usage = $this->usage($node);

        return $usage !== null
            && $usage['disk'] !== null
            && $usage['disk'] > $this->storagePercent();
    }

    /**
     * Live utilisation as percentages, or null when the node did not answer.
     *
     * A dimension is null rather than 0.0 when its total is zero, because
     * `Node::statistics()` cannot tell "0 of 0" apart from "no answer" and averaging
     * a fabricated zero into a decision is worse than declining to decide. CPU is the
     * exception: wings reports it as a percentage already, with no total to divide by,
     * so a zero there is taken at face value.
     *
     * @return null|array{memory: ?float, cpu: ?float, disk: ?float}
     */
    public function usage(?Node $node): ?array
    {
        if (!$node instanceof Node) {
            return null;
        }

        try {
            $stats = $node->statistics();
        } catch (Throwable) {
            // statistics() swallows its own HTTP failures, so reaching here means
            // something structural — a node row with no daemon config, most likely.
            return null;
        }

        if (!is_array($stats)) {
            return null;
        }

        $memoryTotal = (float) ($stats['memory_total'] ?? 0);
        $diskTotal = (float) ($stats['disk_total'] ?? 0);

        // The all-zeros default. memory_total is what Node::statistics() itself tests
        // to decide whether wings gave it a real answer, so it is the honest signal
        // for "there is nothing here to read".
        if ($memoryTotal <= 0 && $diskTotal <= 0) {
            return null;
        }

        return [
            'memory' => $this->percentage((float) ($stats['memory_used'] ?? 0), $memoryTotal),
            'cpu' => $this->clamp((float) ($stats['cpu_percent'] ?? 0)),
            'disk' => $this->percentage((float) ($stats['disk_used'] ?? 0), $diskTotal),
        ];
    }

    protected function percentage(float $used, float $total): ?float
    {
        return $total > 0 ? $this->clamp(($used / $total) * 100) : null;
    }

    protected function clamp(float $percent): float
    {
        return max(0.0, min(100.0, round($percent, 2)));
    }

    /**
     * A percentage from config, kept inside 1-100.
     *
     * 0 would mean "every node is full" and is far more likely a typo or an empty .env
     * value than an intention, so it falls back to the default rather than taking the
     * whole fleet out of service.
     */
    protected function percentSetting(string $key, float $default): float
    {
        $value = (float) config('plugged-host-servers.nodes.' . $key, $default);

        return $value > 0 && $value <= 100 ? $value : $default;
    }
}
