<?php

namespace PluggedHost\Servers\Models;

use App\Models\Node;
use App\Models\Server;
use App\Models\ServerTransfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * One move of a server to another node, from the click to the server running again.
 *
 * `state` is owned by TransferService (the state machine); `phase`, the byte counters,
 * `rate`, `progress_at` and `socket_at` are written by the watcher from what the source
 * node says over its websocket while the copy runs. The two never write the same column,
 * so neither can clobber the other.
 *
 * @property int $id
 * @property int $server_id
 * @property int|null $user_id
 * @property string $origin
 * @property string|null $location
 * @property int $from_node_id
 * @property int|null $to_node_id
 * @property int|null $transfer_id
 * @property string $state
 * @property string|null $phase
 * @property int $attempt
 * @property int $max_attempts
 * @property bool $was_running
 * @property bool $start_after
 * @property int $bytes_total
 * @property int $bytes_done
 * @property int $backups_total
 * @property int $backups_done
 * @property int $backup_bytes
 * @property int $rate
 * @property array<string, mixed>|null $detail
 * @property string|null $error
 * @property Carbon|null $state_at
 * @property Carbon|null $progress_at
 * @property Carbon|null $socket_at
 * @property Carbon|null $next_step_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Server|null $server
 * @property-read User|null $user
 * @property-read Node|null $fromNode
 * @property-read Node|null $toNode
 * @property-read ServerTransfer|null $transfer
 */
class ServerRelocation extends Model
{
    /** Destination picked and reserved; about to stop the server or hand over to wings. */
    public const PREPARING = 'preparing';

    /** Waiting for the server to shut down cleanly (killed after stop_timeout). */
    public const STOPPING = 'stopping';

    /** Wings is streaming the data (and backups) to the new node. */
    public const COPYING = 'copying';

    /** Every byte is sent; the new node is verifying and building the container. */
    public const FINALIZING = 'finalizing';

    /** The panel recorded the move; fixing up ports and DNS and starting the server. */
    public const STARTING = 'starting';

    /** An attempt failed; the next one (usually another node) starts at next_step_at. */
    public const RETRYING = 'retrying';

    /**
     * The move gave up or was cancelled and the server is being started again on its old
     * node; detail.final_state is what it becomes once it is back.
     */
    public const ROLLBACK = 'rollback';

    public const COMPLETED = 'completed';

    /** Gave up. The server is where it was, with its data, back in its old power state. */
    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /** Could not be resolved safely on its own; an admin has been told. Nothing was deleted. */
    public const STUCK = 'stuck';

    public const ACTIVE = [self::PREPARING, self::STOPPING, self::COPYING, self::FINALIZING, self::STARTING, self::RETRYING, self::ROLLBACK];

    public const PHASE_ARCHIVE = 'archive';

    public const PHASE_BACKUPS = 'backups';

    /** The source said it has streamed everything; the destination has not answered yet. */
    public const PHASE_HANDOFF = 'handoff';

    /** Both nodes were asked to abort; waiting for wings to report the failure. */
    public const PHASE_CANCELLING = 'cancelling';

    public const ORIGIN_USER = 'user';

    public const ORIGIN_QUEUE = 'queue';

    /** Started by an admin from the admin server page: may target a node, skips the user gates. */
    public const ORIGIN_ADMIN = 'admin';

    /** A transfer started outside this service (admin panel, limbo) that the watcher took over. */
    public const ORIGIN_EXTERNAL = 'external';

    /** Timeline entries kept per move. */
    private const MAX_EVENTS = 40;

    protected $table = 'server_relocations';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'server_id' => 'int',
            'user_id' => 'int',
            'from_node_id' => 'int',
            'to_node_id' => 'int',
            'transfer_id' => 'int',
            'attempt' => 'int',
            'max_attempts' => 'int',
            'was_running' => 'bool',
            'start_after' => 'bool',
            'bytes_total' => 'int',
            'bytes_done' => 'int',
            'backups_total' => 'int',
            'backups_done' => 'int',
            'backup_bytes' => 'int',
            'rate' => 'int',
            'detail' => 'array',
            'state_at' => 'datetime',
            'progress_at' => 'datetime',
            'socket_at' => 'datetime',
            'next_step_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fromNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'from_node_id');
    }

    public function toNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'to_node_id');
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(ServerTransfer::class, 'transfer_id');
    }

    private static ?bool $ready = null;

    /**
     * Has the migration run? Checked once per process, so a panel that has the plugin code
     * before its table (or a migration that failed) hides transfers instead of erroring.
     */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = Schema::hasTable('server_relocations');
            } catch (Throwable) {
                return false;
            }
        }

        return self::$ready;
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('state', self::ACTIVE);
    }

    public function isActive(): bool
    {
        return in_array($this->state, self::ACTIVE, true);
    }

    /** The newest move of this server that has not finished yet. */
    public static function activeFor(Server|int $server): ?self
    {
        if (!self::ready()) {
            return null;
        }

        return self::query()
            ->where('server_id', $server instanceof Server ? $server->getKey() : $server)
            ->active()
            ->latest('id')
            ->first();
    }

    public function isCancellable(): bool
    {
        return in_array($this->state, [self::PREPARING, self::STOPPING, self::RETRYING], true)
            || ($this->state === self::COPYING && $this->phase !== self::PHASE_CANCELLING);
    }

    /** Which strings.transfers.state.* line describes where this move is right now. */
    public function headlineKey(): string
    {
        return match (true) {
            $this->phase === self::PHASE_CANCELLING => $this->info('abort_reason') === 'user' ? 'cancelling' : 'checking',
            $this->state === self::COPYING && $this->phase === self::PHASE_BACKUPS => 'copying_backups',
            $this->state === self::COPYING && $this->phase === self::PHASE_HANDOFF => 'finalizing',
            default => $this->state,
        };
    }

    public function info(string $key, mixed $default = null): mixed
    {
        return data_get($this->detail ?? [], $key, $default);
    }

    public function remember(string $key, mixed $value): void
    {
        $detail = $this->detail ?? [];
        data_set($detail, $key, $value);
        $this->detail = $detail;
    }

    /**
     * Add a line to the timeline the page shows. Keys are translated there
     * (`strings.transfers.events.<key>`), so the stored entry stays language-neutral.
     *
     * @param  array<string, scalar|null>  $params
     */
    public function event(string $key, array $params = [], string $level = 'info'): void
    {
        $events = $this->info('events', []);
        $events[] = ['at' => now()->toIso8601String(), 'key' => $key, 'params' => $params, 'level' => $level];

        $this->remember('events', array_slice($events, -self::MAX_EVENTS));
    }

    public function moveState(string $state): void
    {
        $this->state = $state;
        $this->state_at = now();
    }

    /**
     * Bytes of backups known to have arrived: the completed ones exactly (wings streams them
     * in file-name order, which is the order kept in detail.backups), the one in flight
     * estimated from the time it has been moving at the measured rate, capped below its size.
     */
    public function backupBytesDone(): int
    {
        $list = (array) $this->info('backups', []);
        $done = 0;

        foreach (array_slice($list, 0, $this->backups_done) as $backup) {
            $done += (int) ($backup['bytes'] ?? 0);
        }

        $current = $list[$this->backups_done] ?? null;

        if ($this->phase === self::PHASE_BACKUPS && $current !== null && $this->progress_at !== null) {
            $elapsed = max(0, now()->getTimestamp() - $this->progress_at->getTimestamp());
            $done += (int) min((int) ($current['bytes'] ?? 0) * 0.95, $elapsed * max($this->rate, 1));
        }

        return $done;
    }

    /** 0..1 of the data copy (server files + backups), or null before wings reported any size. */
    public function copyFraction(): ?float
    {
        $archiveTotal = max($this->bytes_total, 0);
        $total = $archiveTotal + $this->backup_bytes;

        if ($total <= 0) {
            return null;
        }

        $archive = in_array($this->phase, [self::PHASE_BACKUPS, self::PHASE_HANDOFF], true)
            ? $archiveTotal
            : min($this->bytes_done, $archiveTotal);

        $backups = $this->phase === self::PHASE_HANDOFF ? $this->backup_bytes : $this->backupBytesDone();

        return max(0.0, min(1.0, ($archive + $backups) / $total));
    }

    /**
     * One number for the bar: stopping takes the first 6%, the copy up to 90, verifying on
     * the new node up to 96, and starting the rest.
     */
    public function percent(): int
    {
        return match ($this->state) {
            self::PREPARING, self::RETRYING => 2,
            self::STOPPING => 4,
            self::COPYING => (int) floor(6 + 84 * ($this->copyFraction() ?? 0.0)),
            self::FINALIZING => 92,
            self::STARTING => 97,
            self::COMPLETED => 100,
            default => (int) floor(6 + 84 * ($this->copyFraction() ?? 0.0)),
        };
    }

    /** Seconds left in the copy at the measured rate, or null when there is nothing to go on. */
    public function etaSeconds(): ?int
    {
        if ($this->state !== self::COPYING || $this->rate <= 0) {
            return null;
        }

        $fraction = $this->copyFraction();

        if ($fraction === null) {
            return null;
        }

        $remaining = (1 - $fraction) * ($this->bytes_total + $this->backup_bytes);

        return (int) ceil($remaining / $this->rate);
    }
}
