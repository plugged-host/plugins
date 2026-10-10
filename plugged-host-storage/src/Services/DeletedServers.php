<?php

namespace PluggedHost\Storage\Services;

use App\Models\Server;
use App\Models\ServerTransfer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PluggedHost\Storage\Models\DeletedServer;
use Throwable;

/**
 * What happens to a deleted server's copy on the storage box.
 *
 * Deleted by an admin, or by the panel itself (abuse purge, an account deleted through
 * noprofit, ...): the copy is kept for `deleted_hold_days` (7), and the owner still finds the
 * server, under its ID, on the backups portal, in case it was deleted by mistake. Deleted by its
 * owner: the copy goes at the agent's next cleanup.
 *
 * The agent never decides on its own what to delete. Once an hour it reports the server folders
 * it holds; sweep() answers with the ones whose server is gone and whose hold is over, and the
 * agent removes exactly those. A folder the panel has no record of (deleted before this existed,
 * or missed) is kept for the hold counted from its last copy, and at least a day from when it
 * was first reported; one with no completed copy only that day.
 */
class DeletedServers
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private const LAST_REPORT = 'plugged-host-storage:last-inventory';

    private static ?bool $ready = null;

    public function __construct(private StorageService $storage) {}

    /** Inert until the migration has run. */
    public function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = Schema::hasTable('storage_deleted_servers');
            } catch (Throwable) {
                return false;
            }
        }

        return self::$ready;
    }

    /** Unix time of the agent's last folder report, or null. */
    public function lastReportAt(): ?int
    {
        // Redis hands numbers back as strings.
        $at = Cache::get(self::LAST_REPORT);

        return is_numeric($at) ? (int) $at : null;
    }

    public function holdDays(): int
    {
        return max(0, (int) config('plugged-host-storage.deleted_hold_days', 7));
    }

    /**
     * Record a server that is being deleted (Server `deleting`, inside the deletion's own
     * transaction). Whoever is signed in decides the hold: the owner deleting their own server
     * gets none, anyone else (an admin) or no one (a command, a queued job) the full hold.
     */
    public function remember(Server $server): void
    {
        if (!$this->ready() || !is_string($server->uuid) || $server->uuid === '') {
            return;
        }

        $actor = auth()->user();
        $actorId = $actor?->getAuthIdentifier();
        $byOwner = $actorId !== null && (int) $actorId === (int) $server->owner_id;
        $now = CarbonImmutable::now();

        DeletedServer::query()->updateOrCreate(['uuid' => $server->uuid], [
            'server_id' => $server->id,
            'owner_id' => $server->owner_id,
            'name' => $server->name,
            'egg' => $server->egg?->name,
            'node' => $server->node?->name,
            'deleted_by' => $actorId,
            'reason' => $byOwner ? 'owner' : ($actorId !== null ? 'admin' : 'system'),
            'deleted_at' => $now,
            'keep_until' => $byOwner ? $now : $now->addDays($this->holdDays()),
            'purged_at' => null,
        ]);
    }

    /**
     * The owner's deleted servers whose copy is still kept (their own deletions excluded).
     *
     * @return Collection<int, DeletedServer>
     */
    public function forOwner(int $userId): Collection
    {
        if (!$this->ready()) {
            return collect();
        }

        return DeletedServer::query()
            ->held()
            ->where('owner_id', $userId)
            ->whereIn('reason', ['admin', 'system'])
            ->orderByDesc('deleted_at')
            ->get();
    }

    /**
     * The agent's hourly report: the server folders on the storage box (mirror, snapshots,
     * cached archive) and, when the box is also a wings node, its volume folders. Answers with
     * what may be removed now.
     *
     * @param  array<int, mixed>  $copies  [{uuid, last_copy (ISO time of the newest snapshot) | null}]
     * @param  array<int, mixed>  $volumes  volume folder names
     * @return array{purge: list<string>, purge_volumes: list<string>}
     */
    public function sweep(array $copies, array $volumes): array
    {
        $none = ['purge' => [], 'purge_volumes' => []];

        if (!$this->ready()) {
            return $none;
        }

        Cache::put(self::LAST_REPORT, time(), now()->addDays(7));

        $lastCopy = [];
        foreach ($copies as $copy) {
            $uuid = is_array($copy) ? strtolower((string) ($copy['uuid'] ?? '')) : '';
            if (preg_match(self::UUID, $uuid) === 1) {
                $lastCopy[$uuid] = $this->time($copy['last_copy'] ?? null);
            }
        }

        $volumes = array_values(array_unique(array_filter(
            array_map(fn ($v) => strtolower((string) $v), $volumes),
            fn (string $v) => preg_match(self::UUID, $v) === 1,
        )));

        $seen = array_values(array_unique([...array_keys($lastCopy), ...$volumes]));
        $now = CarbonImmutable::now();

        $servers = Server::query()->whereIn('uuid', $seen)->get(['id', 'uuid', 'node_id'])->keyBy('uuid');
        $rows = DeletedServer::query()->whereIn('uuid', $seen)->get()->keyBy('uuid');

        $due = [];
        DB::transaction(function () use ($seen, $servers, $rows, $lastCopy, $now, &$due): void {
            foreach ($seen as $uuid) {
                if ($servers->has($uuid)) {
                    continue;
                }

                $row = $rows->get($uuid) ?? $this->orphan($uuid, $lastCopy[$uuid] ?? null, $now);

                if ($row->keep_until === null || $row->keep_until->lte($now)) {
                    $due[$uuid] = true;
                }
            }
        });

        // Gone from the box (removed, or never copied) once the hold is over.
        DeletedServer::query()
            ->whereNull('purged_at')
            ->where('keep_until', '<=', $now)
            ->whereNotIn('uuid', $seen)
            ->update(['purged_at' => $now]);

        if (!(bool) config('plugged-host-storage.purge_deleted', true)) {
            return $none;
        }

        return [
            'purge' => array_values(array_filter(array_keys($lastCopy), fn (string $u) => isset($due[$u]))),
            'purge_volumes' => array_values(array_filter($volumes, fn (string $u) => isset($due[$u]) || $this->leftoverVolume($servers->get($u)))),
        ];
    }

    /**
     * A copy the panel has no record of: kept for the hold from its last copy, at least a day
     * from now. One with no completed copy (a first copy that never finished) holds nothing the
     * agent would serve or restore from: just the day.
     */
    private function orphan(string $uuid, ?CarbonImmutable $lastCopy, CarbonImmutable $now): DeletedServer
    {
        $deletedAt = $lastCopy !== null && $lastCopy->lt($now) ? $lastCopy : $now;
        $keepUntil = $lastCopy !== null ? $deletedAt->addDays($this->holdDays()) : $now;

        return DeletedServer::query()->create([
            'uuid' => $uuid,
            'reason' => 'orphan',
            'deleted_at' => $deletedAt,
            'keep_until' => $keepUntil->max($now->addDay()),
        ]);
    }

    /**
     * A wings volume on the storage box whose server lives on another node: what a move away
     * left behind. Never while the server has a transfer in flight, or one touched in the last
     * hour (the volume is what wings is sending or receiving).
     */
    private function leftoverVolume(?Server $server): bool
    {
        $local = $this->storage->localNode();

        if ($server === null || $local === null || (int) $server->node_id === (int) $local->id) {
            return false;
        }

        return !ServerTransfer::query()
            ->where('server_id', $server->id)
            ->where(fn ($q) => $q->whereNull('successful')->orWhere('updated_at', '>', now()->subHour()))
            ->exists();
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
