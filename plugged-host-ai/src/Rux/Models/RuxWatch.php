<?php

namespace PluggedHost\Ai\Rux\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A channel where rux checks every member message: an id, or a name it resolves.
 *
 * @property int $id
 * @property string $channel
 * @property ?string $added_by_discord_id
 */
class RuxWatch extends Model
{
    protected $table = 'rux_watches';

    protected $fillable = ['channel', 'added_by_discord_id'];
}
