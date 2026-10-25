<?php

namespace PluggedHost\Limbo\Console\Commands;

use App\Facades\Activity;
use Illuminate\Console\Command;
use PluggedHost\Core\Services\GuardedServerDeletionService;
use PluggedHost\Core\Support\Duration;
use PluggedHost\Limbo\Services\LimboPurge;
use PluggedHost\Limbo\Services\LimboService;
use Throwable;

/**
 * Deletes servers parked in limbo for LIMBO_DELETE_AFTER_HOURS (LimboPurge says which). Hourly.
 * Before each deletion the owner's account gets an activity entry with the server's name, so the
 * record outlives the server. A node that does not answer leaves the server for the next run.
 */
class LimboPurgeCommand extends Command
{
    protected $signature = 'plugged-host:limbo-purge
                            {--dry-run : List what would be deleted without deleting anything}';

    protected $description = 'Delete servers parked in limbo once LIMBO_DELETE_AFTER_HOURS have passed.';

    public function handle(LimboService $limbo, GuardedServerDeletionService $deletion): int
    {
        if (!$limbo->tableReady()) {
            return self::SUCCESS;
        }

        $hours = LimboPurge::hours();
        $dryRun = (bool) $this->option('dry-run');

        if ($hours === 0) {
            $this->info('LIMBO_DELETE_AFTER_HOURS is 0: servers in limbo are never deleted.');

            return self::SUCCESS;
        }

        $rows = [];
        $deleted = 0;

        foreach (LimboPurge::due() as $server) {
            $parkedAt = $limbo->state($server)?->limbo_at;

            $row = [
                'id' => $server->id,
                'name' => mb_strimwidth($server->name, 0, 24, '…'),
                'owner' => $server->user?->username ?? '?',
                'node' => $server->node?->name ?? '?',
                'parked' => $parkedAt?->toDateTimeString() ?? '?',
                'result' => $dryRun ? 'would delete' : '',
            ];

            if ($dryRun) {
                $rows[] = $row;

                continue;
            }

            try {
                if ($server->user) {
                    Activity::event('server:limbo.deleted')
                        ->subject($server->user)
                        ->property([
                            'name' => $server->name,
                            'server_id' => $server->id,
                            'uuid' => $server->uuid,
                            'node' => $server->node?->name,
                            'limbo_at' => $parkedAt?->toIso8601String(),
                        ])
                        ->log();
                }

                $deletion->handle($server);
                $row['result'] = 'deleted';
                $deleted++;
            } catch (Throwable $e) {
                $row['result'] = 'failed: ' . mb_strimwidth($e->getMessage(), 0, 60, '…');
                report($e);
            }

            $rows[] = $row;
        }

        if ($rows === []) {
            $this->info(sprintf('No server has been in limbo for %s yet.', Duration::format($hours)));

            return self::SUCCESS;
        }

        $this->table(['ID', 'Name', 'Owner', 'Node', 'In limbo since', 'Result'], $rows);
        $this->info(sprintf('limbo purge: %d due, %d deleted%s', count($rows), $deleted, $dryRun ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }
}
