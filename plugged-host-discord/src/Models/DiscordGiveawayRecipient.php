<?php

namespace PluggedHost\Discord\Models;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One winner of a giveaway, and whether they have been paid.
 *
 * `paid` is terminal. Every other status is retried by GiveawayRewardService::pay():
 * `unlinked` because the winner may link their Discord account later, `failed` because
 * the ledger write may succeed next time.
 *
 * @property int $id
 * @property int $giveaway_id
 * @property string $discord_id
 * @property ?int $user_id
 * @property string $status
 * @property ?string $error
 * @property ?Carbon $paid_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read DiscordGiveaway $giveaway
 * @property-read ?User $user
 */
class DiscordGiveawayRecipient extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_UNLINKED = 'unlinked';

    public const STATUS_FAILED = 'failed';

    protected $table = 'discord_giveaway_recipients';

    protected $fillable = [
        'giveaway_id',
        'discord_id',
        'user_id',
        'status',
        'error',
        'paid_at',
    ];

    protected $casts = [
        'giveaway_id' => 'integer',
        'user_id' => 'integer',
        'paid_at' => 'datetime',
    ];

    public function giveaway(): BelongsTo
    {
        return $this->belongsTo(DiscordGiveaway::class, 'giveaway_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
