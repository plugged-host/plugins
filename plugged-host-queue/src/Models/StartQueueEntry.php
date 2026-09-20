<?php

namespace PluggedHost\Queue\Models;

use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One server's place in the line for its node.
 *
 * A row per server, reused for every start that has to wait, so "where am I in the
 * queue" is a count over one index and never a join. The row outlives the wait — it
 * is left behind as `released` rather than deleted — because deleting it is what a
 * second Start press would race against.
 *
 * @property int $id
 * @property int $user_id
 * @property int $server_id
 * @property int $node_id
 * @property string $action
 * @property string $status
 * @property bool $priority
 * @property Carbon $queued_at
 * @property ?Carbon $last_seen_at
 * @property ?Carbon $released_at
 * @property ?array $metadata
 * @property-read User $user
 * @property-read Server $server
 * @property-read Node $node
 */
class StartQueueEntry extends Model
{
    /** Standing in line. The only status the ordering ever looks at. */
    public const STATUS_WAITING = 'waiting';

    /** Let through; the power action has been dispatched. */
    public const STATUS_RELEASED = 'released';

    /** The user left the queue themselves — took the transfer, or gave up. */
    public const STATUS_CANCELLED = 'cancelled';

    /** Nobody was standing in it: no heartbeat for `stale_after` seconds. */
    public const STATUS_EXPIRED = 'expired';

    protected $table = 'start_queue_entries';

    protected $fillable = [
        'user_id',
        'server_id',
        'node_id',
        'action',
        'status',
        'priority',
        'queued_at',
        'last_seen_at',
        'released_at',
        'metadata',
    ];

    protected $casts = [
        'priority' => 'boolean',
        'queued_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'released_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function isWaiting(): bool
    {
        return $this->status === self::STATUS_WAITING;
    }

    /**
     * @param  Builder<StartQueueEntry>  $query
     * @return Builder<StartQueueEntry>
     */
    public function scopeWaiting(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_WAITING);
    }

    /**
     * The line, in the order it is served.
     *
     * Priority first, then strictly the order people arrived, with the id as the
     * tie-break for two entries stamped inside the same second. So a pass bought
     * while already waiting moves you ahead of everyone without one and behind every
     * pass holder who was already there — never ahead of somebody who also paid and
     * got here first.
     *
     * Matches `start_queue_entries_line_order` column for column; change one and
     * change the other.
     *
     * @param  Builder<StartQueueEntry>  $query
     * @return Builder<StartQueueEntry>
     */
    public function scopeInLineOrder(Builder $query): Builder
    {
        return $query
            ->orderByDesc('priority')
            ->orderBy('queued_at')
            ->orderBy('id');
    }

    /**
     * How many entries on this node are served before this one.
     *
     * Written as the exact negation of `scopeInLineOrder`, which is why the
     * `queued_at` comparison carries the id tie-break inside it rather than beside
     * it: two rows in the same second must be counted by id, and only then.
     */
    public function precedingCount(): int
    {
        return (int) static::query()
            ->waiting()
            ->where('node_id', $this->node_id)
            ->whereKeyNot($this->getKey())
            ->where(function (Builder $query): void {
                if (!$this->priority) {
                    $query->where('priority', true);
                }

                $query->orWhere(function (Builder $inner): void {
                    $inner->where('priority', $this->priority)
                        ->where(function (Builder $earlier): void {
                            $earlier->where('queued_at', '<', $this->queued_at)
                                ->orWhere(function (Builder $sameSecond): void {
                                    $sameSecond->where('queued_at', $this->queued_at)
                                        ->where('id', '<', $this->getKey());
                                });
                        });
                });
            })
            ->count();
    }
}
