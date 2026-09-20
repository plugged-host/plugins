<?php

namespace PluggedHost\Servers\Filament\Admin\Widgets\Concerns;

use App\Models\Node;

trait LoadsCombinedNodeStatistics
{
    /** @return array{cpu_used: float, cpu_total: int, memory_used: int, memory_total: int, storage_used: int, storage_total: int} */
    protected function getCombinedNodeStatistics(): array
    {
        $combined = [
            'cpu_used' => 0.0,
            'cpu_total' => 0,
            'memory_used' => 0,
            'memory_total' => 0,
            'storage_used' => 0,
            'storage_total' => 0,
        ];

        foreach (user()?->accessibleNodes()->get() ?? [] as $node) {
            /** @var Node $node */
            $statistics = $node->statistics();
            $systemInformation = $node->systemInformation();
            $threads = max(0, (int) ($systemInformation['cpu_count'] ?? 0));

            $combined['cpu_used'] += round(max(0, (float) ($statistics['cpu_percent'] ?? 0)) * $threads, 2);
            $combined['cpu_total'] += $threads * 100;
            $combined['memory_used'] += max(0, (int) ($statistics['memory_used'] ?? 0));
            $combined['memory_total'] += max(0, (int) ($statistics['memory_total'] ?? 0));
            $combined['storage_used'] += max(0, (int) ($statistics['disk_used'] ?? 0));
            $combined['storage_total'] += max(0, (int) ($statistics['disk_total'] ?? 0));
        }

        return $combined;
    }
}
