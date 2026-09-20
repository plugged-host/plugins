<?php

namespace PluggedHost\Economy\Models;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the token ledger. Mirrors CreditTransaction so the two currencies
 * read the same way in a query.
 *
 * Was `ads_start_credit_transactions`; renamed in place, so rows predating the
 * token rename are still here and still say "start credit" in their description.
 *
 * @property int $id
 * @property int $user_id
 * @property int $amount
 * @property int $balance_after
 * @property string $type
 * @property string $description
 * @property ?array $metadata
 * @property Carbon $created_at
 * @property-read User $user
 */
class TokenTransaction extends Model
{
    public $timestamps = false;

    protected $table = 'token_transactions';

    protected $fillable = [
        'user_id',
        'amount',
        'balance_after',
        'type',
        'description',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'balance_after' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
