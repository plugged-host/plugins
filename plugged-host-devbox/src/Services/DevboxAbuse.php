<?php

namespace PluggedHost\Devbox\Services;

use App\Models\Subuser;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use PluggedHost\Abuse\Models\AbuseFinding;
use PluggedHost\Abuse\Services\Finding;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Devbox\Models\Devbox;
use PluggedHost\Devbox\Models\DevboxBlock;
use PluggedHost\Devbox\Support\DevboxAccess;
use Throwable;

/**
 * Abuse protection (plugged-host-abuse) and devboxes. A critical finding on a devbox (a miner in
 * its home, a scan started from its terminal) takes devboxes away from the person it was made for
 * (for a shared devbox: the game server's owner, who pays for it and chose who is in it): every
 * devbox they run stops at once, and they get no new one, nor a sign-in to anyone else's, until an
 * admin dismisses or resolves the finding (Admin › Servers › Abuse). The finding's own server is
 * handled by the abuse plugin as for any server (suspended in suspend mode).
 *
 * The abuse plugin knows nothing of this: Eloquent's `saved` event on its findings is the hook
 * (DevboxPluginProvider), so every path that records or closes a finding (the scan, the CPU
 * watch, the node agent, the admin actions) passes through onFinding().
 */
final class DevboxAbuse
{
    public static function enabled(): bool
    {
        return Plugins::enabled('plugged-host-abuse')
            && Plugins::available(AbuseFinding::class)
            && AbuseFinding::ready();
    }

    /**
     * Whether $user may not use devboxes because of an abuse finding. Fails closed: a devbox is
     * a shell on a customer's files, and a database that can't answer this can't start one either.
     */
    public static function blocked(User $user): bool
    {
        if (!self::enabled()) {
            return false;
        }

        try {
            if (DevboxBlock::query()->where('user_id', $user->id)->whereNull('lifted_at')->exists()) {
                return true;
            }

            // A live finding on one of their devboxes that the hook did not see (recorded before
            // this code ran, or while the devbox plugin was switched off).
            $servers = DevboxAccess::paidBy((int) $user->id)->whereNotNull('server_id')->pluck('server_id')->all();

            return $servers !== [] && self::live(AbuseFinding::query()->whereIn('server_id', $servers))->exists();
        } catch (Throwable $exception) {
            report($exception);

            return true;
        }
    }

    /**
     * Whether $devbox's own server carries a live critical finding. Such a devbox is not deleted
     * (cleanup, shared on/off): its findings, the evidence an admin decides on, would go with it.
     */
    public static function underReview(Devbox $devbox): bool
    {
        if ($devbox->server_id === null || !self::enabled()) {
            return false;
        }

        try {
            return self::live(AbuseFinding::query()->where('server_id', $devbox->server_id))->exists();
        } catch (Throwable $exception) {
            report($exception);

            return true;
        }
    }

    /**
     * A finding was recorded, hit again, actioned, dismissed, resolved or reopened.
     */
    public function onFinding(AbuseFinding $finding): void
    {
        if ($finding->server_id === null || $finding->severity !== Finding::CRITICAL || !DevboxAccess::tableExists()) {
            return;
        }

        if (!in_array($finding->status, [AbuseFinding::OPEN, AbuseFinding::ACTIONED], true)) {
            // Dismissed (a false positive) or resolved by an admin: devboxes again.
            DevboxBlock::query()->where('finding_id', $finding->id)->whereNull('lifted_at')->update(['lifted_at' => now()]);

            return;
        }

        $devbox = Devbox::query()->where('server_id', $finding->server_id)->first();
        $person = $devbox === null ? null : ($devbox->isShared() ? $devbox->game?->owner_id : $devbox->user_id);

        $added = 0;
        if ($person !== null) {
            $added = DevboxBlock::query()->insertOrIgnore([
                'user_id' => (int) $person,
                'finding_id' => (int) $finding->id,
                'server_id' => (int) $finding->server_id,
                'rule' => mb_substr((string) $finding->rule, 0, 64),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        // Reopened: whoever it blocked before is blocked again.
        $added += DevboxBlock::query()->where('finding_id', $finding->id)->whereNotNull('lifted_at')->update(['lifted_at' => null]);

        $people = DevboxBlock::query()->where('finding_id', $finding->id)->pluck('user_id')->map(fn ($id): int => (int) $id)->unique()->all();
        if ($added > 0) {
            Log::warning('plugged-host-devbox: devboxes taken away for an abuse finding', [
                'finding' => $finding->id,
                'rule' => $finding->rule,
                'server' => $finding->server_id,
                'users' => $people,
            ]);
        }

        // Every save of a live finding (each hit) lands here; after the first, nothing runs.
        $power = app(DevboxPower::class);
        foreach ($people as $userId) {
            foreach (DevboxAccess::paidBy($userId)->whereNotNull('token_hash')->whereNull('stopping_at')->get() as $running) {
                try {
                    $power->stop($running);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }

        if ($added === 0) {
            return;
        }

        // Shared devboxes of other owners they are in: people() leaves them out from now on, and a
        // restart (new token, every process ended) takes along what they left running there.
        $games = Subuser::query()->whereIn('user_id', $people)->pluck('server_id')->all();
        if ($games === []) {
            return;
        }

        foreach (Devbox::query()->whereNull('user_id')->whereIn('game_server_id', $games)->whereNotNull('token_hash')->whereNull('stopping_at')->get() as $shared) {
            try {
                $power->restart($shared);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * @template TBuilder of \Illuminate\Database\Eloquent\Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    private static function live($query)
    {
        return $query->where('severity', Finding::CRITICAL)
            ->whereIn('status', [AbuseFinding::OPEN, AbuseFinding::ACTIONED]);
    }
}
