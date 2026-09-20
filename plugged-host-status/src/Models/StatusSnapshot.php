<?php

namespace PluggedHost\Status\Models;

use Illuminate\Database\Eloquent\Model;

class StatusSnapshot extends Model
{
    protected $table = 'status_snapshots';

    protected $fillable = ['component_type', 'component_key', 'status', 'metrics', 'checked_at'];

    protected function casts(): array
    {
        return ['metrics' => 'array', 'checked_at' => 'datetime'];
    }
}
