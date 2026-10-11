<?php

namespace PluggedHost\Servers\Services\Transfers;

use App\Models\Backup;
use App\Models\Node;
use App\Models\Server;
use App\Models\ServerTransfer;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PluggedHost\Core\Support\SystemServers;
use PluggedHost\Servers\Exceptions\TransferException;
use PluggedHost\Servers\Models\ServerRelocation;
use PluggedHost\Servers\Services\NodeBalancer;
use Throwable;

/**
 * "Lighten the load" on the admin node page: which of a node's servers to take off it, which
 * nodes can take them, and queueing the moves.
 *
 * Each move is an ordinary admin move (TransferService, origin admin, pinned to the chosen
 * node). They are queued rather than started: a queued move waits in PREPARING, holding
 * nothing, until fewer than `transfers.batch_parallel` moves off the same node are under way
 * (TransferService::takeTurn), so a node is never asked to stream a whole batch at once.
 */
class LoadLightener
{
    /** Offline servers: nobody is kicked and they stay stopped. */
    public const PICK_STOPPED = 'stopped';

    /** Running servers using the most memory: stopped, moved and started again. */
    public const PICK_BUSIEST = 'busiest';

    public function __construct(
        protected TransferService $transfers,
        protected NodeBalancer $balancer,
    ) {}

    public function parallel(): int
    {
        return max(1, (int) config('plugged-host-servers.transfers.batch_parallel', 2));
    }

    /**
     * What wings says about every server on the node, by uuid, or null when it does not
     * answer. Cached briefly: the form asks again on every change.
     *
     * @return array<string, array{state: string, memory_used: int, cpu: float, data: int}>|null
     */
    public function live(Node $node): ?array
    {
        return Cache::remember('plugged-host-servers:lighten-live:' . $node->getKey(), 20, function () use ($node): ?array {
            try {
                $response = Http::daemon($node)->connectTimeout(3)->timeout(10)->get('/api/servers');
            } catch (Throwable) {
                return null;
            }

            if (!$response->successful() || !is_array($response->json())) {
                return null;
            }

            $live = [];

            foreach ((array) $response->json() as $entry) {
                $uuid = (string) data_get($entry, 'configuration.uuid', '');

                if ($uuid === '') {
                    continue;
                }

                $live[$uuid] = [
                    'state' => (string) data_get($entry, 'state', 'offline'),
                    'memory_used' => (int) data_get($entry, 'utilization.memory_bytes', 0),
                    'cpu' => (float) data_get($entry, 'utilization.cpu_absolute', 0),
                    'data' => (int) data_get($entry, 'utilization.disk_bytes', 0),
                ];
            }

            return $live;
        });
    }

    /**
     * The servers on $node a batch could take, in the order it takes them. Left out up front:
     * anything a move would refuse anyway (installing, restoring, suspended, no port, a move
     * or backup under way) and servers wings does not report. Null when the node does not
     * answer.
     *
     * @return Collection<int, array{id: int, name: string, owner: string, memory: int, state: string, memory_used: int, cpu: float, data: int}>|null
     */
    public function candidates(Node $node, string $pick): ?Collection
    {
        $live = $this->live($node);

        if ($live === null) {
            return null;
        }

        $servers = SystemServers::exclude(Server::query())
            ->where('node_id', $node->getKey())
            ->whereNull('status')
            ->whereNotNull('allocation_id')
            ->with('user:id,username')
            ->get(['id', 'uuid', 'name', 'owner_id', 'memory', 'node_id']);

        $ids = $servers->modelKeys();

        $busy = array_flip(array_map(intval(...), array_merge(
            ServerRelocation::query()->active()->whereIn('server_id', $ids)->pluck('server_id')->all(),
            ServerTransfer::query()->whereNull('successful')->whereIn('server_id', $ids)->pluck('server_id')->all(),
            Backup::query()->whereIn('server_id', $ids)->whereNull('completed_at')->where('created_at', '>', now()->subHours(6))->pluck('server_id')->all(),
        )));

        $rows = $servers
            // Held: someone is working in a devbox on it right now (SystemServers::held).
            ->reject(fn (Server $server) => isset($busy[(int) $server->getKey()]) || !isset($live[$server->uuid]) || SystemServers::held($server))
            ->map(fn (Server $server) => [
                'id' => (int) $server->getKey(),
                'name' => (string) $server->name,
                'owner' => (string) ($server->user?->username ?? '—'),
                'memory' => (int) $server->memory,
                ...$live[$server->uuid],
            ]);

        return (match ($pick) {
            // The most memory promised first: the most room freed per move.
            self::PICK_STOPPED => $rows
                ->filter(fn (array $row) => $row['state'] === 'offline')
                ->sortBy([['memory', 'desc'], ['data', 'asc']]),
            // The most memory in use right now first, then CPU.
            self::PICK_BUSIEST => $rows
                ->filter(fn (array $row) => in_array($row['state'], ['running', 'starting'], true))
                ->sortBy([['memory_used', 'desc'], ['cpu', 'desc']]),
            default => collect(),
        })->values();
    }

    /**
     * Nodes a batch from $from can go to, as Select options grouped by location, the coolest
     * first in each. Nodes in maintenance or not answering are listed but end up in $disabled.
     *
     * @param  Collection<int, Node>  $accessible
     * @param  array<int, string>  $disabled  filled by reference
     * @return array<string, array<string, string>>
     */
    public function destinationOptions(Node $from, Collection $accessible, array &$disabled = []): array
    {
        $nodes = Node::query()
            ->whereIn('id', $accessible->modelKeys())
            ->whereKeyNot($from->getKey())
            ->withCount('servers')
            ->get();

        $metrics = $this->balancer->metricsForNodes($nodes);
        $groups = [];

        foreach ($nodes->sortBy(fn (Node $node) => $metrics->get($node->getKey())['score'] ?? 999999.0) as $node) {
            $code = $this->transfers->planner()->locationOf($node);
            $group = $code === null
                ? trans('plugged-host-servers::strings.transfers.unknown_location')
                : $this->transfers->locationName($code);

            $metric = $metrics->get($node->getKey());
            $down = ($metric['source'] ?? 'unreachable') === 'unreachable';
            $key = (string) $node->getKey();

            $groups[$group][$key] = match (true) {
                $node->isUnderMaintenance() => trans('plugged-host-servers::strings.transfers.lighten.node_maintenance', ['name' => $node->name]),
                $down => trans('plugged-host-servers::strings.transfers.lighten.node_down', ['name' => $node->name]),
                default => trans('plugged-host-servers::strings.transfers.lighten.node_line', [
                    'name' => $node->name,
                    'servers' => (int) $node->servers_count,
                    'ram' => (int) round((float) ($metric['memory_percent'] ?? 0)),
                    'cpu' => (int) round((float) ($metric['cpu_percent'] ?? 0)),
                ]),
            };

            if ($down || $node->isUnderMaintenance()) {
                $disabled[] = $key;
            }
        }

        ksort($groups);

        return $groups;
    }

    /**
     * Queue moves of these servers, in this order, from $from to $to. Each one is checked the
     * way a single admin move is; the ones refused are counted by reason.
     *
     * @param  array<int, int>  $serverIds
     * @return array{queued: int, refused: array<string, int>}
     */
    public function queue(Node $from, Node $to, array $serverIds, User $admin, string $pick): array
    {
        $batch = (string) Str::ulid();
        $servers = Server::query()->where('node_id', $from->getKey())->whereIn('id', $serverIds)->get()->keyBy(fn (Server $server) => (int) $server->getKey());
        $queued = 0;
        $refused = [];

        foreach (array_map(intval(...), $serverIds) as $id) {
            $server = $servers->get($id);

            if (!$server instanceof Server) {
                $refused['moved'] = ($refused['moved'] ?? 0) + 1;

                continue;
            }

            try {
                $this->transfers->start($server, $admin, null, ServerRelocation::ORIGIN_ADMIN, [(int) $to->getKey()], false, [
                    'batch' => $batch,
                    'queued' => true,
                    'parallel' => $this->parallel(),
                    'only_stopped' => $pick === self::PICK_STOPPED,
                ]);
                $queued++;
            } catch (TransferException $e) {
                $refused[$e->reason] = ($refused[$e->reason] ?? 0) + 1;
            } catch (Throwable $e) {
                report($e);
                $refused['internal_error'] = ($refused['internal_error'] ?? 0) + 1;
            }
        }

        if ($queued > 0) {
            TransferWatcher::ensureRunning();
        }

        return ['queued' => $queued, 'refused' => $refused];
    }

    /** 1024-based, like the transfer pages: "812 MB", "4.5 GB", "16 GB". */
    public static function size(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $bytes = max(0, (float) $bytes);

        while ($bytes >= 1000 && $i < 4) {
            $bytes /= 1024;
            $i++;
        }

        $whole = $i === 0 || $bytes >= 100 || abs($bytes - round($bytes)) < 0.05;

        return ($whole ? number_format($bytes) : number_format($bytes, 1)) . ' ' . $units[$i];
    }
}
