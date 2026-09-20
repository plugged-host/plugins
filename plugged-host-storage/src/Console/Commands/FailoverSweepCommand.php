<?php

namespace PluggedHost\Storage\Console\Commands;

use Illuminate\Console\Command;
use PluggedHost\Storage\Services\FailoverService;

/**
 * Manual entry point to the failover sweep, for operators to watch its verdicts (especially in
 * the first week) without waiting on the schedule. --dry-run detects but never recovers.
 */
class FailoverSweepCommand extends Command
{
    protected $signature = 'plugged-host:failover-sweep {--dry-run : Detect and report but never recover} {--status : Alias for --dry-run}';

    protected $description = 'Detect offline nodes and (in auto mode) recover their servers from the storage mirror.';

    public function handle(FailoverService $failover): int
    {
        $dryRun = (bool) ($this->option('dry-run') || $this->option('status'));

        $result = $failover->sweep($dryRun);

        $this->info(sprintf(
            'mode=%s  nodes_down=%d  acted=%d%s',
            $result['mode'] ?? '?',
            $result['nodes_down'] ?? 0,
            $result['acted'] ?? 0,
            $dryRun ? '  (dry-run)' : '',
        ));

        if (isset($result['error'])) {
            $this->warn('note: ' . $result['error']);
        }

        return self::SUCCESS;
    }
}
