<?php

namespace PluggedHost\Discord\Models;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One giveaway payout: a prize, a currency, an amount per winner, and the winners.
 *
 * Written only by GiveawayRewardService. There is no edit path on purpose — changing the
 * amount after winners have been paid would leave this record disagreeing with the
 * ledger rows it produced.
 *
 * @property int $id
 * @property string $prize
 * @property string $currency
 * @property int $amount
 * @property ?int $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read ?User $creator
 * @property-read \Illuminate\Database\Eloquent\Collection<int, DiscordGiveawayRecipient> $recipients
 */
class DiscordGiveaway extends Model
{
    public const CURRENCY_CREDITS = 'credits';

    public const CURRENCY_TOKENS = 'tokens';

    protected $table = 'discord_giveaways';

    protected $fillable = [
        'prize',
        'currency',
        'amount',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'integer',
        'created_by' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(DiscordGiveawayRecipient::class, 'giveaway_id');
    }
}
