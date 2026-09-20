<?php

namespace PluggedHost\Status\Console\Commands;

use Illuminate\Console\Command;
use PluggedHost\Status\Services\StatusCollector;
use PluggedHost\Status\Services\StatusExporter;

class StatusCollectCommand extends Command
{
    protected $signature = 'plugged-host:status-collect';

    protected $description = 'Collect public service telemetry and atomically export the status page data';

    public function handle(StatusCollector $collector, StatusExporter $exporter): int
    {
        $collector->collect();
        $collector->prune();
        $exporter->export();

        $this->components->info('Status telemetry collected and exported.');

        return self::SUCCESS;
    }
}
