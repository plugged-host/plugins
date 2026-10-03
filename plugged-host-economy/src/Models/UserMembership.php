<?php

namespace PluggedHost\Economy\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An account's plugged.host Plus and PlusPlus (MembershipService).
 *
 * @property int $id
 * @property int $user_id
 * @property ?Carbon $plus_at
 * @property ?Carbon $plusplus_until
 * @property ?Carbon $plusplus_settled_at
 * @property int $plusplus_purchases
 */
class UserMembership extends Model
{
    protected $table = 'user_memberships';

    protected $fillable = [
        'user_id',
        'plus_at',
        'plusplus_until',
        'plusplus_settled_at',
        'plusplus_purchases',
    ];

    protected function casts(): array
    {
        return [
            'plus_at' => 'datetime',
            'plusplus_until' => 'datetime',
            'plusplus_settled_at' => 'datetime',
            'plusplus_purchases' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasPlus(): bool
    {
        return $this->plus_at !== null;
    }

    /**
     * PlusPlus counts only on top of Plus.
     */
    public function hasPlusPlus(): bool
    {
        return $this->hasPlus() && $this->plusplus_until !== null && $this->plusplus_until->isFuture();
    }
}
