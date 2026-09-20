<?php

namespace PluggedHost\Economy\Models;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to earn tokens from an off-site reward provider.
 *
 * The row is written *before* the user leaves the panel, which is the point: the
 * `nonce` we mint here is what ties the returning request back to the account
 * that started the trip. Without it a provider token would be redeemable by
 * whoever presents it, and these tokens travel through a URL bar.
 *
 * The table was `ads_start_credit_claims` until 2026-08-31 and was renamed rather
 * than recreated — see database/migrations/002_rename_tokens_to_tokens.php.
 * It stays in this plugin while the balance it pays into lives in
 * plugged-host-economy, because a claim is an ads-side artefact: it records a trip
 * through a sponsored link, and nothing outside this plugin has a use for it.
 *
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $nonce
 * @property ?string $token_hash
 * @property string $status
 * @property int $amount
 * @property ?string $ip
 * @property ?string $user_agent
 * @property ?array $metadata
 * @property Carbon $created_at
 * @property ?Carbon $completed_at
 * @property-read User $user
 */
class TokenClaim extends Model
{
    public $timestamps = false;

    public const STATUS_PENDING = 'pending';

    public const STATUS_GRANTED = 'granted';

    public const STATUS_REJECTED = 'rejected';

    protected $table = 'token_claims';

    protected $fillable = [
        'user_id',
        'provider',
        'nonce',
        'token_hash',
        'status',
        'amount',
        'ip',
        'user_agent',
        'metadata',
        'created_at',
        'completed_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Provider tokens are never stored in the clear — see the migration.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', trim($token));
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * A claim is only redeemable for a short window. Linkvertise's anti-bypass
     * token is valid for about ten seconds, so anything older than this never had
     * a live token attached to it — the window only has to cover the round trip.
     */
    public function isFresh(int $minutes): bool
    {
        return $this->created_at !== null && $this->created_at->gt(now()->subMinutes(max(1, $minutes)));
    }

    public function markGranted(string $tokenHash, int $amount, array $metadata = []): void
    {
        $this->update([
            'token_hash' => $tokenHash,
            'status' => self::STATUS_GRANTED,
            'amount' => $amount,
            'metadata' => $metadata ?: $this->metadata,
            'completed_at' => now(),
        ]);
    }

    public function markRejected(string $reason, array $metadata = []): void
    {
        $this->update([
            'status' => self::STATUS_REJECTED,
            'metadata' => array_merge($this->metadata ?? [], $metadata, ['reason' => $reason]),
            'completed_at' => now(),
        ]);
    }

    /**
     * @param  Builder<TokenClaim>  $query
     * @return Builder<TokenClaim>
     */
    public function scopeGrantedToday(Builder $query, int $userId, string $provider): Builder
    {
        return $query->where('user_id', $userId)
            ->where('provider', $provider)
            ->where('status', self::STATUS_GRANTED)
            ->where('created_at', '>=', now()->startOfDay());
    }
}
