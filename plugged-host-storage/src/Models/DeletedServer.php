<?php

namespace PluggedHost\Storage\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A deleted server whose off-site copy is (or was) still on the storage box. Written when the
 * server is deleted, or when the agent reports a copy the panel has no record of; the agent
 * removes the copy once `keep_until` has passed (see DeletedServers).
 */
class DeletedServer extends Model
{
    protected $table = 'storage_deleted_servers';

    protected $fillable = [
        'uuid', 'server_id', 'owner_id', 'name', 'egg', 'node', 'deleted_by', 'reason',
        'deleted_at', 'keep_until', 'purged_at',
    ];

    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
            'keep_until' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    /** Copies still kept: not removed, hold not over. */
    public function scopeHeld(Builder $query): void
    {
        $query->whereNull('purged_at')->where('keep_until', '>', now());
    }

    public function uuidShort(): string
    {
        return substr($this->uuid, 0, 8);
    }
}
