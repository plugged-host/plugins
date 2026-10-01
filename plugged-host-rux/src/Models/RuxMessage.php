<?php

namespace PluggedHost\Rux\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A row of rux's short memory. A `user` row with status `pending` is also an order waiting for
 * its worker (plugged-host:rux-handle <id>); the rows of one turn share its turn_id.
 *
 * @property int $id
 * @property string $conversation
 * @property ?int $turn_id
 * @property ?string $discord_message_id
 * @property string $channel_id
 * @property ?string $guild_id
 * @property string $author_id
 * @property ?string $author_name
 * @property string $role
 * @property ?string $content
 * @property ?list<array<string, mixed>> $tool_calls
 * @property ?string $tool_call_id
 * @property ?string $tool_name
 * @property string $status
 * @property ?array<string, mixed> $meta
 * @property \Carbon\Carbon $created_at
 */
class RuxMessage extends Model
{
    public const USER = 'user';

    public const ASSISTANT = 'assistant';

    public const TOOL = 'tool';

    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    /** Not an admin, rate limited, or empty: never shown to the model. */
    public const IGNORED = 'ignored';

    protected $table = 'rux_messages';

    protected $fillable = [
        'conversation',
        'turn_id',
        'discord_message_id',
        'channel_id',
        'guild_id',
        'author_id',
        'author_name',
        'role',
        'content',
        'tool_calls',
        'tool_call_id',
        'tool_name',
        'status',
        'meta',
    ];

    protected $casts = [
        'turn_id' => 'integer',
        'tool_calls' => 'array',
        'meta' => 'array',
    ];

    public static function conversationKey(string $channelId, string $authorId): string
    {
        return "{$channelId}:{$authorId}";
    }

    public function isDirect(): bool
    {
        return $this->guild_id === null;
    }
}
