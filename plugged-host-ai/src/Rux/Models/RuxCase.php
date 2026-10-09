<?php

namespace PluggedHost\Ai\Rux\Models;

use Illuminate\Database\Eloquent\Model;
use PluggedHost\Ai\Rux\Support\RuxText;
use PluggedHost\Ai\Rux\Support\RuxTime;

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

    /**
     * The case in one line for the model: number, action, when, who, why, and what the member
     * said when rux caught them.
     */
    public function line(bool $withMember = false): string
    {
        $said = $this->meta['said'] ?? null;

        return '#' . $this->id . ' ' . $this->action
            . ($this->automatic ? ($this->rule_id !== null ? " (automatic, rule #{$this->rule_id})" : ' (automatic)') : '')
            . ($this->revoked_at !== null ? ' (cleared)' : '')
            . ' ' . RuxTime::human($this->created_at)
            . ($withMember && $this->target_id !== null ? " on <@{$this->target_id}>" : '')
            . ($withMember && !$this->automatic ? " by <@{$this->moderator_discord_id}>" : '')
            . ($this->channel_id !== null ? " in <#{$this->channel_id}>" : '')
            . ($this->duration_seconds ? ' for ' . RuxTime::humanDuration($this->duration_seconds) : '')
            . (isset($this->meta['deleted']) ? ", {$this->meta['deleted']} deleted" : '')
            . ($this->reason ? ": {$this->reason}" : '')
            . (is_string($said) && $said !== '' ? ' (they said: "' . RuxText::untrusted($said, 200) . '")' : '');
    }

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
