<?php

namespace PluggedHost\Economy\Models;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * A user's token balance. Tokens buy priority in the start queue.
 *
 * Method-for-method the same shape as UserCredits, on purpose: two ledgers that
 * behaved differently under the same names would be a trap for whoever reads this
 * next. The one addition is grantDailyIfDue().
 *
 * This was `ads_start_credits` until 2026-08-31 and the table was renamed rather
 * than recreated, which is why `lifetime_earned` and `daily_granted_on` are here at
 * all — see plugged-host-ads/database/migrations/002_rename_start_credits_to_tokens.php.
 *
 * @property int $id
 * @property int $user_id
 * @property int $tokens
 * @property ?Carbon $daily_granted_on
 * @property int $lifetime_earned
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 */
class UserTokens extends Model
{
    protected $table = 'user_tokens';

    protected $fillable = [
        'user_id',
        'tokens',
        'daily_granted_on',
        'lifetime_earned',
    ];

    protected $casts = [
        'tokens' => 'integer',
        'daily_granted_on' => 'date',
        'lifetime_earned' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(TokenTransaction::class, 'user_id', 'user_id')->latest('created_at');
    }

    /**
     * The row for a user, created on first sight.
     *
     * The hasTable() guard is what lets every caller stay unconditional: a plugin's
     * code is live the moment its files land, but the tables only exist once someone
     * has run `artisan migrate`. Between those two points every reader here returns
     * null instead of a 500.
     */
    protected static ?bool $tableExists = null;

    /** @var array<int, ?self> */
    protected static array $userMemo = [];

    public static function forgetMemo(?User $user = null): void
    {
        if ($user) {
            unset(self::$userMemo[(int) $user->getKey()]);
            return;
        }
        self::$userMemo = [];
    }

    public static function forUser(?User $user): ?self
    {
        if (!$user) {
            return null;
        }

        $id = (int) $user->getKey();
        if (array_key_exists($id, self::$userMemo)) {
            return self::$userMemo[$id];
        }

        if (self::$tableExists === null) {
            self::$tableExists = Schema::hasTable('user_tokens');
        }

        if (!self::$tableExists) {
            return self::$userMemo[$id] = null;
        }

        return self::$userMemo[$id] = self::firstOrCreate(
            ['user_id' => $id],
            ['tokens' => 0, 'lifetime_earned' => 0],
        );
    }

    public function canAfford(int $amount): bool
    {
        return $amount >= 0 && $this->tokens >= $amount;
    }

    public function addTokens(int $amount, string $type, string $description, array $metadata = []): TokenTransaction
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Token amount to add must be greater than zero.');
        }

        return DB::transaction(function () use ($amount, $type, $description, $metadata) {
            $this->increment('tokens', $amount);
            $this->increment('lifetime_earned', $amount);
            $this->refresh();

            return TokenTransaction::create([
                'user_id' => $this->user_id,
                'amount' => $amount,
                'balance_after' => $this->tokens,
                'type' => $type,
                'description' => $description,
                'metadata' => $metadata ?: null,
                'created_at' => now(),
            ]);
        });
    }

    public function deductTokens(int $amount, string $type, string $description, array $metadata = []): TokenTransaction
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Token amount to deduct must be greater than zero.');
        }

        return DB::transaction(function () use ($amount, $type, $description, $metadata) {
            // Re-read inside the transaction. The balance shown on the queue page was
            // rendered seconds ago and the same account can be open in two tabs, each
            // looking at a pass it wants to buy with the same tokens.
            $fresh = self::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($fresh->tokens < $amount) {
                throw new InvalidArgumentException('Insufficient token balance.');
            }

            $fresh->decrement('tokens', $amount);
            $fresh->refresh();
            $this->setRawAttributes($fresh->getAttributes(), true);

            return TokenTransaction::create([
                'user_id' => $this->user_id,
                'amount' => -$amount,
                'balance_after' => $this->tokens,
                'type' => $type,
                'description' => $description,
                'metadata' => $metadata ?: null,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Deduct at most `$amount`, returning what was actually taken.
     *
     * `tokens` is unsigned and deductTokens() throws once the balance is short,
     * which makes it unusable for clawing back a reward that turned out to be
     * fraudulent — that must never fail just because the token was already spent.
     * Same escape hatch, same reason, as UserCredits::deductCreditsUpTo().
     */
    public function deductTokensUpTo(int $amount, string $type, string $description, array $metadata = []): int
    {
        if ($amount <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($amount, $type, $description, $metadata) {
            $fresh = self::query()->lockForUpdate()->findOrFail($this->getKey());
            $take = min($amount, (int) $fresh->tokens);

            if ($take <= 0) {
                return 0;
            }

            $fresh->decrement('tokens', $take);
            $fresh->refresh();
            $this->setRawAttributes($fresh->getAttributes(), true);

            TokenTransaction::create([
                'user_id' => $this->user_id,
                'amount' => -$take,
                'balance_after' => $this->tokens,
                'type' => $type,
                'description' => $description,
                'metadata' => $metadata ?: null,
                'created_at' => now(),
            ]);

            return $take;
        });
    }

    /**
     * The free daily token, granted the first time the user is looked at today.
     *
     * Defaults to 0 — see the config for why. Lazy rather than scheduled: a nightly
     * job would write a row for every account that has ever existed to benefit the
     * handful who log in, and it would make the grant depend on the queue being alive.
     *
     * The claim is a conditional UPDATE whose affected-row count decides the winner,
     * not a read-then-write. Two tabs opening at once both see yesterday's date; only
     * one of them can move it, and the loser gets null.
     */
    public function grantDailyIfDue(): ?TokenTransaction
    {
        $amount = (int) config('plugged-host-economy.tokens.daily_grant', 0);

        if ($amount <= 0) {
            return null;
        }

        $today = now()->toDateString();

        return DB::transaction(function () use ($amount, $today) {
            $claimed = self::query()
                ->whereKey($this->getKey())
                ->where(fn ($query) => $query
                    ->whereNull('daily_granted_on')
                    ->orWhere('daily_granted_on', '<', $today))
                ->update(['daily_granted_on' => $today]);

            if ($claimed === 0) {
                return null;
            }

            $this->refresh();

            return $this->addTokens(
                $amount,
                'daily',
                trans('plugged-host-economy::strings.tokens.tx_daily'),
                ['date' => $today],
            );
        });
    }
}
