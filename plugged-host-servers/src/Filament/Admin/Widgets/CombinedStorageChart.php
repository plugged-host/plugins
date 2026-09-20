<?php

namespace PluggedHost\Servers\Filament\Admin\Widgets;

use Filament\Widgets\ChartWidget;
use PluggedHost\Servers\Filament\Admin\Widgets\Concerns\LoadsCombinedNodeStatistics;

class CombinedStorageChart extends ChartWidget
{
    use LoadsCombinedNodeStatistics;

    protected ?string $pollingInterval = '30s';

    protected ?string $maxHeight = '200px';

    protected int $used = 0;

    protected int $total = 0;

    protected ?array $options = [
        'scales' => [
            'x' => ['grid' => ['display' => false], 'ticks' => ['display' => false]],
            'y' => ['grid' => ['display' => false], 'ticks' => ['display' => false]],
        ],
    ];

    protected function getData(): array
    {
        $data = $this->getCombinedNodeStatistics();
        $this->total = $data['storage_total'];
        $this->used = $data['storage_used'];
        $total = config('panel.use_binary_prefix')
            ? $this->total / 1024 / 1024 / 1024
            : $this->total / 1000 / 1000 / 1000;
        $used = config('panel.use_binary_prefix')
            ? $this->used / 1024 / 1024 / 1024
            : $this->used / 1000 / 1000 / 1000;

        return [
            'datasets' => [[
                'data' => [round($used, 2), round(max(0, $total - $used), 2)],
                'backgroundColor' => ['rgb(59, 130, 246)', 'rgb(74, 222, 128)'],
            ]],
            'labels' => [trans('admin/node.used'), trans('admin/node.unused')],
            'locale' => user()->language ?? 'en',
        ];
    }

    protected function getType(): string
    {
        return 'pie';
    }

    public function getHeading(): string
    {
        return trans('admin/node.disk_chart', [
            'used' => convert_bytes_to_readable($this->used),
            'total' => convert_bytes_to_readable($this->total),
        ]);
    }
}
