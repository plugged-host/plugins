<?php

namespace PluggedHost\Economy\Services\Rewards;

use PluggedHost\Economy\Models\TokenClaim;

/**
 * One way to earn tokens off-site.
 *
 * The contract is deliberately narrow: a provider knows how to send a user out,
 * how to find its own token on the way back, and how to ask its own API whether
 * that token is real. Everything else — who the claim belongs to, whether it is
 * still fresh, whether the token has been seen before, whether the daily cap is
 * spent, and the actual payout — belongs to TokenEarnService, because those
 * rules must not vary per provider.
 *
 * Two invariants every implementation owes its caller:
 *
 * verifyToken() fails **closed**. A timeout, a 500 or an unparseable body is a
 * "no". This is the opposite of the start gate, which fails open — there the
 * downside of guessing wrong is locking a customer out of their own server; here
 * it is minting currency for free.
 *
 * verifyToken() may consume the token. An anti-bypass endpoint is entitled to treat
 * the check itself as the redemption, so it must be called at most once per token,
 * and only after the cheap local checks (freshness, replay, cap) have already
 * passed.
 */
interface RewardProvider
{
    /** Stable identifier; stored in `token_claims.provider`. */
    public function key(): string;

    /** Human label for the earn page. */
    public function label(): string;

    /** Has an admin supplied everything this provider needs to work? */
    public function isConfigured(): bool;

    public function rewardAmount(): int;

    /** Successful claims allowed per user per day. */
    public function dailyCap(): int;

    /**
     * Where to send the user. Null if unusable.
     *
     * `$returnUrl` is where the provider should drop them afterwards. Most
     * publisher dashboards pin the destination on their side, in which case this
     * is only useful to providers whose link format can carry a dynamic target.
     */
    public function buildUrl(TokenClaim $claim, string $returnUrl): ?string;

    /**
     * Pull this provider's token out of the returning request's query string.
     *
     * @param  array<string, mixed>  $input
     */
    public function extractToken(array $input): ?string;

    /** Ask the provider whether the token is genuine and unspent. Fails closed. */
    public function verifyToken(string $token): bool;
}
