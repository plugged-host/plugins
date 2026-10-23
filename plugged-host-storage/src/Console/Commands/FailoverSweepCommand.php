<?php

namespace PluggedHost\Storage\Console\Commands;

use App\Models\Node;
use App\Models\Server;
use Illuminate\Console\Command;
use PluggedHost\Storage\Models\NodeFailover;
use PluggedHost\Storage\Services\FailoverService;

/**
 * Manual entry point to the failover sweep, for operators to watch its verdicts (especially in
 * the first week) without waiting on the schedule. --dry-run detects but never recovers or
 * notifies. --drill=<server id> rehearses a recovery on one server whose node is healthy (the
 * scheduled sweep then carries it through install and restore, and removes the old copy at the
 * end). --resume=<node_failovers id> retries the restore of a failed recovery that already moved
 * its server. --node=<id|name> declares a node gone (a reinstalled machine or a lost IP: the host
 * refuses wings' port, which the automatic detection reads as "up"), so the sweep recovers its
 * servers while wings misses its probes; it ends by itself once wings answers, or with
 * --clear-node. All three are refused while STORAGE_FAILOVER_MODE is off: the sweep that carries them on
 * does not run then, and a drill would leave its server moved, installed fresh and without its files.
 */
class FailoverSweepCommand extends Command
{
    protected $signature = 'plugged-host:failover-sweep {--dry-run : Detect and report but never recover or notify} {--status : Alias for --dry-run} {--drill= : Rehearse a recovery on this server id (its node must be healthy)} {--resume= : Pick a failed recovery (node_failovers id) up again at its restore} {--node= : Declare this node (id or name) gone: recover its servers while wings misses its probes} {--clear-node= : Withdraw a --node declaration}';

    protected $description = 'Detect offline nodes and (in auto mode) recover their servers from the storage mirror.';

    public function handle(FailoverService $failover): int
    {
        if ($this->option('clear-node') !== null) {
            $node = $this->node((string) $this->option('clear-node'));
            if (!$node instanceof Node) {
                return self::FAILURE;
            }

            $this->info($failover->undeclare($node) ? "{$node->name} is no longer declared down." : "{$node->name} was not declared down.");

            return self::SUCCESS;
        }

        if (($this->option('resume') !== null || $this->option('drill') !== null || $this->option('node') !== null) && $failover->mode() === 'off') {
            $this->error('Failover is off (STORAGE_FAILOVER_MODE=off), so no sweep would carry this past its first step. Set the mode to notify or auto first.');

            return self::FAILURE;
        }

        if ($this->option('resume') !== null) {
            $record = NodeFailover::query()->find((int) $this->option('resume'));
            $ok = $record instanceof NodeFailover && $failover->resume($record);
            $this->info($ok ? 'Resumed: the next sweep restarts its restore.' : 'Not resumed: only a failed recovery whose server sits on its destination can be.');

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        if ($this->option('node') !== null) {
            $node = $this->node((string) $this->option('node'));
            if (!$node instanceof Node) {
                return self::FAILURE;
            }

            $failover->declareDown($node);
            $this->info("{$node->name} is declared down: from the next sweep (every minute) its servers are recovered elsewhere, a few at a time, while wings misses its probes. It ends by itself once wings answers again; --clear-node={$node->id} withdraws it.");

            return self::SUCCESS;
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

        $declared = Node::query()->whereKey($failover->declaredDown())->pluck('name')->all();
        if ($declared !== []) {
            $this->warn('declared down by an operator: ' . implode(', ', $declared));
        }

        return self::SUCCESS;
    }

    private function node(string $ref): ?Node
    {
        $node = ctype_digit($ref) ? Node::query()->find((int) $ref) : Node::query()->where('name', $ref)->first();

        if (!$node instanceof Node) {
            $this->error("No node {$ref}.");
        }

        return $node;
    }
}
