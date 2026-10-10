<?php

namespace PluggedHost\Storage\Console\Commands;

use App\Models\Server;
use Illuminate\Console\Command;
use PluggedHost\Storage\Models\NodeFailover;
use PluggedHost\Storage\Services\FailoverService;

/**
 * Manual entry point to the failover sweep, for operators to watch its verdicts (especially in
 * the first week) without waiting on the schedule. --dry-run detects but never recovers.
 * --drill=<server id> rehearses a recovery on one server whose node is healthy (the scheduled
 * sweep then carries it through install and restore, and removes the old copy at the end).
 * --resume=<node_failovers id> retries the restore of a failed recovery that already moved its server.
 */
class FailoverSweepCommand extends Command
{
    protected $signature = 'plugged-host:failover-sweep {--dry-run : Detect and report but never recover} {--status : Alias for --dry-run} {--drill= : Rehearse a recovery on this server id (its node must be healthy)} {--resume= : Pick a failed recovery (node_failovers id) up again at its restore}';

    protected $description = 'Detect offline nodes and (in auto mode) recover their servers from the storage mirror.';

    public function handle(FailoverService $failover): int
    {
        if ($this->option('resume') !== null) {
            $record = NodeFailover::query()->find((int) $this->option('resume'));
            $ok = $record instanceof NodeFailover && $failover->resume($record);
            $this->info($ok ? 'Resumed: the next sweep restarts its restore.' : 'Not resumed: only a failed recovery whose server sits on its destination can be.');

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        if ($this->option('drill') !== null) {
            $server = Server::query()->find((int) $this->option('drill'));
            if (!$server instanceof Server) {
                $this->error('No such server.');

                return self::FAILURE;
            }

            $record = $failover->drill($server);
            $this->info($record === null
                ? 'Not started: the server is already being recovered or transferred.'
                : sprintf('record #%d  state=%s  to_node=%s  %s', $record->id, $record->state, $record->to_node_id ?? '-', $record->detail['error'] ?? ''));

            return self::SUCCESS;
        }

        $dryRun = (bool) ($this->option('dry-run') || $this->option('status'));

        $result = $failover->sweep($dryRun);

        $this->info(sprintf(
            'mode=%s  nodes_down=%d  acted=%d  in_flight=%d  advanced=%d%s',
            $result['mode'] ?? '?',
            $result['nodes_down'] ?? 0,
            $result['acted'] ?? 0,
            $result['in_flight'] ?? 0,
            $result['advanced'] ?? 0,
            $dryRun ? '  (dry-run)' : '',
        ));

        if (isset($result['error'])) {
            $this->warn('note: ' . $result['error']);
        }

        return self::SUCCESS;
    }
}
