<?php

namespace PluggedHost\Ai\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A row of neo on Discord's short memory. A `user` row with status `pending` is also a message
 * waiting for its worker (plugged-host:neo-bot-handle); the rows of one answer share its
 * turn_id, which is the id of the first message it answers.
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
class NeoBotMessage extends Model
{
    public const USER = 'user';

    public const ASSISTANT = 'assistant';

    public const TOOL = 'tool';

    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const DONE = 'done';

    /** Answered with a fixed text (a limit, an outage): never replayed to the model. */
    public const FAILED = 'failed';

    protected $table = 'neo_bot_messages';

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
