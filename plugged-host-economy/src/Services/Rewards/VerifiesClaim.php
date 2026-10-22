<?php

namespace PluggedHost\Economy\Services\Rewards;

use PluggedHost\Economy\Models\TokenClaim;

/**
 * A provider whose returning token can only be checked against the claim it was minted for.
 *
 * Linkvertise answers "is this hash real?" on its own API, so a bare token is enough there. A
 * shortener without an anti-bypass API (Vylo) has nothing to ask: the proof is a value we put
 * into the destination ourselves, and it means something only next to the claim that minted it.
 * TokenEarnService calls this in place of verifyToken() when a provider implements it, at the
 * same point in redeem() and with the same fail-closed contract.
 */
interface VerifiesClaim
{
    public function verifyClaim(TokenClaim $claim, string $token): bool;
}
