<?php

namespace PluggedHost\Servers\Filament\Admin\Widgets;

use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use PluggedHost\Servers\Filament\Admin\Widgets\Concerns\LoadsCombinedNodeStatistics;

class CombinedMemoryChart extends ChartWidget
{
    use LoadsCombinedNodeStatistics;

    protected ?string $pollingInterval = '5s';

    protected ?string $maxHeight = '300px';

    /** @var array<int, array{memory: float, timestamp: string}> */
    protected array $memoryHistory = [];

    protected int $totalMemory = 0;

    protected function getData(): array
    {
        $sessionKey = 'available_resources.combined';
        $data = $this->getCombinedNodeStatistics();
        $this->totalMemory = $data['memory_total'];
        $this->memoryHistory = session("{$sessionKey}.memory_history", []);
        $this->memoryHistory[] = [
            'memory' => round(config('panel.use_binary_prefix')
                ? $data['memory_used'] / 1024 / 1024 / 1024
                : $data['memory_used'] / 1000 / 1000 / 1000, 2),
            'timestamp' => now(user()->timezone ?? 'UTC')->format('H:i:s'),
        ];
        $this->memoryHistory = array_slice($this->memoryHistory, -60);
        session()->put("{$sessionKey}.memory_history", $this->memoryHistory);

        return [
            'datasets' => [[
                'data' => array_column($this->memoryHistory, 'memory'),
                'backgroundColor' => ['rgba(96, 165, 250, 0.3)'],
                'tension' => '0.3',
                'fill' => true,
            ]],
            'labels' => array_column($this->memoryHistory, 'timestamp'),
            'locale' => user()->language ?? 'en',
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
        {
            scales: {
                y: {
                    min: 0,
                },
            },
            plugins: {
                legend: {
                    display: false,
                }
            }
        }
    JS);
    }

    public function getHeading(): string
    {
        $latest = collect($this->memoryHistory)->last() ?? ['memory' => 0];
        $used = config('panel.use_binary_prefix')
            ? format_number($latest['memory'], maxPrecision: 2) . ' GiB'
            : format_number($latest['memory'], maxPrecision: 2) . ' GB';
        $total = config('panel.use_binary_prefix')
            ? format_number($this->totalMemory / 1024 / 1024 / 1024, maxPrecision: 2) . ' GiB'
            : format_number($this->totalMemory / 1000 / 1000 / 1000, maxPrecision: 2) . ' GB';

        return trans('admin/node.memory_chart', ['used' => $used, 'total' => $total]);
    }
}
