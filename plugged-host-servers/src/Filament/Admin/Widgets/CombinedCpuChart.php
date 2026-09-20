<?php

namespace PluggedHost\Servers\Filament\Admin\Widgets;

use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use PluggedHost\Servers\Filament\Admin\Widgets\Concerns\LoadsCombinedNodeStatistics;

class CombinedCpuChart extends ChartWidget
{
    use LoadsCombinedNodeStatistics;

    protected ?string $pollingInterval = '5s';

    protected ?string $maxHeight = '300px';

    /** @var array<int, array{cpu: float, timestamp: string}> */
    protected array $cpuHistory = [];

    protected int $threads = 0;

    protected function getData(): array
    {
        $sessionKey = 'available_resources.combined';
        $data = $this->getCombinedNodeStatistics();
        $this->threads = (int) ($data['cpu_total'] / 100);
        $this->cpuHistory = session("{$sessionKey}.cpu_history", []);
        $this->cpuHistory[] = [
            'cpu' => round($data['cpu_used'], 2),
            'timestamp' => now(user()->timezone ?? 'UTC')->format('H:i:s'),
        ];
        $this->cpuHistory = array_slice($this->cpuHistory, -60);
        session()->put("{$sessionKey}.cpu_history", $this->cpuHistory);

        return [
            'datasets' => [[
                'data' => array_column($this->cpuHistory, 'cpu'),
                'backgroundColor' => ['rgba(96, 165, 250, 0.3)'],
                'tension' => '0.3',
                'fill' => true,
            ]],
            'labels' => array_column($this->cpuHistory, 'timestamp'),
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
        $latest = collect($this->cpuHistory)->last() ?? ['cpu' => 0];

        return trans('admin/node.cpu_chart', [
            'cpu' => format_number($latest['cpu'], maxPrecision: 2),
            'max' => format_number($this->threads * 100),
        ]);
    }
}
