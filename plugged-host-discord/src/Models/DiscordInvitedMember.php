<?php

namespace PluggedHost\Discord\Models;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Facades\Schema;

/**
 * One tracked Discord join, from escrow through reward to clawback.
 *
 * States, in the order a row moves through them:
 *   pending  — `matured_at` in the future, nothing awarded yet
 *   rewarded — `is_rewarded`, credits paid to the inviter
 *   left     — `has_left`, and if it had been rewarded, `revoked_credits` taken back
 *
 * A rejoin clears `has_left` and increments `rejoin_count` rather than resetting the
 * row, so repeat cycling stays visible instead of silently re-earning credits.
 *
 * @property int $id
 * @property string $inviter_discord_id
 * @property string $joined_discord_id
 * @property ?string $invite_code
 * @property ?Carbon $account_created_at
 * @property ?Carbon $joined_at
 * @property bool $is_eligible
 * @property bool $is_rewarded
 * @property ?Carbon $rewarded_at
 * @property ?Carbon $matured_at
 * @property bool $has_left
 * @property ?Carbon $left_at
 * @property int $leave_count
 * @property int $rejoin_count
 * @property ?Carbon $revoked_at
 * @property int $revoked_credits
 * @property ?Carbon $last_seen_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class DiscordInvitedMember extends Model
{
    protected $table = 'discord_invited_members';

    protected $fillable = [
        'inviter_discord_id',
        'joined_discord_id',
        'invite_code',
        'account_created_at',
        'joined_at',
        'is_eligible',
        'is_rewarded',
        'rewarded_at',
        'matured_at',
        'has_left',
        'left_at',
        'leave_count',
        'rejoin_count',
        'revoked_at',
        'revoked_credits',
        'last_seen_at',
    ];

    protected $casts = [
        'account_created_at' => 'datetime',
        'joined_at' => 'datetime',
        'is_eligible' => 'boolean',
        'is_rewarded' => 'boolean',
        'rewarded_at' => 'datetime',
        'matured_at' => 'datetime',
        'has_left' => 'boolean',
        'left_at' => 'datetime',
        'leave_count' => 'integer',
        'rejoin_count' => 'integer',
        'revoked_at' => 'datetime',
        'revoked_credits' => 'integer',
        'last_seen_at' => 'datetime',
    ];

    /**
     * Rows that count toward an inviter's total: still in the guild, old enough to
     * not be an alt. Escrow state is deliberately not filtered here — a pending join
     * is a real join, it just has not paid out yet.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeCounted($query)
    {
        return $query->where('has_left', false)->where('is_eligible', true);
    }

    /**
     * Escrowed joins that are now payable: matured, eligible, still present, unpaid.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeMatured($query)
    {
        return $query->where('is_rewarded', false)
            ->where('has_left', false)
            ->where('is_eligible', true)
            ->whereNotNull('matured_at')
            ->where('matured_at', '<=', now());
    }

    /**
     * Calculate Discord account creation date from a Discord Snowflake ID.
     */
    public static function getCreatedAtFromSnowflake(string $snowflake): Carbon
    {
        // Discord Snowflake: ((id >> 22) + 1420070400000) ms Unix timestamp
        // 1420070400000 = Discord Epoch (Jan 1, 2015 UTC)
        // 2^22 = 4194304
        if (function_exists('bcdiv')) {
            $ms = (int) (bcdiv($snowflake, '4194304') + 1420070400000);
        } else {
            $ms = ((int) ($snowflake >> 22)) + 1420070400000;
        }

        return Carbon::createFromTimestampMs($ms);
    }

    /**
     * Determine if account creation age is at least the required minimum days.
     */
    public static function checkEligibility(string $joinedDiscordId, int $minAgeDays = 30): bool
    {
        $createdAt = self::getCreatedAtFromSnowflake($joinedDiscordId);
        $ageDays = $createdAt->diffInDays(now());

        return $ageDays >= $minAgeDays;
    }
}

