<?php

namespace PluggedHost\Status\Services;

use PluggedHost\Status\Models\StatusNodeSetting;

class StatusHealthEvaluator
{
    /** @return array{status: string, failure_count: int, healthy_count: int, resource_pressure_count: int} */
    public function node(StatusNodeSetting $setting, bool $reachable, bool $underPressure, bool $maintenance): array
    {
        if (!$reachable) {
            $failures = $setting->failure_count + 1;

            return [
                'status' => $failures >= 3 ? 'offline' : 'degraded',
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

        $recovering = $setting->failure_count > 0 || $setting->status === 'degraded';
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

    /** @param array<string, int|float> $metrics */
    public function underResourcePressure(array $metrics): bool
    {
        $threshold = (float) config('plugged-host-status.resource_threshold_percent', 90);

        return $this->percentage($metrics['cpu_used'] ?? 0, $metrics['cpu_total'] ?? 0) >= $threshold
            || $this->percentage($metrics['memory_used'] ?? 0, $metrics['memory_total'] ?? 0) >= $threshold
            || $this->percentage($metrics['storage_used'] ?? 0, $metrics['storage_total'] ?? 0) >= $threshold;
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
