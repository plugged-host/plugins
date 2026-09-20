<?php

namespace PluggedHost\Economy\Services;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PluggedHost\Economy\Models\TokenClaim;
use PluggedHost\Economy\Services\Rewards\RewardProvider;
use PluggedHost\Economy\Services\Rewards\RewardRegistry;
use PluggedHost\Economy\Models\TokenTransaction;
use PluggedHost\Economy\Services\TokenService;
use RuntimeException;
use Throwable;

/**
 * Everything about earning tokens off-site that must not vary between providers.
 *
 * A provider knows how to send a user out and how to ask its own API whether a
 * token is genuine. It does not decide who the claim belongs to, whether it is
 * still fresh, whether that token has been redeemed before, or whether the daily
 * cap is spent — because those are the rules that keep the currency honest, and a
 * copy per provider would eventually be a different rule per provider.
 *
 * This owns the *earning* half only. The ledger lives in plugged-host-economy,
 * because the same tokens are spent by plugged-host-queue, and a balance two plugins
 * each write in their own way is not a balance. The class was StartCreditService
 * until 2026-08-31; the rename followed the currency. One unhappy consequence is that
 * "token" now means two things in the code below — a provider's anti-bypass code
 * (`$token`, `$tokenHash`) and the currency it pays out in. The former never leaves
 * this class.
 *
 * The config key stayed `plugged-host-ads.start_credits` through all of that. It is
 * read from 14 files and written to `.env` as `ADS_START_*`, and renaming a settings
 * namespace to match a class name would have meant a live `.env` migration for no
 * behavioural gain.
 *
 * The order of checks in redeem() is deliberate and load-bearing: everything
 * cheap and local runs *before* the provider call, because an anti-bypass endpoint
 * is entitled to consume the token as it validates it. Verify a replay first and
 * you have burned a token that the legitimate flow still needed.
 *
 * @phpstan-type Redemption array{status: string, amount: int, provider: ?string}
 */
class TokenEarnService
{
    public const RESULT_GRANTED = 'granted';

    /** startClaim() only: the trip was opened. Nothing has been credited yet. */
    public const RESULT_OK = 'ok';

    public const RESULT_NO_CLAIM = 'no_claim';

    public const RESULT_SETTLED = 'settled';

    public const RESULT_EXPIRED = 'expired';

    public const RESULT_NO_TOKEN = 'no_token';

    public const RESULT_REPLAY = 'replay';

    public const RESULT_CAPPED = 'capped';

    public const RESULT_THROTTLED = 'throttled';

    public const RESULT_INVALID = 'invalid';

    public const RESULT_ERROR = 'error';

    public function __construct(protected RewardRegistry $registry, protected TokenService $tokens) {}

    public function registry(): RewardRegistry
    {
        return $this->registry;
    }

    /**
     * Is there any way at all to earn right now? Used to decide whether the earn
     * page is worth linking to.
     *
     * Deliberately *not* gated on `start_credits.enabled`. It was until 2026-08-31,
     * when a token bought a way past the start gate and so was worthless with the gate
     * off. Tokens now buy priority in plugged-host-queue, which is a different plugin
     * with its own switch, so the only questions left are whether the currency exists
     * at all and whether anything is configured to hand it out.
     */
    public function isEarnable(): bool
    {
        return $this->tokens->isEnabled()
            && $this->registry->available() !== [];
    }

    public function claimTtlMinutes(): int
    {
        return max(1, (int) config('plugged-host-ads.start_credits.claim_ttl_minutes', 30));
    }

    /**
     * Every read here goes through plugged-host-economy, which is where the ledger
     * lives and which already degrades a missing table to "0 tokens" rather than to a
     * 500 on a page the user needs. These three are thin on purpose: the earn page
     * should not have to know which plugin owns the balance it is printing.
     */
    public function balance(?User $user): int
    {
        return $this->tokens->balance($user);
    }

    /**
     * Kept as a no-op-ish passthrough rather than removed.
     *
     * The ads side used to hand out a free credit a day, which existed to buy one
     * ad-free start — a mechanic that is gone. Any daily grant is now the economy's
     * setting (`plugged-host-economy.tokens.daily_grant`, 0 by default), and this
     * forwards to it so the earn page keeps a single call to make on mount.
     */
    public function syncDailyGrant(?User $user): ?TokenTransaction
    {
        return $this->tokens->syncDailyGrant($user);
    }

    /**
     * @return array<int, TokenTransaction>
     */
    public function recentTransactions(?User $user, int $limit = 10): array
    {
        return $this->tokens->recentTransactions($user, $limit);
    }

    public function grantedToday(User $user, RewardProvider $provider): int
    {
        try {
            return TokenClaim::query()
                ->grantedToday($user->id, $provider->key())
                ->count();
        } catch (Throwable $e) {
            $this->log('daily cap read failed', $e);

            // Fail closed: a cap we cannot read is treated as reached. This is the
            // side of the coin where guessing wrong mints currency.
            return PHP_INT_MAX;
        }
    }

    public function capReached(User $user, RewardProvider $provider): bool
    {
        $cap = $provider->dailyCap();

        return $cap > 0 && $this->grantedToday($user, $provider) >= $cap;
    }

    /**
     * Open a claim and work out where to send the user.
     *
     * The claim row is written before the user leaves, and the nonce it carries is
     * what binds the return trip to this account. `$returnUrl` may contain the
     * literal `{nonce}`, which is substituted here — the caller cannot do it
     * itself, because the nonce does not exist until the row does.
     *
     * The nonce is a convenience rather than the security boundary: most publisher
     * dashboards pin the destination, so it often cannot travel at all. The
     * returning request is authenticated either way, and settleReturn() falls back
     * to this user's newest pending claim for the provider.
     *
     * @return array{status: string, claim: ?TokenClaim, url: ?string}
     */
    public function startClaim(User $user, RewardProvider $provider, string $returnUrl): array
    {
        if (!$provider->isConfigured()) {
            return ['status' => self::RESULT_INVALID, 'claim' => null, 'url' => null];
        }

        if ($this->capReached($user, $provider)) {
            return ['status' => self::RESULT_CAPPED, 'claim' => null, 'url' => null];
        }

        // Throttles *attempts*, which the daily cap does not: a cap of 5 grants is
        // no protection against a loop that opens a thousand pending rows and a
        // thousand outbound requests. Derived from the cap so one knob tunes both.
        $throttleKey = "ph:start-claim:{$provider->key()}:{$user->id}";
        $maxAttempts = max(5, $provider->dailyCap() * 3);

        try {
            if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
                return ['status' => self::RESULT_THROTTLED, 'claim' => null, 'url' => null];
            }

            RateLimiter::hit($throttleKey, 3600);
        } catch (Throwable $e) {
            $this->log('claim throttle failed', $e);
        }

        try {
            $claim = TokenClaim::create([
                'user_id' => $user->id,
                'provider' => $provider->key(),
                'nonce' => Str::random(64),
                'status' => TokenClaim::STATUS_PENDING,
                'amount' => 0,
                'ip' => Str::limit((string) request()->ip(), 45, ''),
                'user_agent' => Str::limit((string) request()->userAgent(), 255, ''),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            $this->log('claim create failed', $e);

            return ['status' => self::RESULT_ERROR, 'claim' => null, 'url' => null];
        }

        return [
            'status' => self::RESULT_OK,
            'claim' => $claim,
            'url' => $provider->buildUrl(
                $claim,
                str_replace('{nonce}', rawurlencode($claim->nonce), $returnUrl),
            ),
        ];
    }

    /**
     * The newest still-open claim for this user and provider.
     *
     * The fallback for the common case where the provider's dashboard pins the
     * destination and our nonce never makes the round trip. Newest-first and
     * freshness-bounded, so an abandoned claim from an hour ago cannot be settled
     * by someone else's token arriving now.
     *
     * It is also what keeps the Full Script anchor idempotent. That link has to
     * exist in the HTML the page ships — linkvertise.js rewrites anchors once, at
     * DOMContentLoaded — so the claim is opened at render rather than on a click,
     * and without reuse every reload of the earn page would open another pending row
     * and spend another attempt against the per-hour throttle.
     */
    public function latestPendingClaim(User $user, RewardProvider $provider): ?TokenClaim
    {
        try {
            return TokenClaim::query()
                ->where('user_id', $user->id)
                ->where('provider', $provider->key())
                ->where('status', TokenClaim::STATUS_PENDING)
                ->where('created_at', '>=', now()->subMinutes($this->claimTtlMinutes()))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();
        } catch (Throwable $e) {
            $this->log('pending claim lookup failed', $e);

            return null;
        }
    }

    public function latestPendingNonce(User $user, RewardProvider $provider): ?string
    {
        return $this->latestPendingClaim($user, $provider)?->nonce;
    }

    /**
     * Settle a returning claim.
     *
     * Read the sequence as "everything free first, then the one expensive thing
     * exactly once". The provider call is last because it is the only step with a
     * side effect on someone else's system — an anti-bypass check is entitled to
     * spend the token while answering — so a replay or a spent cap must be caught
     * before it, or the legitimate retry finds its own token already burned.
     *
     * @param  array<string, mixed>  $input  the returning request's query string
     * @return array{status: string, amount: int, provider: ?string}
     */
    public function redeem(User $user, string $nonce, array $input): array
    {
        try {
            $claim = TokenClaim::query()
                ->where('nonce', $nonce)
                ->where('user_id', $user->id)
                ->first();

            if (!$claim instanceof TokenClaim) {
                return $this->result(self::RESULT_NO_CLAIM);
            }

            $provider = $this->registry->find($claim->provider);

            if (!$provider instanceof RewardProvider) {
                return $this->result(self::RESULT_INVALID, 0, $claim->provider);
            }

            // Already settled, either way. Not an error — a refresh of the return
            // URL lands here, and the honest answer is "this one is done".
            if (!$claim->isPending()) {
                return $this->result(self::RESULT_SETTLED, (int) $claim->amount, $provider->key());
            }

            if (!$claim->isFresh($this->claimTtlMinutes())) {
                $claim->markRejected('expired');

                return $this->result(self::RESULT_EXPIRED, 0, $provider->key());
            }

            $token = $provider->extractToken($input);

            if ($token === null) {
                $claim->markRejected('no_token', ['params' => array_keys($input)]);

                return $this->result(self::RESULT_NO_TOKEN, 0, $provider->key());
            }

            $tokenHash = TokenClaim::hashToken($token);

            if ($this->tokenAlreadyUsed($provider, $tokenHash, $claim)) {
                $claim->markRejected('replay');

                return $this->result(self::RESULT_REPLAY, 0, $provider->key());
            }

            if ($this->capReached($user, $provider)) {
                $claim->markRejected('daily_cap');

                return $this->result(self::RESULT_CAPPED, 0, $provider->key());
            }

            if (!$provider->verifyToken($token)) {
                $claim->markRejected('not_verified');

                return $this->result(self::RESULT_INVALID, 0, $provider->key());
            }

            return $this->grant($user, $claim, $provider, $tokenHash);
        } catch (Throwable $e) {
            $this->log('redemption failed', $e);

            return $this->result(self::RESULT_ERROR);
        }
    }

    /**
     * The cheap local replay check.
     *
     * This is a courtesy, not the guarantee — two simultaneous requests can both
     * pass it. The guarantee is `unique(provider, token_hash)`, which turns the
     * loser of that race into a constraint violation inside grant().
     */
    protected function tokenAlreadyUsed(RewardProvider $provider, string $tokenHash, TokenClaim $claim): bool
    {
        return TokenClaim::query()
            ->where('provider', $provider->key())
            ->where('token_hash', $tokenHash)
            ->whereKeyNot($claim->getKey())
            ->exists();
    }

    /**
     * @return array{status: string, amount: int, provider: ?string}
     */
    protected function grant(User $user, TokenClaim $claim, RewardProvider $provider, string $tokenHash): array
    {
        $amount = $provider->rewardAmount();

        try {
            $paid = DB::transaction(function () use ($user, $claim, $provider, $tokenHash, $amount): bool {
                // Re-read under a lock so a double-submitted nonce cannot be paid
                // twice; the token index catches a *different* nonce reusing one
                // token, this catches one nonce arriving twice.
                $locked = TokenClaim::query()->lockForUpdate()->findOrFail($claim->getKey());

                if (!$locked->isPending()) {
                    return false;
                }

                $locked->markGranted($tokenHash, $amount, array_merge($claim->metadata ?? [], [
                    'reward' => $amount,
                ]));

                // The ledger write is the economy's, and it is inside this transaction
                // on purpose: if crediting fails the claim must not stay marked granted,
                // or the user is left with a spent provider token and no tokens. null
                // rather than an exception is TokenService's way of saying the ledger is
                // unreachable, so turn it into one and let the rollback happen.
                if ($this->tokens->add(
                    $user,
                    $amount,
                    "earn:{$provider->key()}",
                    trans('plugged-host-economy::strings.tokens_tx_earn', ['provider' => $provider->label()]),
                    ['claim_id' => $locked->getKey(), 'provider' => $provider->key()],
                ) === null) {
                    throw new RuntimeException('token ledger unavailable');
                }

                return true;
            });
        } catch (QueryException $e) {
            // A unique-index violation here is the replay backstop firing, which is
            // the system working. Anything else is a real database problem.
            if ($this->isDuplicate($e)) {
                $claim->markRejected('replay_race');

                return $this->result(self::RESULT_REPLAY, 0, $provider->key());
            }

            $this->log('grant failed', $e);

            return $this->result(self::RESULT_ERROR, 0, $provider->key());
        } catch (Throwable $e) {
            $this->log('grant failed', $e);

            return $this->result(self::RESULT_ERROR, 0, $provider->key());
        }

        if (!$paid) {
            return $this->result(self::RESULT_SETTLED, 0, $provider->key());
        }

        return $this->result(self::RESULT_GRANTED, $amount, $provider->key());
    }

    /**
     * @return array{status: string, amount: int, provider: ?string}
     */
    protected function result(string $status, int $amount = 0, ?string $provider = null): array
    {
        return ['status' => $status, 'amount' => $amount, 'provider' => $provider];
    }

    protected function isDuplicate(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            || str_contains($e->getMessage(), 'Duplicate entry')
            || str_contains($e->getMessage(), 'ads_claims_provider_token_unique');
    }

    protected function log(string $message, Throwable $e): void
    {
        Log::warning("plugged-host-ads: {$message}", [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
