<?php

namespace PluggedHost\Ai\Rux\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Discord user in one giveaway. won_at/draw are set when a draw picks them, payout once
 * noprofit.space answered for that draw (paid, or pending until they link again).
 *
 * @property int $id
 * @property int $giveaway_id
 * @property string $discord_id
 * @property ?string $username
 * @property ?Carbon $won_at
 * @property ?int $draw
 * @property ?string $payout
 */
class RuxGiveawayEntry extends Model
{
    protected $table = 'rux_giveaway_entries';

    protected $fillable = [
        'giveaway_id',
        'discord_id',
        'username',
        'won_at',
        'draw',
        'payout',
    ];

    protected $casts = [
        'won_at' => 'datetime',
        'draw' => 'integer',
    ];

    public function giveaway(): BelongsTo
    {
        return $this->belongsTo(RuxGiveaway::class, 'giveaway_id');
    }
}
