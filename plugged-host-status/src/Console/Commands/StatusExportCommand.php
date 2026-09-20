<?php

namespace PluggedHost\Status\Console\Commands;

use Illuminate\Console\Command;
use PluggedHost\Status\Services\StatusExporter;

class StatusExportCommand extends Command
{
    protected $signature = 'plugged-host:status-export';

    protected $description = 'Regenerate the public status JSON and incident feed';

    public function handle(StatusExporter $exporter): int
    {
        $exporter->export();
        $this->components->info('Status data exported.');

        return self::SUCCESS;
    }
}
