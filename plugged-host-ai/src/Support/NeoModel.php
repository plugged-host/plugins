<?php

namespace PluggedHost\Ai\Support;

use App\Models\User;
use PluggedHost\Core\Support\Plugins;
use Throwable;

/**
 * Which model a neo chat turn runs on, and on which plan its account is. Every model the backend
 * offers is picked by its key (the slot number) in the chat; a turn names the picked one and runs
 * on that model only, or on the backend's default model when nothing was picked.
 *
 * The plan (free, plus, plusplus: plugged-host-economy's MembershipService, free without it) says
 * which models the account may pick and how much of a limited one it gets. Whichever backend runs
 * neo decides by it.
 */
final class NeoModel
{
    public const PLANS = ['free', 'plus', 'plusplus'];

    public static function plan(?User $user): string
    {
        $membership = 'PluggedHost\\Economy\\Services\\MembershipService';

        try {
            if ($user === null || !Plugins::available($membership)) {
                return 'free';
            }

            $service = app($membership);

            return match (true) {
                $service->hasPlusPlus($user) => 'plusplus',
                $service->hasPlus($user) => 'plus',
                default => 'free',
            };
        } catch (Throwable) {
            return 'free';
        }
    }

    /**
     * Whether $plan may pick a model open to $access (everyone, plus, plusplus).
     */
    public static function allows(string $plan, string $access): bool
    {
        $rank = ['free' => 0, 'everyone' => 0, 'plus' => 1, 'plusplus' => 2];

        return ($rank[$plan] ?? 0) >= ($rank[$access] ?? 0);
    }

    /**
     * The model picked in the chat (its key), or null for the backend's default model.
     */
    public static function forTurn(User $user): ?string
    {
        return NeoPreferences::model($user);
    }
}
