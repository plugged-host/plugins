<?php

namespace PluggedHost\Ai\Rux\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A giveaway rux runs on Discord (GiveawayService). Active until ends_at, then drawn once;
 * cancelled ones are never drawn.
 *
 * @property int $id
 * @property string $guild_id
 * @property string $channel_id
 * @property ?string $message_id
 * @property string $prize
 * @property int $credits
 * @property int $winners_count
 * @property Carbon $ends_at
 * @property int $exclude_recent
 * @property ?array{giveaways?: list<array{id: int, prize: string, at: ?string}>, discord_ids?: list<string>} $excluded
 * @property string $status
 * @property string $created_by_discord_id
 * @property ?int $created_by_account_id
 * @property ?Carbon $ended_at
 * @property string $payout_status
 * @property int $payout_attempts
 * @property ?string $payout_error
 * @property ?int $noprofit_giveaway_id
 * @property int $rerolls
 * @property int $rendered_entries
 */
class RuxGiveaway extends Model
{
    public const ACTIVE = 'active';

    public const DRAWING = 'drawing';

    public const ENDED = 'ended';

    public const CANCELLED = 'cancelled';

    /** No winners to pay (yet), every draw paid, or a draw still waiting on noprofit.space. */
    public const PAYOUT_NONE = 'none';

    public const PAYOUT_PENDING = 'pending';

    public const PAYOUT_PAID = 'paid';

    protected $table = 'rux_giveaways';

    protected $fillable = [
        'guild_id',
        'channel_id',
        'message_id',
        'prize',
        'credits',
        'winners_count',
        'ends_at',
        'exclude_recent',
        'excluded',
        'status',
        'created_by_discord_id',
        'created_by_account_id',
        'ended_at',
        'payout_status',
        'payout_attempts',
        'payout_error',
        'noprofit_giveaway_id',
        'rerolls',
        'rendered_entries',
    ];

    protected $casts = [
        'credits' => 'integer',
        'winners_count' => 'integer',
        'ends_at' => 'datetime',
        'exclude_recent' => 'integer',
        'excluded' => 'array',
        'created_by_account_id' => 'integer',
        'ended_at' => 'datetime',
        'payout_attempts' => 'integer',
        'noprofit_giveaway_id' => 'integer',
        'rerolls' => 'integer',
        'rendered_entries' => 'integer',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(RuxGiveawayEntry::class, 'giveaway_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::ACTIVE && $this->ends_at->isFuture();
    }

    /**
     * @return list<string>
     */
    public function excludedDiscordIds(): array
    {
        return array_values(array_map('strval', (array) ($this->excluded['discord_ids'] ?? [])));
    }

    public function excludes(string $discordId): bool
    {
        return in_array($discordId, $this->excludedDiscordIds(), true);
    }

    /**
     * The noprofit.space idempotency key of one draw: "rux:12" for the main draw, "rux:12:r1"
     * for the first reroll.
     */
    public function payoutKey(int $draw): string
    {
        return $draw === 0 ? "rux:{$this->id}" : "rux:{$this->id}:r{$draw}";
    }

    public function jumpUrl(): ?string
    {
        return $this->message_id === null
            ? null
            : "https://discord.com/channels/{$this->guild_id}/{$this->channel_id}/{$this->message_id}";
    }
}
