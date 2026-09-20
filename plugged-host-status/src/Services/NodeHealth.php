<?php

namespace PluggedHost\Status\Services;

use PluggedHost\Status\Models\StatusNodeSetting;
use PluggedHost\Status\Models\StatusSnapshot;

/**
 * The second witness in storage's failover decision (WP9).
 *
 * The storage plugin asks "how many times in a row has the panel's own minute-by-minute probe
 * failed to reach this node?" and combines the answer with a fresh, independent reachability
 * check from the storage agent. Failover only fires when BOTH have lost the node — the panel
 * alone cannot tell a dead node from a panel↔node network partition.
 *
 * Consumed by plugged-host-storage via class-string behind class_exists(), so status stays
 * independently removable.
 */
class NodeHealth
{
    /** Cap the scan; far more than any real failover threshold needs. */
    private const SCAN = 30;

    /**
     * Number of most-recent consecutive node snapshots that report the node unreachable.
     *
     * Counts from the latest snapshot backwards and stops at the first reachable one. Returns 0
     * when the most recent probe reached the node, and 0 when there is no snapshot history at all
     * (no evidence is not evidence of death).
     */
    public function consecutiveFailures(int $nodeId): int
    {
        $publicId = StatusNodeSetting::query()->where('node_id', $nodeId)->value('public_id');

        if (!is_string($publicId) || $publicId === '') {
            return 0;
        }

        $recent = StatusSnapshot::query()
            ->where('component_type', 'node')
            ->where('component_key', $publicId)
            ->latest('checked_at')
            ->limit(self::SCAN)
            ->get();

        $failures = 0;
        foreach ($recent as $snapshot) {
            if ($this->reachable($snapshot)) {
                break;
            }
            $failures++;
        }

        return $failures;
    }

    private function reachable(StatusSnapshot $snapshot): bool
    {
        $metrics = $snapshot->metrics;

        if (is_array($metrics) && array_key_exists('reachable', $metrics)) {
            return (bool) $metrics['reachable'];
        }

        // Fall back to the evaluated status when the metrics blob is malformed.
        return $snapshot->status !== 'offline';
    }
}
