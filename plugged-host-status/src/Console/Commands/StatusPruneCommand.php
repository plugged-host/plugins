<?php

namespace PluggedHost\Status\Console\Commands;

use Illuminate\Console\Command;
use PluggedHost\Status\Services\StatusCollector;

class StatusPruneCommand extends Command
{
    protected $signature = 'plugged-host:status-prune';

    protected $description = 'Delete status telemetry outside the configured retention period';

    public function handle(StatusCollector $collector): int
    {
        $collector->prune();
        $this->components->info('Expired status telemetry pruned.');

        return self::SUCCESS;
    }
}
