<?php

namespace PluggedHost\Queue\Models;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A paid window during which every start this user makes jumps the line.
 *
 * Account-wide rather than per server, because that is what was sold: tokens buy days
 * of not queueing, on everything you own. One row per user, extended rather than
 * replaced, so buying again while a window is open adds to the end of it instead of
 * throwing away what is left.
 *
 * Two windows, not one. `expires_at` is the priority, which both tiers buy;
 * `ad_free_until` is the quiet, which only the ad-free tier buys. They are separate
 * dates because they are sold in different lengths and legitimately overlap — nine
 * days of priority with two of quiet inside it is an ordinary state, and one date
 * could not describe it.
 *
 * Kept in its own table and never touched by the sweep. An entry is scratch state; a
 * window is something somebody paid for, and the two must not share a code path that
 * is allowed to delete rows.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $expires_at
 * @property Carbon|null $ad_free_until
 * @property int $tokens_spent
 * @property int $purchases
 * @property int $ad_free_purchases
 * @property-read User $user
 */
class StartQueuePriority extends Model
{
    protected $table = 'start_queue_priorities';

    protected $fillable = [
        'user_id',
        'expires_at',
        'ad_free_until',
        'tokens_spent',
        'purchases',
        'ad_free_purchases',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'ad_free_until' => 'datetime',
        'tokens_spent' => 'integer',
        'purchases' => 'integer',
        'ad_free_purchases' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isFuture();
    }

    /**
     * Seconds left on the window, or 0 once it has run out.
     *
     * Seconds rather than days so the page can render "2 days" and "40 minutes" from
     * the same number; a window in its last hour still has to read as a window.
     */
    public function remainingSeconds(): int
    {
        if (!$this->isActive()) {
            return 0;
        }

        return max(0, $this->expires_at->getTimestamp() - now()->getTimestamp());
    }

    /**
     * Is this account inside its no-advertising window?
     *
     * Read on every page render through `AdDisplayService::isExemptUser()`, so it is
     * deliberately one comparison against an already-hydrated column and nothing else.
     */
    public function isAdFree(): bool
    {
        return $this->ad_free_until !== null && $this->ad_free_until->isFuture();
    }

    public function adFreeRemainingSeconds(): int
    {
        if (!$this->isAdFree()) {
            return 0;
        }

        return max(0, $this->ad_free_until->getTimestamp() - now()->getTimestamp());
    }
}
