<?php

namespace PluggedHost\Ai\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only neo usage ledger when this panel meters neo itself (noprofit.space's
 * ai_usage_events): token counts and routing only, never what was said.
 *
 * @property int $id
 * @property ?int $user_id
 * @property ?string $discord_id
 * @property string $service_key
 * @property string $status ok, failed or test
 * @property int $charged_tokens
 */
class NeoUsageEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'neo_usage_events';

    protected $fillable = [
        'user_id',
        'discord_id',
        'service_key',
        'conversation',
        'request_id',
        'provider_slot',
        'provider_label',
        'driver',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'charged_tokens',
        'finish_reason',
        'status',
        'attempts',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'provider_slot' => 'integer',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'charged_tokens' => 'integer',
            'attempts' => 'array',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
