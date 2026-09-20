<?php

namespace PluggedHost\Economy\Support;

use App\Models\User;
use PluggedHost\Economy\Services\Rewards\LinkvertiseProvider;
use PluggedHost\Economy\Services\Rewards\RewardProvider;
use PluggedHost\Economy\Services\TokenEarnService;

/**
 * What is on offer for earning tokens, as a presentation model.
 *
 * Two surfaces show this list now — the earn page itself, and the block this plugin
 * renders onto plugged-host-economy's Economy page — and every number on it is one
 * somebody decides on: what a completion pays, whether there is a daily cap, and how
 * much of that cap is already gone. A second copy of the mapping would drift, and the
 * two places would disagree about the same account on the same day.
 *
 * It deliberately builds no outbound URL. Doing that means opening a claim row and
 * spending an attempt against the per-hour throttle, so it stays on EarnTokensPage —
 * the only surface that can settle the return trip, and the only one whose HTML the
 * Linkvertise script is loaded into.
 */
class EarnOffers
{
    public function __construct(protected TokenEarnService $tokens) {}

    /**
     * Every configured provider, in registry order.
     *
     * @return list<array<string, mixed>>
     */
    public function cards(?User $user): array
    {
        $cards = [];

        foreach ($this->tokens->registry()->available() as $provider) {
            $cards[] = $this->card($user, $provider);
        }

        return $cards;
    }

    /**
     * @return array<string, mixed>
     */
    public function card(?User $user, RewardProvider $provider): array
    {
        $cap = $provider->dailyCap();
        $used = $user instanceof User ? $this->tokens->grantedToday($user, $provider) : 0;

        return [
            'key' => $provider->key(),
            'label' => $provider->label(),
            'reward' => $provider->rewardAmount(),
            'cap' => $cap,
            // grantedToday() answers PHP_INT_MAX when it cannot read the table, which is
            // the right answer for the cap check and a nonsense one to print, so clamp
            // it for display.
            'used' => min($used, $cap > 0 ? $cap : $used),
            'capped' => $user instanceof User && $this->tokens->capReached($user, $provider),
            'fullscript' => $this->isFullScript($provider),
        ];
    }

    public function isFullScript(RewardProvider $provider): bool
    {
        return $provider instanceof LinkvertiseProvider && $provider->usesFullScript();
    }
}
