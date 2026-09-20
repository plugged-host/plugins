<?php

namespace PluggedHost\Limbo\Console\Commands;

use App\Models\Server;
use Illuminate\Console\Command;
use PluggedHost\Limbo\Models\ServerLimboState;
use PluggedHost\Limbo\Services\LimboService;
use Throwable;

/**
 * The idle sweep (WP5). Scheduled every minute (LimboPluginProvider), and runnable by hand with
 * --status / --dry-run. Nothing here depends on a page being open.
 *
 * Guards, all of them: skip conflict-state / installing / restoring / mid-transfer servers,
 * skip suspended, skip nodes under maintenance, skip disk=0 (unbounded volume), a per-run cap
 * so a first run after an outage cannot start twenty transfers, and the global kill switch.
 */
class LimboSweepCommand extends Command
{
    protected $signature = 'plugged-host:limbo-sweep {--dry-run : Report actions without taking them} {--status : Alias for --dry-run}';

    protected $description = 'Stop and hibernate idle servers, and hand hibernated ones to the limbo node.';

    public function handle(LimboService $limbo): int
    {
        if (!$limbo->tableReady()) {
            $this->warn('server_limbo_states table missing — limbo is inert until the migration runs.');

            return self::SUCCESS;
        }

        $dryRun = (bool) ($this->option('dry-run') || $this->option('status'));
        $enabled = $limbo->enabled();

        if (!$enabled && !$dryRun) {
            $this->warn('LIMBO_ENABLED is false — limbo is dark. Use --status to preview.');

            return self::SUCCESS;
        }

        $stopHours = (int) config('plugged-host-limbo.stop_hours', 48);
        $transferHours = (int) config('plugged-host-limbo.transfer_hours', 72);
        $cap = max(1, (int) config('plugged-host-limbo.max_per_run', 3));

        $stopped = 0;
        $hibernated = 0;
        $acted = 0;

        foreach ($this->candidates() as $server) {
            if ($acted >= $cap) {
                break;
            }

            $idle = $limbo->idleHours($server);
            if ($idle === null) {
                continue;
            }

            $existing = $limbo->state($server);
            $current = $existing?->state;

            // Past transfer threshold and not already in limbo → hibernate.
            if ($idle >= $transferHours && !in_array($current, ['limbo', 'restoring'], true)) {
                $this->line(sprintf('  HIBERNATE  %s (idle %.1fh)', $server->uuid, $idle));
                if (!$dryRun && $limbo->hibernate($server)) {
                    $hibernated++;
                    $acted++;
                }
                continue;
            }

            // Past stop threshold and still running (no marker yet) → stop.
            if ($idle >= $stopHours && $current === null) {
                $this->line(sprintf('  STOP       %s (idle %.1fh)', $server->uuid, $idle));
                if (!$dryRun && $limbo->stop($server)) {
                    $stopped++;
                    $acted++;
                }
            }
        }

        $this->info(sprintf('limbo sweep: stopped=%d hibernated=%d%s', $stopped, $hibernated, $dryRun ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }

    /**
     * Servers eligible to be considered: excludes the guard cases up front so idle math only
     * runs on real candidates.
     *
     * @return iterable<int, Server>
     */
    private function candidates(): iterable
    {
        return Server::query()
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
