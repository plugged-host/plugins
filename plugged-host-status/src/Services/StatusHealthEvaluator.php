<?php

namespace PluggedHost\Status\Services;

use PluggedHost\Status\Models\StatusNodeSetting;

class StatusHealthEvaluator
{
    /**
     * A node's public status. Unreachable: degraded from the second missed check in a row (one
     * miss is a blip, not an incident), offline from the third. Reachable but with its CPU
     * saturated for five checks in a row: degraded, because servers on it really are slower.
     * A node that is merely full (memory or disk) is working normally and stays operational:
     * new servers go elsewhere and the start queue handles starts.
     *
     * @return array{status: string, failure_count: int, healthy_count: int, resource_pressure_count: int}
     */
    public function node(StatusNodeSetting $setting, bool $reachable, bool $underPressure, bool $maintenance): array
    {
        if (!$reachable) {
            $failures = $setting->failure_count + 1;

            return [
                'status' => match (true) {
                    $failures >= 3 => 'offline',
                    $failures >= 2 => 'degraded',
                    default => $setting->status ?: 'operational',
                },
                'failure_count' => $failures,
                'healthy_count' => 0,
                'resource_pressure_count' => 0,
            ];
        }

        if ($maintenance) {
            return [
                'status' => 'maintenance',
                'failure_count' => 0,
                'healthy_count' => 0,
                'resource_pressure_count' => 0,
            ];
        }

        if ($underPressure) {
            $pressure = $setting->resource_pressure_count + 1;

            return [
                'status' => $pressure >= 5 ? 'degraded' : 'operational',
                'failure_count' => 0,
                'healthy_count' => 0,
                'resource_pressure_count' => $pressure,
            ];
        }

        // Coming back from a real episode (not a single missed check): two good checks first.
        $recovering = in_array($setting->status, ['degraded', 'offline'], true);
        $healthy = $recovering ? $setting->healthy_count + 1 : max(2, $setting->healthy_count);

        return [
            'status' => $recovering && $healthy < 2 ? 'degraded' : 'operational',
            'failure_count' => 0,
            'healthy_count' => $healthy,
            'resource_pressure_count' => 0,
        ];
    }

    /** @param array<int, array<string, mixed>> $recentMetrics */
    public function endpoint(bool $reachable, array $recentMetrics): string
    {
        if ($reachable) {
            return (($recentMetrics[0]['reachable'] ?? true) === false) ? 'degraded' : 'operational';
        }

        $failures = 1;
        foreach ($recentMetrics as $metrics) {
            if (($metrics['reachable'] ?? true) !== false) {
                break;
            }
            $failures++;
        }

        return $failures >= 3 ? 'offline' : 'degraded';
    }

    /**
     * CPU saturation only. Memory and disk fill up on a busy game node by design (servers hold
     * the RAM they were given), and a full node is not a slow one, so neither marks it degraded.
     *
     * @param  array<string, int|float>  $metrics
     */
    public function underResourcePressure(array $metrics): bool
    {
        $threshold = (float) config('plugged-host-status.resource_threshold_percent', 95);

        return $this->percentage($metrics['cpu_used'] ?? 0, $metrics['cpu_total'] ?? 0) >= $threshold;
    }

    public function priority(string $status): int
    {
        return match ($status) {
            'major_outage', 'offline' => 5,
            'partial_outage' => 4,
            'degraded' => 3,
            'maintenance' => 2,
            'operational' => 1,
            default => 0,
        };
    }

    private function percentage(int|float $used, int|float $total): float
    {
        return $total > 0 ? ((float) $used / (float) $total) * 100 : 0;
    }
}
