<?php

namespace PluggedHost\Servers\Services;

use App\Facades\Activity;
use App\Models\User;
use Filament\Notifications\Notification;
use PluggedHost\Servers\Support\TierAccess;
use Throwable;

/**
 * The paid location-tier unlock (WP13 premium, and Plus). One flat price per tier unlocks every
 * location in that tier for a user, permanently. Follows limbo's charge-then-refund idiom against
 * economy's TokenService by class-string, so servers does not hard-depend on economy.
 */
class TierUnlockService
{
    public function cost(string $tier): int
    {
        return app(LocationCatalog::class)->tierCost($tier);
    }

    public function isUnlocked(?User $user, string $tier): bool
    {
        return TierAccess::isUnlocked($user, $tier);
    }

    public function balance(User $user): int
    {
        $service = 'PluggedHost\\Economy\\Services\\TokenService';

        if (!class_exists($service)) {
            return 0;
        }

        try {
            return (int) app($service)->balance($user);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Unlock a paid tier for a user. Idempotent (a higher tier already covering it counts), and
     * safe against a double click: spend() re-reads under a lock and returns false (not throws)
     * on an insufficient balance, and the TierAccess write is refunded if it fails.
     *
     * @return array{ok: bool, reason?: string, cost?: int, balance?: int}
     */
    public function unlock(User $user, string $tier): array
    {
        if (!TierAccess::supported($tier)) {
            return ['ok' => false, 'reason' => 'unavailable'];
        }

        if (TierAccess::isUnlocked($user, $tier)) {
            return ['ok' => true, 'cost' => 0, 'balance' => $this->balance($user)];
        }

        $service = 'PluggedHost\\Economy\\Services\\TokenService';

        if (!class_exists($service)) {
            return ['ok' => false, 'reason' => 'economy_absent'];
        }

        $cost = $this->cost($tier);
        $name = ucfirst($tier);

        try {
            $spent = (bool) app($service)->spend($user, $cost, "{$tier}_unlock", "{$name} tier unlock", ['reason' => "{$tier} tier unlock"]);
        } catch (Throwable) {
            return ['ok' => false, 'reason' => 'charge_failed', 'cost' => $cost];
        }

        if (!$spent) {
            return ['ok' => false, 'reason' => 'insufficient_tokens', 'cost' => $cost, 'balance' => $this->balance($user)];
        }

        if (!TierAccess::unlock($user, $tier)) {
            // Refund only when the entitlement write failed.
            try {
                app($service)->add($user, $cost, "{$tier}_unlock_refund", "{$name} unlock refund", ['reason' => "{$tier} unlock refund"]);
            } catch (Throwable) {
                //
            }

            return ['ok' => false, 'reason' => 'unlock_write_failed', 'cost' => $cost];
        }

        try {
            Activity::event("user:provision.{$tier}-unlock")->actor($user)->subject($user)->property('cost', $cost)->log();
        } catch (Throwable) {
            //
        }

        try {
            Notification::make()->title("{$name} locations unlocked")->success()->sendToDatabase($user);
        } catch (Throwable) {
            //
        }

        return ['ok' => true, 'cost' => $cost, 'balance' => $this->balance($user)];
    }
}
