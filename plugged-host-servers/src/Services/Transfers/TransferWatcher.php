<?php

namespace PluggedHost\Servers\Services\Transfers;

use App\Enums\NodeJwtScope;
use App\Models\Node;
use App\Models\Server;
use App\Models\ServerTransfer;
use App\Services\Nodes\NodeJWTService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use PluggedHost\Servers\Models\ServerRelocation;
use Symfony\Component\Process\PhpExecutableFinder;
use Throwable;

/**
 * The one process that drives moves: every second it steps each active relocation
 * (TransferService::advance) and reads the source node's websocket for every copy in
 * flight, turning wings' "Uploading … x / y" lines into the numbers the page shows.
 *
 * A background CLI process rather than a queued job, because it holds sockets open for as
 * long as a copy runs and the panel has a single queue worker that must not be tied up
 * for the length of a transfer. Exactly one runs at a time, enforced with flock() on a
 * file (not a cache lock, which `optimize:clear` would flush). It is started by the first
 * move (ensureRunning) and by the scheduler every minute while there is work, lives at
 * most ten minutes (so new code is picked up), and hands over to a successor on the way
 * out when moves are still running.
 */
class TransferWatcher
{
    public const COMMAND = 'plugged-host-servers:transfers';

    /** Progress rows are written at most this often per move (seconds). */
    private const WRITE_EVERY = 2.0;

    /**
     * @var array<int, array<string, mixed>> per relocation: socket, transfer id, the
     *                                       models for re-auth, rate samples, pending writes
     */
    private array $watch = [];

    public function __construct(private readonly TransferService $service) {}

    public static function lockPath(): string
    {
        return storage_path('framework/plugged-host-transfers.lock');
    }

    /** Is there anything for a watcher to do? Cheap; the scheduler asks every minute. */
    public static function hasWork(): bool
    {
        try {
            if (!ServerRelocation::ready()) {
                return false;
            }

            return ServerRelocation::query()->active()->exists()
                || ServerTransfer::query()->whereNull('successful')->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /** Start a watcher in the background unless one is already running. */
    public static function ensureRunning(): void
    {
        try {
            $handle = @fopen(self::lockPath(), 'c');

            if ($handle === false) {
                return;
            }

            $free = flock($handle, LOCK_EX | LOCK_NB);

            if ($free) {
                flock($handle, LOCK_UN);
            }

            fclose($handle);

            if (!$free) {
                return;
            }

            // PhpExecutableFinder returns the CLI binary even under php-fpm. setsid, where
            // there is one, so the watcher is not tied to the session of whoever started it.
            $php = (new PhpExecutableFinder())->find(false) ?: 'php';
            $setsid = is_executable('/usr/bin/setsid') ? '/usr/bin/setsid ' : '';

            exec(sprintf(
                '%s%s %s %s < /dev/null > /dev/null 2>&1 &',
                $setsid,
                escapeshellarg($php),
                escapeshellarg(base_path('artisan')),
                self::COMMAND,
            ));
        } catch (Throwable $e) {
            Log::warning('[plugged-host-servers] could not start the transfer watcher', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return int 0 when it ran (or another watcher is running), 1 when the lock is unusable
     */
    public function run(int $maxSeconds = 600, int $idleSeconds = 20): int
    {
        $handle = @fopen(self::lockPath(), 'c');

        if ($handle === false) {
            Log::error('[plugged-host-servers] transfer watcher lock file is not writable: ' . self::lockPath());

            return 1;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return 0;
        }

        $started = microtime(true);
        $idleSince = null;
        $lastAdopt = 0.0;
        $busy = false;

        try {
            while (microtime(true) - $started < $maxSeconds) {
                $tick = microtime(true);

                if ($tick - $lastAdopt >= 30) {
                    $lastAdopt = $tick;

                    try {
                        $this->service->adoptExternal();
                        $this->service->reviveStuck();
                    } catch (Throwable $e) {
                        report($e);
                    }
                }

                // One bad tick (a dropped DB connection, a node that answers garbage) must not
                // end the process: the scheduler would only bring it back a minute later.
                try {
                    $active = ServerRelocation::query()->active()->get();
                    $busy = $active->isNotEmpty();

                    if (!$busy) {
                        $idleSince ??= $tick;

                        if ($tick - $idleSince >= $idleSeconds) {
                            break;
                        }
                    } else {
                        $idleSince = null;
                    }

                    foreach ($active as $relocation) {
                        $this->service->advance($relocation);
                    }

                    $this->syncSockets(ServerRelocation::query()
                        ->whereIn('state', [ServerRelocation::COPYING, ServerRelocation::FINALIZING])
                        ->whereNotNull('transfer_id')
                        ->get());

                    $this->pump(max(0.2, 1.0 - (microtime(true) - $tick)));
                } catch (Throwable $e) {
                    report($e);
                    usleep(1_000_000);
                }
            }
        } finally {
            foreach ($this->watch as $watch) {
                $watch['socket']?->close();
            }

            $this->watch = [];

            flock($handle, LOCK_UN);
            fclose($handle);
        }

        // Out of time with moves still running: hand over now instead of leaving a gap
        // until the scheduler's next minute.
        if ($busy && microtime(true) - $started >= $maxSeconds) {
            self::ensureRunning();
        }

        return 0;
    }

    /** @param Collection<int, ServerRelocation> $relocations */
    private function syncSockets(Collection $relocations): void
    {
        $keep = [];

        foreach ($relocations as $relocation) {
            $id = (int) $relocation->getKey();
            $keep[$id] = true;

            $watch = $this->watch[$id] ?? null;

            // A new attempt is a new transfer, possibly from a fresh socket session.
            if ($watch !== null && $watch['transfer_id'] !== (int) $relocation->transfer_id) {
                $watch['socket']?->close();
                $watch = null;
            }

            $watch ??= [
                'transfer_id' => (int) $relocation->transfer_id,
                'socket' => null,
                'retry_at' => 0.0,
                'authed' => false,
                'alive_at' => 0.0,
                'samples' => [],
                'last_done' => -1,
                'phase' => $relocation->phase,
                'pending' => [],
                'last_write' => 0.0,
                'node' => null,
                'server' => null,
            ];

            if (($watch['socket'] === null || !$watch['socket']->isOpen()) && microtime(true) >= $watch['retry_at']) {
                $watch = $this->connect($relocation, $watch);
            }

            $this->watch[$id] = $watch;
        }

        foreach (array_diff_key($this->watch, $keep) as $id => $watch) {
            $watch['socket']?->close();
            unset($this->watch[$id]);
        }
    }

    /**
     * @param  array<string, mixed>  $watch
     * @return array<string, mixed>
     */
    private function connect(ServerRelocation $relocation, array $watch): array
    {
        $transfer = ServerTransfer::query()->find($relocation->transfer_id);
        $server = Server::query()->with('user')->find($relocation->server_id);
        $node = $transfer?->oldNode;

        if (!$node instanceof Node || !$server instanceof Server || $server->user === null) {
            $watch['retry_at'] = microtime(true) + 15;

            return $watch;
        }

        try {
            $url = str_replace(['https://', 'http://'], ['wss://', 'ws://'], $node->getConnectionAddress()) . "/api/servers/{$server->uuid}/ws";
            $socket = WingsSocket::open($url, rtrim((string) config('app.url'), '/'), app()->environment('production'));
            $socket->sendJson(['event' => 'auth', 'args' => [$this->token($node, $server)]]);

            $watch['socket'] = $socket;
            $watch['authed'] = false;
            $watch['node'] = $node;
            $watch['server'] = $server;
        } catch (Throwable $e) {
            $watch['socket'] = null;
            // Back off; a node that refuses the socket is judged by the size budget instead.
            $watch['retry_at'] = microtime(true) + 10;
            $watch['error'] = $e->getMessage();
        }

        return $watch;
    }

    /**
     * A websocket token for the source node that may read transfer logs. Bound to the owner
     * because wings rejects tokens without a user; it carries no power or console rights.
     */
    private function token(Node $node, Server $server): string
    {
        return app(NodeJWTService::class)
            ->setExpiresAt(CarbonImmutable::now()->addMinutes(15))
            ->setScopes(NodeJwtScope::Websocket)
            ->setUser($server->user)
            ->setClaims([
                'server_uuid' => $server->uuid,
                'permissions' => ['websocket.connect', 'admin.websocket.transfer'],
            ])
            ->handle($node, 'plugged-host-transfers' . $server->uuid)
            ->toString();
    }

    private function pump(float $timeout): void
    {
        $streams = [];

        foreach ($this->watch as $watch) {
            if ($watch['socket']?->isOpen()) {
                $streams[] = $watch['socket']->stream();
            }
        }

        if ($streams !== []) {
            $write = $except = null;
            @stream_select($streams, $write, $except, 0, (int) ($timeout * 1_000_000));
        } else {
            usleep((int) ($timeout * 1_000_000));
        }

        foreach (array_keys($this->watch) as $id) {
            $watch = $this->watch[$id];

            if ($watch['socket'] !== null) {
                foreach ($watch['socket']->read() as $message) {
                    $watch = $this->handle($watch, $message);
                }

                if ($watch['socket'] !== null && !$watch['socket']->isOpen()) {
                    $watch['socket'] = null;
                    $watch['retry_at'] = microtime(true) + 3;
                }

                // Connected and authenticated counts as watching even while wings is quiet.
                if ($watch['authed'] && $watch['socket'] !== null && microtime(true) - $watch['alive_at'] >= 10) {
                    $watch['alive_at'] = microtime(true);
                    $watch['pending']['socket_at'] = true;
                }
            }

            $this->watch[$id] = $this->flush($id, $watch);
        }
    }

    /**
     * @param  array<string, mixed>  $watch
     * @return array<string, mixed>
     */
    private function handle(array $watch, string $message): array
    {
        $data = json_decode($message, true);

        if (!is_array($data)) {
            return $watch;
        }

        $args = array_values(array_map(strval(...), (array) ($data['args'] ?? [])));

        switch ((string) ($data['event'] ?? '')) {
            case 'auth success':
                $watch['authed'] = true;
                $watch['alive_at'] = microtime(true);
                $watch['pending']['socket_at'] = true;

                break;

            case 'token expiring':
                try {
                    $watch['socket']?->sendJson(['event' => 'auth', 'args' => [$this->token($watch['node'], $watch['server'])]]);
                } catch (Throwable) {
                    $watch['socket']?->close();
                }

                break;

            case 'token expired':
            case 'jwt error':
                $watch['socket']?->close();
                $watch['socket'] = null;
                $watch['authed'] = false;
                $watch['retry_at'] = microtime(true) + 2;

                break;

            case 'transfer logs':
                foreach ($args as $line) {
                    $watch = $this->onLog($watch, $line);
                }

                $watch['pending']['socket_at'] = true;

                break;

            default:
                break;
        }

        return $watch;
    }

    /**
     * @param  array<string, mixed>  $watch
     * @return array<string, mixed>
     */
    private function onLog(array $watch, string $line): array
    {
        $log = TransferLog::parse($line);
        $now = microtime(true);

        switch ($log['type']) {
            case TransferLog::UPLOAD:
                $done = (int) $log['done'];

                // wings measures the total from a cached directory size that can be stale or 0;
                // never let it drop below what has already been sent.
                if ((int) $log['total'] > 0) {
                    $watch['pending']['bytes_total'] = max((int) $log['total'], $done);
                }

                if ($done > $watch['last_done']) {
                    $watch['last_done'] = $done;
                    $watch['pending']['bytes_done'] = $done;
                    $watch['pending']['progress_at'] = true;
                }

                $watch['samples'][] = [$now, $done];
                $watch['samples'] = array_values(array_filter($watch['samples'], fn (array $s) => $now - $s[0] <= 40));

                $first = $watch['samples'][0];

                if ($now - $first[0] >= 8 && $done > $first[1]) {
                    $watch['pending']['rate'] = (int) (($done - $first[1]) / ($now - $first[0]));
                }

                if (!in_array($watch['phase'], [ServerRelocation::PHASE_BACKUPS, ServerRelocation::PHASE_HANDOFF], true)) {
                    $watch = $this->phase($watch, ServerRelocation::PHASE_ARCHIVE);
                }

                break;

            case TransferLog::BACKUPS_START:
                $watch['pending']['backups_total'] = (int) $log['count'];
                $watch['pending']['progress_at'] = true;
                $watch = $this->phase($watch, ServerRelocation::PHASE_BACKUPS);

                break;

            case TransferLog::BACKUP_DONE:
                $watch['pending']['backups_done'] = (int) $log['index'];
                $watch['pending']['progress_at'] = true;
                $watch = $this->phase($watch, ServerRelocation::PHASE_BACKUPS);

                break;

            case TransferLog::STREAMED:
                $watch['pending']['progress_at'] = true;
                $watch = $this->phase($watch, ServerRelocation::PHASE_HANDOFF);

                break;

            default:
                break;
        }

        return $watch;
    }

    /**
     * @param  array<string, mixed>  $watch
     * @return array<string, mixed>
     */
    private function phase(array $watch, string $phase): array
    {
        if ($watch['phase'] !== $phase) {
            $watch['phase'] = $phase;
            $watch['pending']['phase'] = $phase;
        }

        return $watch;
    }

    /**
     * Write what the socket said. Every write is pinned to the transfer it was read for, so
     * a late line from a failed attempt can never land on the next one, and the phase is
     * never written over the service's own "cancelling".
     *
     * @param  array<string, mixed>  $watch
     * @return array<string, mixed>
     */
    private function flush(int $id, array $watch): array
    {
        $pending = $watch['pending'];

        if ($pending === []) {
            return $watch;
        }

        $now = microtime(true);

        if (!isset($pending['phase']) && $now - $watch['last_write'] < self::WRITE_EVERY) {
            return $watch;
        }

        $update = array_intersect_key($pending, array_flip(['bytes_total', 'bytes_done', 'backups_total', 'backups_done', 'rate']));

        if (!empty($pending['progress_at'])) {
            $update['progress_at'] = now();
        }

        if (!empty($pending['socket_at'])) {
            $update['socket_at'] = now();
        }

        try {
            if ($update !== []) {
                ServerRelocation::query()->whereKey($id)->where('transfer_id', $watch['transfer_id'])->update($update);
            }

            if (isset($pending['phase'])) {
                ServerRelocation::query()
                    ->whereKey($id)
                    ->where('transfer_id', $watch['transfer_id'])
                    ->where('state', ServerRelocation::COPYING)
                    ->where(fn ($query) => $query->whereNull('phase')->orWhere('phase', '!=', ServerRelocation::PHASE_CANCELLING))
                    ->update(['phase' => $pending['phase']]);
            }
        } catch (Throwable $e) {
            report($e);
        }

        $watch['pending'] = [];
        $watch['last_write'] = $now;

        return $watch;
    }
}
