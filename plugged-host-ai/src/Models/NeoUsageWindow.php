<?php

namespace PluggedHost\Ai\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One anchored neo usage window (short = 5 hours, long = weekly by default) when this panel meters
 * neo itself (Services\NeoUsageMeter): per panel user, or per Discord account nobody linked
 * (neo on Discord; user_id null). The row is reset in place when its window has expired.
 *
 * @property int $id
 * @property ?int $user_id
 * @property ?string $discord_id
 * @property string $kind
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $resets_at
 * @property int $tokens_used
 */
class NeoUsageWindow extends Model
{
    public const CREATED_AT = null;

    protected $table = 'neo_usage_windows';

    protected $fillable = [
        'user_id',
        'discord_id',
        'kind',
        'started_at',
        'resets_at',
        'tokens_used',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'resets_at' => 'immutable_datetime',
            'tokens_used' => 'integer',
        ];
    }
}
