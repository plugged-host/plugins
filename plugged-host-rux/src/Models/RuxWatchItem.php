<?php

namespace PluggedHost\Rux\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A message from a watched channel, waiting for its check.
 *
 * @property int $id
 * @property string $message_id
 * @property string $channel_id
 * @property string $author_id
 * @property ?string $author_name
 * @property ?list<string> $roles
 * @property string $content
 * @property \Carbon\Carbon $created_at
 */
class RuxWatchItem extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'rux_watch_queue';

    protected $fillable = ['message_id', 'channel_id', 'author_id', 'author_name', 'roles', 'content'];

    protected $casts = ['roles' => 'array'];
}
