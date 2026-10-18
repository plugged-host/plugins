<?php

namespace PluggedHost\Limbo\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per server that limbo has touched. Records where it came from (origin node +
 * allocations) so restore can return it to the same location tag, where it was parked
 * (limbo_node_id, migration 021: the limbo node can change while servers stay parked on the old
 * one), and when it entered each state so the ransom window can be computed.
 */
class ServerLimboState extends Model
{
    protected $table = 'server_limbo_states';

    protected $fillable = [
        'server_id', 'state', 'idle_since', 'stopped_at', 'limbo_at',
        'origin_node_id', 'origin_allocation_id', 'origin_allocations', 'limbo_node_id',
        'restore_cost', 'restored_at',
    ];

    protected function casts(): array
    {
        return [
            'idle_since' => 'datetime',
            'stopped_at' => 'datetime',
            'limbo_at' => 'datetime',
            'restored_at' => 'datetime',
            'origin_allocations' => 'array',
            'limbo_node_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
