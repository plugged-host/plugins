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
 * A user's credit balance and the resources those credits have already bought.
 *
 * Credits buy resources and nothing else — see UserTokens for the other currency
 * and PluggedHost\Economy\README.md for why the two never meet.
 *
 * @property int $id
 * @property int $user_id
 * @property int $credits
 * @property int $extra_cpu
 * @property int $extra_memory
 * @property int $extra_disk
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 */
class UserCredits extends Model
{
    protected $table = 'user_credits';

    protected $fillable = [
        'user_id',
        'credits',
        'extra_cpu',
        'extra_memory',
        'extra_disk',
    ];

    protected $casts = [
        'noprofit_plan' => 'array',
        'credits' => 'integer',
        'extra_cpu' => 'integer',
        'extra_memory' => 'integer',
        'extra_disk' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class, 'user_id', 'user_id')->latest('created_at');
    }

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
            self::$tableExists = Schema::hasTable('user_credits');
        }

        if (!self::$tableExists) {
            return self::$userMemo[$id] = null;
        }

        return self::$userMemo[$id] = self::firstOrCreate(
            ['user_id' => $id],
            [
                // Starting credits: ComputePlans::startingCredits(), granted when the user is
                // created (ComputePlansServiceProvider), or by noprofit.space via the sync.
                'credits' => 0,
                'extra_cpu' => 0,
                'extra_memory' => 0,
                'extra_disk' => 0,
            ]
        );
    }

    public function canAfford(int $amount): bool
    {
        return $amount >= 0 && $this->credits >= $amount;
    }

    public function addCredits(int $amount, string $type, string $description, array $metadata = []): CreditTransaction
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Credit amount to add must be greater than zero.');
        }

        return DB::transaction(function () use ($amount, $type, $description, $metadata) {
            $this->increment('credits', $amount);
            $this->refresh();

            return CreditTransaction::create([
                'user_id' => $this->user_id,
                'amount' => $amount,
                'balance_after' => $this->credits,
                'type' => $type,
                'description' => $description,
                'metadata' => $metadata ?: null,
                'created_at' => now(),
            ]);
        });
    }

    public function deductCredits(int $amount, string $type, string $description, array $metadata = []): CreditTransaction
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Credit amount to deduct must be greater than zero.');
        }

        return DB::transaction(function () use ($amount, $type, $description, $metadata) {
            if ($this->credits < $amount) {
                throw new InvalidArgumentException('Insufficient credits balance.');
            }

            $this->decrement('credits', $amount);
            $this->refresh();

            return CreditTransaction::create([
                'user_id' => $this->user_id,
                'amount' => -$amount,
                'balance_after' => $this->credits,
                'type' => $type,
                'description' => $description,
                'metadata' => $metadata ?: null,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Deduct at most `$amount` credits, taking whatever the balance can cover and
     * returning the number actually taken.
     *
     * `credits` is an unsigned column and deductCredits() throws once the balance
     * is short, which makes it unusable for reward clawbacks — those must never
     * fail just because the user already spent the credits. Callers recover the
     * remainder elsewhere (see CreditRecoveryService).
     */
    public function deductCreditsUpTo(int $amount, string $type, string $description, array $metadata = []): int
    {
        if ($amount <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($amount, $type, $description, $metadata) {
            $this->refresh();
            $take = min($amount, (int) $this->credits);

            if ($take <= 0) {
                return 0;
            }

            $this->decrement('credits', $take);
            $this->refresh();

            CreditTransaction::create([
                'user_id' => $this->user_id,
                'amount' => -$take,
                'balance_after' => $this->credits,
                'type' => $type,
                'description' => $description,
                'metadata' => $metadata ?: null,
                'created_at' => now(),
            ]);

            return $take;
        });
    }

    public function addExtraResources(int $cpu = 0, int $memory = 0, int $disk = 0): void
    {
        $this->update([
            'extra_cpu' => max(0, $this->extra_cpu + $cpu),
            'extra_memory' => max(0, $this->extra_memory + $memory),
            'extra_disk' => max(0, $this->extra_disk + $disk),
        ]);
    }

    /**
     * Take purchased resources back off the account, clamped so no column can go
     * negative (they are all unsigned).
     *
     * This does not check whether the user's servers are still inside the reduced
     * limit — CreditRecoveryService owns that guard and only ever passes amounts
     * that fit in the unused headroom.
     */
    public function reduceExtraResources(int $cpu = 0, int $memory = 0, int $disk = 0): void
    {
        $this->addExtraResources(-$cpu, -$memory, -$disk);
    }
}
