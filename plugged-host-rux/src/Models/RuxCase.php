<?php

namespace PluggedHost\Rux\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One moderation action rux took on someone's order (ModerationService).
 *
 * @property int $id
 * @property string $guild_id
 * @property string $action
 * @property ?string $target_id
 * @property ?string $target_name
 * @property ?string $channel_id
 * @property string $moderator_discord_id
 * @property ?int $moderator_account_id
 * @property ?string $reason
 * @property ?int $duration_seconds
 * @property bool $automatic
 * @property ?int $rule_id
 * @property ?\Carbon\Carbon $expires_at
 * @property ?\Carbon\Carbon $resolved_at
 * @property ?\Carbon\Carbon $revoked_at
 * @property ?array<string, mixed> $meta
 * @property \Carbon\Carbon $created_at
 */
class RuxCase extends Model
{
    protected $table = 'rux_cases';

    protected $fillable = [
        'guild_id',
        'action',
        'target_id',
        'target_name',
        'channel_id',
        'moderator_discord_id',
        'moderator_account_id',
        'reason',
        'duration_seconds',
        'automatic',
        'rule_id',
        'expires_at',
        'resolved_at',
        'revoked_at',
        'meta',
    ];

    protected $casts = [
        'moderator_account_id' => 'integer',
        'duration_seconds' => 'integer',
        'automatic' => 'boolean',
        'rule_id' => 'integer',
        'expires_at' => 'datetime',
        'resolved_at' => 'datetime',
        'revoked_at' => 'datetime',
        'meta' => 'array',
    ];
}
