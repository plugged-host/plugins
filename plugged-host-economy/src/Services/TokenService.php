<?php

namespace PluggedHost\Economy\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use PluggedHost\Economy\Models\TokenTransaction;
use PluggedHost\Economy\Models\UserTokens;
use Throwable;

/**
 * The token ledger's public face.
 *
 * Everything that earns or spends tokens goes through here rather than through the
 * model: `plugged-host-ads` credits them, `plugged-host-queue` spends them, and
 * neither should have to know that a missing table means "0 tokens" rather than a
 * 500. Every read is wrapped for exactly that reason — the tables exist only after
 * `artisan migrate`, and a balance that cannot be read has to degrade to zero on a
 * page the user needs.
 *
 * Spending is the one operation that does *not* swallow its failure quietly: it
 * returns false, so the caller can tell the user "that did not go through" instead of
 * handing out a pass nobody paid for.
 */
class TokenService
{
    public function isEnabled(): bool
    {
        return (bool) config('plugged-host-economy.tokens.enabled', true);
    }

    public function dailyGrant(): int
    {
        return max(0, (int) config('plugged-host-economy.tokens.daily_grant', 0));
    }

    public function balance(?User $user): int
    {
        try {
            return (int) (UserTokens::forUser($user)?->tokens ?? 0);
        } catch (Throwable $e) {
            $this->log('token balance read failed', $e);

            return 0;
        }
    }

    /**
     * The free daily token. Safe to call on every render — the model's conditional
     * UPDATE is what makes it once-a-day, not the caller.
     */
    public function syncDailyGrant(?User $user): ?TokenTransaction
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            return UserTokens::forUser($user)?->grantDailyIfDue();
        } catch (Throwable $e) {
            $this->log('token daily grant failed', $e);

            return null;
        }
    }

    /**
     * Credit tokens. Returns the ledger row, or null when the ledger is unreachable.
     *
     * Callers treat null as "not paid" and are expected to leave whatever they were
     * settling in a state that can be retried.
     */
    public function add(?User $user, int $amount, string $type, string $description, array $metadata = []): ?TokenTransaction
    {
        if (!$user || $amount <= 0) {
            return null;
        }

        $tokens = UserTokens::forUser($user);

        if (!$tokens instanceof UserTokens) {
            return null;
        }

        return $tokens->addTokens($amount, $type, $description, $metadata);
    }

    /**
     * Spend tokens, all of them or none.
     *
     * False rather than an exception, because every caller is a Livewire action on a
     * page the user is standing on: "you no longer have enough tokens" has to be a
     * notification, not an error page. The balance is re-read under a lock inside
     * deductTokens(), so a stale button cannot overdraw.
     */
    public function spend(?User $user, int $amount, string $type, string $description, array $metadata = []): bool
    {
        if (!$user) {
            return false;
        }

        if ($amount <= 0) {
            return true;
        }

        try {
            $tokens = UserTokens::forUser($user);

            if (!$tokens instanceof UserTokens) {
                return false;
            }

            $tokens->deductTokens($amount, $type, $description, $metadata);

            return true;
        } catch (Throwable $e) {
            $this->log('token spend failed', $e);

            return false;
        }
    }

    /**
     * Take back at most `$amount`, returning what was actually taken. For clawbacks,
     * which must never fail just because the tokens were already spent.
     */
    public function reclaimUpTo(?User $user, int $amount, string $type, string $description, array $metadata = []): int
    {
        if (!$user || $amount <= 0) {
            return 0;
        }

        try {
            return (int) (UserTokens::forUser($user)?->deductTokensUpTo($amount, $type, $description, $metadata) ?? 0);
        } catch (Throwable $e) {
            $this->log('token reclaim failed', $e);

            return 0;
        }
    }

    /**
     * @return array<int, TokenTransaction>
     */
    public function recentTransactions(?User $user, int $limit = 10): array
    {
        if (!$user) {
            return [];
        }

        try {
            return TokenTransaction::query()
                ->where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(max(1, $limit))
                ->get()
                ->all();
        } catch (Throwable $e) {
            $this->log('token transaction read failed', $e);

            return [];
        }
    }

    protected function log(string $message, Throwable $e): void
    {
        Log::warning("plugged-host-economy: {$message}", [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
