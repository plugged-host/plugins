<?php

namespace PluggedHost\Storage\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The audit + state row for one server's recovery off a dead node. `state` includes `fenced`:
 * a fenced server is one that has been recovered elsewhere, and the sweep + the panel refuse to
 * start the old copy until an operator clears it — the single most important guard against two
 * live copies of the same server.
 */
class NodeFailover extends Model
{
    protected $table = 'node_failovers';

    protected $fillable = [
        'node_id', 'server_id', 'from_node_id', 'to_node_id', 'state', 'started_at', 'finished_at', 'detail',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'detail' => 'array',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
