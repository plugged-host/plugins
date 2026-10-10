<?php

namespace PluggedHost\Storage\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use PluggedHost\Storage\Models\DeletedServer;
use PluggedHost\Storage\Services\DeletedServers;
use PluggedHost\Storage\Services\StorageService;

/**
 * Deleted servers whose copy is still on the storage box: the admin's way back to a server
 * deleted by mistake (its owner has the backups portal). --url prints a signed download link
 * (valid STORAGE_SIGNED_TTL seconds) to fetch the archive, or to pull it into a new server.
 */
class DeletedServersCommand extends Command
{
    protected $signature = 'plugged-host:deleted-servers
                            {--url= : Print a signed download link for this uuid (or its first 8 characters)}
                            {--extend= : Keep this uuid\'s copy longer}
                            {--days=7 : With --extend, keep it this many days from now}
                            {--all : Also list copies that are due for removal or already removed}';

    protected $description = 'List deleted servers whose copy on the storage box is kept, with a download link or a longer hold.';

    public function handle(DeletedServers $deleted, StorageService $storage): int
    {
        if (!$deleted->ready()) {
            $this->error('The storage_deleted_servers table does not exist yet: run the plugin migrations.');

            return self::FAILURE;
        }

        if (filled($this->option('url')) || filled($this->option('extend'))) {
            $row = $this->find((string) ($this->option('url') ?: $this->option('extend')));

            if ($row === null) {
                $this->error('No deleted server with that uuid.');

                return self::FAILURE;
            }

            if (filled($this->option('extend'))) {
                $row->update(['keep_until' => now()->addDays(max(1, (int) $this->option('days'))), 'purged_at' => null]);
                $this->info("Copy of {$row->uuid} kept until {$row->keep_until->toDateTimeString()}.");
            }

            if (filled($this->option('url'))) {
                $url = $storage->archiveUrlForIdentifier($row->uuid, 'backup-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $row->name) . "-{$row->uuidShort()}.tar.gz");
                $url === null ? $this->error('The storage agent is not configured.') : $this->line($url);
            }

            return self::SUCCESS;
        }

        $rows = DeletedServer::query()
            ->when(!$this->option('all'), fn ($q) => $q->held())
            ->orderByDesc('deleted_at')
            ->get();

        $owners = User::query()->whereIn('id', $rows->pluck('owner_id')->filter()->unique())->pluck('username', 'id');
        // Not for orphans: hundreds of /info calls (each a du on the box) would stall the agent.
        $infos = $storage->backupInfoMany($rows->where('reason', '!=', 'orphan')->pluck('uuid')->all());

        $this->table(
            ['uuid', 'name', 'owner', 'reason', 'deleted', 'kept until', 'copy'],
            $rows->map(fn (DeletedServer $row) => [
                $row->uuid,
                mb_strimwidth((string) $row->name, 0, 24, '…'),
                $row->owner_id ? ($owners[$row->owner_id] ?? "#{$row->owner_id}") : '',
                $row->reason,
                $row->deleted_at?->toDateTimeString(),
                $row->purged_at ? 'removed ' . $row->purged_at->toDateTimeString() : $row->keep_until?->toDateTimeString(),
                match (true) {
                    !array_key_exists($row->uuid, $infos) => '',
                    $infos[$row->uuid] === null => '?',
                    (bool) ($infos[$row->uuid]['exists'] ?? false) => round(((int) ($infos[$row->uuid]['bytes'] ?? 0)) / 1e9, 2) . ' GB',
                    default => 'none',
                },
            ])->all(),
        );

        $this->line(sprintf('%d deleted server(s). Hold: %d days after an admin or the panel deleted one.', $rows->count(), $deleted->holdDays()));

        $at = $deleted->lastReportAt();
        $this->line($at === null
            ? 'The storage agent has not reported its folders yet (agent 1.4.0+, hourly): nothing is being removed.'
            : 'Last cleanup report from the storage agent: ' . now()->setTimestamp($at)->diffForHumans() . '.');

        return self::SUCCESS;
    }

    private function find(string $id): ?DeletedServer
    {
        $id = strtolower(trim($id));

        if (preg_match('/^[0-9a-f-]{8,36}$/', $id) !== 1) {
            return null;
        }

        return DeletedServer::query()->where('uuid', 'like', $id . '%')->orderByDesc('deleted_at')->first();
    }
}
