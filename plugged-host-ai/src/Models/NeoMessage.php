<?php

namespace PluggedHost\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $conversation_id
 * @property int $seq
 * @property string $role
 * @property ?string $content
 * @property ?array $tool_calls
 * @property ?string $tool_call_id
 * @property ?string $tool_name
 * @property ?string $status
 * @property ?array $meta
 */
class NeoMessage extends Model
{
    public const USER = 'user';

    public const ASSISTANT = 'assistant';

    public const TOOL = 'tool';

    public const NOTICE = 'notice';

    // Tool-row states.
    public const QUEUED = 'queued';

    public const PENDING_APPROVAL = 'pending_approval';

    public const APPROVED = 'approved';

    public const DENIED = 'denied';

    public const EXECUTING = 'executing';

    public const DONE = 'done';

    public const FAILED = 'error';

    public const SKIPPED = 'skipped';

    protected $table = 'neo_messages';

    protected $fillable = [
        'conversation_id',
        'seq',
        'role',
        'content',
        'tool_calls',
        'tool_call_id',
        'tool_name',
        'status',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'tool_calls' => 'array',
            'meta' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(NeoConversation::class, 'conversation_id');
    }

    /**
     * The decoded arguments of a tool row (kept in meta.arguments).
     *
     * @return array<string, mixed>
     */
    public function arguments(): array
    {
        return is_array($this->meta['arguments'] ?? null) ? $this->meta['arguments'] : [];
    }
}
