<?php

namespace PluggedHost\Abuse\Services;

use App\Models\Server;
use Carbon\CarbonImmutable;
use PluggedHost\Core\Filament\Server\Pages\SuspendedServerPage;
use PluggedHost\Abuse\Models\AbuseFinding;
use PluggedHost\Core\Support\SuspensionReason;
use Throwable;

/**
 * Deleting servers the abuse checks suspended, ABUSE_DELETE_AFTER_HOURS after the suspension
 * (plugged-host:abuse-purge; 0 turns it off). The owner's suspended page shows when.
 *
 * Only abuse suspensions, and only while nothing says otherwise. Limbo parks hibernated servers
 * with the same Suspended status, and admins suspend for their own reasons, so a server is
 * deleted only when all of these hold:
 *   - it is suspended and not parked by limbo (SuspendedServerPage::appliesTo);
 *   - its latest suspension came from the abuse checks;
 *   - nobody has unsuspended it since (an admin lifting it stops the clock for good);
 *   - no finding on it was dismissed as a false positive.
 */
final class AbusePurge
{
    public static function hours(): int
    {
        return max(0, (int) config('plugged-host-abuse.delete_after_hours', 24));
    }

    /** When $server will be deleted, or null when it will not be. */
    public static function deletesAt(Server $server): ?CarbonImmutable
    {
        $hours = self::hours();

        if ($hours === 0 || !SuspendedServerPage::appliesTo($server)) {
            return null;
        }

        $reason = SuspensionReason::latest($server);

        if ($reason === null || $reason['source'] !== SuspensionReason::SOURCE_ABUSE_SCAN) {
            return null;
        }

        if (SuspensionReason::unsuspendedSince($server, $reason['at'])) {
            return null;
        }

        try {
            if (AbuseFinding::ready() && AbuseFinding::query()->where('server_id', $server->id)->where('status', AbuseFinding::DISMISSED)->exists()) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return $reason['at']->addHours($hours);
    }
}
