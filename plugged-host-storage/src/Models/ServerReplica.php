<?php

namespace PluggedHost\Storage\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per replicated server: where its mirror lives on the storage box, how fresh it is,
 * and how big. Written by the agent's signed report, read by the panel's replica API and by
 * failover to know a mirror exists before recovering from it.
 */
class ServerReplica extends Model
{
    protected $table = 'server_replicas';

    protected $fillable = [
        'server_id', 'last_replicated_at', 'bytes', 'mirror_path', 'checksum', 'state', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'last_replicated_at' => 'datetime',
            'bytes' => 'integer',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
