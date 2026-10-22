<?php

namespace PluggedHost\Ai\Rux\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A message from a watched channel, waiting for its check.
 *
 * @property int $id
 * @property ?string $guild_id null: the primary server's (queued before rux knew several)
 * @property string $message_id
 * @property string $channel_id
 * @property string $author_id
 * @property ?string $author_name
 * @property ?list<string> $roles
 * @property string $content
 * @property ?string $replied_to "name: text" of the message it replies to
 * @property \Carbon\Carbon $created_at
 */
class RuxWatchItem extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'rux_watch_queue';

    protected $fillable = ['guild_id', 'message_id', 'channel_id', 'author_id', 'author_name', 'roles', 'content', 'replied_to'];

    protected $casts = ['roles' => 'array'];
}
