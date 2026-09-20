<?php

namespace PluggedHost\Status\Services;

use PluggedHost\Status\Models\StatusNodeSetting;
use PluggedHost\Status\Models\StatusSnapshot;

/**
 * The "panel → node" latency figure the create page (WP3) shows on each location card.
 *
 * It is the median of the daemon-probe round-trip StatusCollector already records every
 * minute into status_snapshots.metrics.response_time_ms (fact 7) — no table of its own. It is
 * honestly labelled "panel → node" because that is what it measures; the user-perceived probe
 * is a separate, opt-in browser measurement in the servers plugin.
 *
 * Reached from plugged-host-servers by class-string behind class_exists(), so status stays
 * independently removable — a missing status plugin simply means "no reading" (null), which the
 * card renders as absent rather than as zero.
 */
class NodeLatency
{
    /** How many recent node snapshots to median over. */
    private const SAMPLES = 15;

    /** Median panel→node latency in ms for one node, or null when there is no reading. */
    public function medianFor(int $nodeId): ?int
    {
        $publicId = StatusNodeSetting::query()->where('node_id', $nodeId)->value('public_id');

        if (!is_string($publicId) || $publicId === '') {
            return null;
        }

        $samples = StatusSnapshot::query()
            ->where('component_type', 'node')
            ->where('component_key', $publicId)
            ->latest('checked_at')
            ->limit(self::SAMPLES)
            ->get()
            ->map(fn (StatusSnapshot $s) => is_array($s->metrics) ? ($s->metrics['response_time_ms'] ?? null) : null)
            ->filter(fn ($v) => is_int($v) || (is_numeric($v) && $v !== ''))
            ->map(fn ($v) => (int) $v)
            ->values();

        if ($samples->isEmpty()) {
            return null;
        }

        $sorted = $samples->sort()->values();
        $count = $sorted->count();
        $mid = intdiv($count, 2);

        return $count % 2 === 1
            ? (int) $sorted[$mid]
            : (int) round(((int) $sorted[$mid - 1] + (int) $sorted[$mid]) / 2);
    }

    /**
     * Median latency for many nodes at once.
     *
     * @param  array<int, int>  $ids
     * @return array<int, ?int>  node id => median ms (or null)
     */
    public function medianForNodes(array $ids): array
    {
        $result = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $result[$id] = $this->medianFor($id);
        }

        return $result;
    }
}
