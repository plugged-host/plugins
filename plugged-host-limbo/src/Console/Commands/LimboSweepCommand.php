<?php

namespace PluggedHost\Limbo\Console\Commands;

use App\Models\Server;
use Illuminate\Console\Command;
use PluggedHost\Core\Support\SystemServers;
use PluggedHost\Limbo\Models\ServerLimboState;
use PluggedHost\Limbo\Services\LimboService;
use Throwable;

/**
 * The idle sweep (WP5). Scheduled every minute (LimboPluginProvider), and runnable by hand with
 * --status / --dry-run. Nothing here depends on a page being open.
 *
 * Every run first settles moves wings has finished (LimboService::reconcile()) — also while
 * LIMBO_ENABLED is false, so switching limbo off never strands a server mid-move.
 *
 * Guards, all of them: skip conflict-state / installing / restoring / mid-transfer servers,
 * skip suspended, skip nodes under maintenance, skip disk=0 (unbounded volume), only move a
 * server the sweep has already stopped, back off a day after a failed move, cap the moves in
 * flight at once (not per run: a move takes minutes, and the limbo node is small), pause moves
 * while the limbo node refuses them or its disk is full (LimboService::moveBudget()), and the
 * global kill switch.
 *
 * --status also names the limbo node (where new servers go) and every node servers are parked on.
 */
class LimboSweepCommand extends Command
{
    /** Hours before a server whose move to limbo failed is tried again. */
    private const RETRY_HOURS = 24;

    protected $signature = 'plugged-host:limbo-sweep {--dry-run : Report actions without taking them} {--status : Alias for --dry-run}';

    protected $description = 'Stop and hibernate idle servers, and hand hibernated ones to the limbo node.';

    public function handle(LimboService $limbo): int
    {
        if (!$limbo->tableReady()) {
            $this->warn('server_limbo_states table missing — limbo is inert until the migration runs.');

            return self::SUCCESS;
        }

        $dryRun = (bool) ($this->option('dry-run') || $this->option('status'));

        if ($dryRun) {
            $this->describeNodes($limbo);
        }

        foreach ($limbo->reconcile($dryRun) as $line) {
            $this->line('  ' . $line);
        }

        if (!$limbo->enabled() && !$dryRun) {
            $this->warn('LIMBO_ENABLED is false — limbo is dark. Use --status to preview.');

            return self::SUCCESS;
        }

        $stopHours = (int) config('plugged-host-limbo.stop_hours', 48);
        $transferHours = (int) config('plugged-host-limbo.transfer_hours', 72);
        $cap = max(1, (int) config('plugged-host-limbo.max_per_run', 3));

        $moveSlots = $limbo->moveBudget($cap);

        if ($moveSlots === 0 && ServerLimboState::query()->where('state', 'hibernating')->count() < $cap) {
            $this->warn($limbo->limboNodeFull()
                ? sprintf('The limbo node\'s disk is over %d%% (LIMBO_MAX_DISK_PERCENT) — new moves are paused.', (int) config('plugged-host-limbo.max_disk_percent', 85))
                : 'The last moves to the limbo node all failed — new moves are paused (one probe an hour).');
        }

        $stopped = 0;
        $hibernated = 0;

        foreach ($this->candidates() as $server) {
            if ($stopped >= $cap && $hibernated >= $moveSlots) {
                break;
            }

            $idle = $limbo->idleHours($server);
            if ($idle === null) {
                continue;
            }

            $existing = $limbo->state($server);
            $current = $existing?->state;

            // Stopped by us and past the transfer threshold (or a failed move, after the back-off)
            // → move it to limbo.
            $retry = $current === 'failed' && $existing->updated_at?->lte(now()->subHours(self::RETRY_HOURS));

            if ($idle >= $transferHours && ($current === 'stopped' || $retry)) {
                if ($hibernated >= $moveSlots) {
                    continue;
                }

                $this->line(sprintf('  HIBERNATE  %s (idle %.1fh)', $server->uuid, $idle));
                $hibernated++;

                if (!$dryRun) {
                    $limbo->hibernate($server);
                }

                continue;
            }

            // Past stop threshold and no marker yet (or back from limbo) → stop.
            if ($idle >= $stopHours && in_array($current, [null, 'restored'], true)) {
                if ($stopped >= $cap) {
                    continue;
                }

                $this->line(sprintf('  STOP       %s (idle %.1fh)', $server->uuid, $idle));
                $stopped++;

                if (!$dryRun) {
                    $limbo->stop($server);
                }
            }
        }

        $this->info(sprintf('limbo sweep: stopped=%d hibernated=%d%s', $stopped, $hibernated, $dryRun ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }

    private function describeNodes(LimboService $limbo): void
    {
        $nodes = $limbo->nodeSummary();

        if (!collect($nodes)->contains('destination', true)) {
            $this->warn(sprintf('No node has the limbo tag (%s): nothing new is parked.', config('plugged-host-limbo.node_tag', 'limbo')));
        }

        foreach ($nodes as $row) {
            $this->line(sprintf(
                '  %-11s %s (#%d): %d parked%s',
                $row['destination'] ? 'LIMBO NODE' : 'PARKED ON',
                $row['node']->name,
                $row['node']->getKey(),
                $row['parked'],
                $row['disk_percent'] !== null ? sprintf(', disk %.1f%% used', $row['disk_percent']) : '',
            ));
        }
    }

    /**
     * Servers eligible to be considered: excludes the guard cases up front so idle math only
     * runs on real candidates.
     *
     * @return iterable<int, Server>
     */
    private function candidates(): iterable
    {
        // System servers (devboxes) stop themselves when idle and are never hibernated.
        return SystemServers::exclude(Server::query())
            ->with('node')
            ->get()
            ->reject(function (Server $server): bool {
                try {
                    if ((int) $server->disk === 0) {
                        return true; // unbounded volume — cannot be moved predictably
                    }
                    if (method_exists($server, 'isInConflictState') && $server->isInConflictState()) {
                        return true; // installing / suspended / transferring / restoring
                    }
                    if ($server->node?->maintenance_mode) {
                        return true;
                    }
                } catch (Throwable) {
                    return true;
                }

                return false;
            });
    }
}
