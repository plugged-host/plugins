<?php

namespace PluggedHost\Ai\Rux\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PluggedHost\Ai\Rux\Support\RuxConfig;

/**
 * A channel where rux checks every member message: an id, or a name it resolves among the
 * channels of its server.
 *
 * @property int $id
 * @property ?string $guild_id null: the primary server's (from before rux knew several)
 * @property string $channel
 * @property ?string $added_by_discord_id
 */
class RuxWatch extends Model
{
    protected $table = 'rux_watches';

    protected $fillable = ['guild_id', 'channel', 'added_by_discord_id'];

    /**
     * The rows of one server (a row without a guild_id is the primary server's).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForGuild(Builder $query, string $guildId): Builder
    {
        return $query->where(fn (Builder $q) => $guildId === RuxConfig::guildId()
            ? $q->where('guild_id', $guildId)->orWhereNull('guild_id')
            : $q->where('guild_id', $guildId));
    }
}
