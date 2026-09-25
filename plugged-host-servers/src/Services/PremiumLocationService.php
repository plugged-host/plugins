<?php

namespace PluggedHost\Servers\Services;

use App\Facades\Activity;
use App\Models\User;
use Filament\Notifications\Notification;
use PluggedHost\Servers\Support\PremiumAccess;
use Throwable;

/**
 * The paid-location unlock (WP13). One flat price unlocks every Plus and Premium location for a
 * user, permanently. Follows limbo's charge-then-refund idiom against economy's TokenService by
 * class-string, so servers does not hard-depend on economy.
 */
class PremiumLocationService
{
    public function cost(): int
    {
        return app(LocationCatalog::class)->premiumCost();
    }

    public function isUnlocked(?User $user): bool
    {
        return PremiumAccess::isUnlocked($user);
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
     * Unlock the premium tier for a user. Idempotent, and safe against a double click: spend()
     * re-reads under a lock and returns false (not throws) on an insufficient balance, and the
     * PremiumAccess write is refunded if it fails.
     *
     * @return array{ok: bool, reason?: string, cost?: int, balance?: int}
     */
    public function unlock(User $user): array
    {
        if (!PremiumAccess::supported()) {
            return ['ok' => false, 'reason' => 'unavailable'];
        }

        if (PremiumAccess::isUnlocked($user)) {
            return ['ok' => true, 'cost' => 0, 'balance' => $this->balance($user)];
        }

        $service = 'PluggedHost\\Economy\\Services\\TokenService';

        if (!class_exists($service)) {
            return ['ok' => false, 'reason' => 'economy_absent'];
        }

        $cost = $this->cost();

        try {
            $spent = (bool) app($service)->spend($user, $cost, 'premium_unlock', 'Premium tier unlock', ['reason' => 'premium tier unlock']);
        } catch (Throwable) {
            return ['ok' => false, 'reason' => 'charge_failed', 'cost' => $cost];
        }

        if (!$spent) {
            return ['ok' => false, 'reason' => 'insufficient_tokens', 'cost' => $cost, 'balance' => $this->balance($user)];
        }

        if (!PremiumAccess::unlock($user)) {
            // Refund only when the entitlement write failed.
            try {
                app($service)->add($user, $cost, 'premium_unlock_refund', 'Premium unlock refund', ['reason' => 'premium unlock refund']);
            } catch (Throwable) {
                //
            }

            return ['ok' => false, 'reason' => 'unlock_write_failed', 'cost' => $cost];
        }

        try {
            Activity::event('user:provision.premium-unlock')->actor($user)->subject($user)->property('cost', $cost)->log();
        } catch (Throwable) {
            //
        }

        try {
            Notification::make()->title('Plus and Premium locations unlocked')->success()->sendToDatabase($user);
        } catch (Throwable) {
            //
        }

        return ['ok' => true, 'cost' => $cost, 'balance' => $this->balance($user)];
    }
}
