<?php

namespace PluggedHost\Ai\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $user_id
 * @property bool $enabled
 * @property bool $auto_approve
 * @property ?\Illuminate\Support\Carbon $acknowledged_at
 */
class NeoUserSetting extends Model
{
    protected $table = 'neo_user_settings';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'enabled',
        'auto_approve',
        'acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'auto_approve' => 'boolean',
            'acknowledged_at' => 'datetime',
        ];
    }
}
