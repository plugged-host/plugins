<?php

namespace PluggedHost\Status\Models;

use App\Models\Node;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StatusNodeSetting extends Model
{
    protected $table = 'status_node_settings';

    protected $fillable = [
        'node_id', 'public_id', 'display_name', 'enabled', 'sort_order', 'status',
        'failure_count', 'healthy_count', 'resource_pressure_count', 'last_metrics', 'last_reported_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_metrics' => 'array',
            'last_reported_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $setting): void {
            $setting->public_id ??= (string) Str::uuid();
        });
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
