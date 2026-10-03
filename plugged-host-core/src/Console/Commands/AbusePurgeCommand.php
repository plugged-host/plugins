<?php

namespace PluggedHost\Core\Console\Commands;

use App\Enums\ServerState;
use App\Facades\Activity;
use App\Models\Server;
use Illuminate\Console\Command;
use PluggedHost\Core\Services\Abuse\AbuseAlerts;
use PluggedHost\Core\Services\Abuse\AbusePurge;
use PluggedHost\Core\Services\GuardedServerDeletionService;
use PluggedHost\Core\Support\SuspensionReason;
use Throwable;

/**
 * Deletes servers the abuse checks suspended once ABUSE_DELETE_AFTER_HOURS have passed (AbusePurge
 * says which). Hourly. Before each deletion the owner's account gets an activity entry with the
 * server's name and the reasons, so the record outlives the server.
 */
class AbusePurgeCommand extends Command
{
    protected $signature = 'plugged-host:abuse-purge
                            {--dry-run : List what would be deleted, and when, without deleting anything}';

    protected $description = 'Delete servers suspended by the abuse checks once ABUSE_DELETE_AFTER_HOURS have passed.';

    public function handle(GuardedServerDeletionService $deletion, AbuseAlerts $alerts): int
    {
        $hours = AbusePurge::hours();
        $dryRun = (bool) $this->option('dry-run');

        if ($hours === 0) {
            $this->info('ABUSE_DELETE_AFTER_HOURS is 0: abuse-suspended servers are never deleted.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach (Server::query()->where('status', ServerState::Suspended->value)->with(['user', 'node'])->orderBy('id')->get() as $server) {
            $at = AbusePurge::deletesAt($server);

            if ($at === null) {
                continue;
            }

            $row = [
                'id' => $server->id,
                'name' => mb_strimwidth($server->name, 0, 24, '…'),
                'owner' => $server->user?->username ?? '?',
                'node' => $server->node?->name ?? '?',
                'deletes' => $at->toDateTimeString() . ' (' . $at->diffForHumans() . ')',
                'result' => $at->isFuture() ? 'waiting' : ($dryRun ? 'would delete' : ''),
            ];

            if ($at->isFuture() || $dryRun) {
                $rows[] = $row;

                continue;
            }

            $reasons = SuspensionReason::latest($server)['reasons'] ?? [];

            try {
                if ($server->user) {
                    Activity::event('server:abuse.deleted')
                        ->subject($server->user)
                        ->property([
                            'name' => $server->name,
                            'server_id' => $server->id,
                            'uuid' => $server->uuid,
                            'node' => $server->node?->name,
                            'reasons' => $reasons,
                        ])
                        ->log();
                }

                $deletion->handle($server);
                $row['result'] = 'deleted';
                $alerts->deleted($server, $reasons);
            } catch (Throwable $e) {
                // The node may be down; the next run tries again.
                $row['result'] = 'failed: ' . mb_strimwidth($e->getMessage(), 0, 60, '…');
                report($e);
            }

            $rows[] = $row;
        }

        if ($rows === []) {
            $this->info('No abuse-suspended servers are due for deletion.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Name', 'Owner', 'Node', 'Deleted at', 'Result'], $rows);

        return self::SUCCESS;
    }
}
