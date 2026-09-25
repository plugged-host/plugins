<?php

namespace PluggedHost\Limbo\Services;

use App\Enums\ServerState;
use App\Facades\Activity;
use App\Models\Allocation;
use App\Models\Server;
use App\Services\Servers\TransferServerService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PluggedHost\Limbo\Filament\Server\Pages\RestoreFromLimboPage;
use PluggedHost\Limbo\Models\ServerLimboState;
use Throwable;

/**
 * Idle-server hibernation (WP5).
 *
 * Everything degrades to a logged-once no-op when the server_limbo_states table is missing
 * (the migration is handed to the operator, like TokenService's own tables), and the whole
 * feature is dark unless LIMBO_ENABLED is true.
 *
 * The idle definition has ONE seam — lastActivityAt() — so switching from panel-activity to the
 * daemon-sampling variant later is a one-method change, not a redesign.
 */
class LimboService
{
    private static bool $missingTableLogged = false;

    public function enabled(): bool
    {
        return (bool) config('plugged-host-limbo.enabled', false);
    }

    public function tableReady(): bool
    {
        try {
            if (Schema::hasTable('server_limbo_states')) {
                return true;
            }
        } catch (Throwable) {
            //
        }

        if (!self::$missingTableLogged) {
            self::$missingTableLogged = true;
            Log::warning('plugged-host-limbo: server_limbo_states table missing; limbo inert.');
        }

        return false;
    }

    /**
     * The single idle seam: most recent panel activity for a server, falling back to when it was
     * created. Replace the body with a daemon-sampling read to change the whole policy.
     */
    public function lastActivityAt(Server $server): ?CarbonInterface
    {
        try {
            $ts = DB::table('activity_logs')
                ->join('activity_log_subjects', 'activity_logs.id', '=', 'activity_log_subjects.activity_log_id')
                ->where('activity_log_subjects.subject_type', $server->getMorphClass())
                ->where('activity_log_subjects.subject_id', $server->getKey())
                ->max('activity_logs.timestamp');

            if ($ts !== null) {
                return Carbon::parse($ts);
            }
        } catch (Throwable) {
            //
        }

        return $server->created_at ? Carbon::parse($server->created_at) : null;
    }

    /** Hours since last activity, or null when it cannot be determined. */
    public function idleHours(Server $server): ?float
    {
        $last = $this->lastActivityAt($server);

        return $last === null ? null : $last->diffInMinutes(now()) / 60;
    }

    public function state(Server $server): ?ServerLimboState
    {
        if (!$this->tableReady()) {
            return null;
        }

        return ServerLimboState::query()->where('server_id', $server->getKey())->first();
    }

    /**
     * The PowerActionInterceptor seam (ads → limbo, first in the chain). Returns the restore
     * page URL when the server is parked in limbo, else null (start proceeds).
     */
    public function interceptStart(mixed $user, Server $server, string $action): ?string
    {
        if (!$this->tableReady()) {
            return null;
        }

        $state = $this->state($server);

        if ($state === null) {
            return null;
        }

        // A container that was only stopped (not yet transferred) starts normally; clear the
        // marker so the sweep does not immediately re-stop it — the click is itself activity.
        if ($state->state === 'stopped') {
            $state->delete();

            return null;
        }

        if (in_array($state->state, ['limbo', 'restoring'], true)) {
            return RestoreFromLimboPage::getUrl(panel: 'server', tenant: $server);
        }

        return null;
    }

    /** Cost to restore, per the ransom window measured from limbo_at. */
    public function cost(ServerLimboState $state): int
    {
        $window = (int) config('plugged-host-limbo.cost_window_hours', 72);
        $early = (int) config('plugged-host-limbo.cost_early', 5);
        $late = (int) config('plugged-host-limbo.cost_late', 10);

        if ($state->limbo_at !== null && now()->greaterThan($state->limbo_at->copy()->addHours($window))) {
            return $late;
        }

        return $early;
    }

    /**
     * Stop an idle container (48h). Records a `stopped` marker and notifies the owner. Reversible
     * for free — the server is still on its own node.
     */
    public function stop(Server $server): bool
    {
        if (!$this->tableReady()) {
            return false;
        }

        try {
            // Stop first, record second: a stop wings refused must not leave a running server
            // marked as stopped. (DaemonPowerRepository does not exist in this Pelican; power
            // lives on DaemonServerRepository and throws on a refused call.)
            app(\App\Repositories\Daemon\DaemonServerRepository::class)->setServer($server)->power('stop');

            ServerLimboState::query()->updateOrCreate(
                ['server_id' => $server->getKey()],
                ['state' => 'stopped', 'idle_since' => $this->lastActivityAt($server), 'stopped_at' => now()],
            );

            Activity::event('server:limbo.stop')->subject($server)->log();

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Move an idle-past-transfer server (72h) to the limbo node and park it (suspended).
     *
     * Reuses upstream TransferServerService (the limbo node is a live wings node, so a real
     * transfer works — unlike failover's dead-node case). Origin node + allocations are recorded
     * first so restore can return it to the same location tag.
     *
     * VERIFY IN PHASE C: that Server::validateTransferState() permits transferring before the
     * server is suspended; if not, suspend must happen after arrival (handled in restore()).
     */
    public function hibernate(Server $server): bool
    {
        if (!$this->tableReady()) {
            return false;
        }

        $limboNode = $this->limboNode();

        if ($limboNode === null) {
            Log::warning('plugged-host-limbo: no limbo node found (tag ' . config('plugged-host-limbo.node_tag') . '); skipping hibernate.');

            return false;
        }

        try {
            $needed = max(1, (int) $server->allocations()->count());
            $allocationIds = $this->pickAllocations($limboNode, $needed);

            if ($allocationIds === []) {
                Log::warning("plugged-host-limbo: limbo node has no free allocations for server {$server->id}.");

                return false;
            }

            $originAllocations = $server->allocations()->pluck('id')->map(fn ($id) => (int) $id)->all();

            ServerLimboState::query()->updateOrCreate(
                ['server_id' => $server->getKey()],
                [
                    'state' => 'limbo',
                    'limbo_at' => now(),
                    'origin_node_id' => $server->node_id,
                    'origin_allocation_id' => $server->allocation_id,
                    'origin_allocations' => $originAllocations,
                ],
            );

            $primary = (int) array_shift($allocationIds);
            app(TransferServerService::class)->handle($server, (int) $limboNode->getKey(), $primary, $allocationIds);

            // Park it: Suspended makes isInConflictState() true, greys the server out and refuses
            // power actions with no new code.
            $server->forceFill(['status' => ServerState::Suspended->value])->saveQuietly();

            Activity::event('server:limbo.hibernate')->subject($server)->property('limbo_node', $limboNode->getKey())->log();

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Restore a limbo server: charge the ransom (before the transfer), move it back toward its
     * origin, unsuspend. Refund if the transfer is refused. Owner-only is enforced by the page.
     *
     * @return array{ok: bool, reason?: string, cost?: int}
     */
    public function restore(mixed $user, Server $server): array
    {
        if (!$this->tableReady()) {
            return ['ok' => false, 'reason' => 'unavailable'];
        }

        $state = $this->state($server);

        if ($state === null || !in_array($state->state, ['limbo', 'restoring'], true)) {
            return ['ok' => false, 'reason' => 'not_in_limbo'];
        }

        $cost = $this->cost($state);

        // Charge first, via economy by class-string. spend() returns false (not throws) on an
        // insufficient balance and re-reads under a lock, so a stale button cannot overdraw.
        if (!$this->spendTokens($user, $cost)) {
            return ['ok' => false, 'reason' => 'insufficient_tokens', 'cost' => $cost];
        }

        $state->update(['state' => 'restoring', 'restore_cost' => $cost]);

        try {
            $node = $this->originNode($state) ?? $this->limboNode();
            $needed = max(1, count($state->origin_allocations ?? []) ?: 1);
            $allocationIds = $node ? $this->pickAllocations($node, $needed) : [];

            if ($node === null || $allocationIds === []) {
                $this->refundTokens($user, $cost);
                $state->update(['state' => 'limbo']);

                return ['ok' => false, 'reason' => 'no_destination', 'cost' => $cost];
            }

            // Unsuspend before transfer so validateTransferState() permits it; re-suspend on
            // failure is handled by the catch restoring the limbo marker.
            $server->forceFill(['status' => null])->saveQuietly();

            $primary = (int) array_shift($allocationIds);
            app(TransferServerService::class)->handle($server->refresh(), (int) $node->getKey(), $primary, $allocationIds);

            $state->update(['state' => 'restored', 'restored_at' => now()]);

            Activity::event('server:limbo.restore')->subject($server)->property('cost', $cost)->log();

            return ['ok' => true, 'cost' => $cost];
        } catch (Throwable $e) {
            report($e);
            $this->refundTokens($user, $cost);
            $server->forceFill(['status' => ServerState::Suspended->value])->saveQuietly();
            $state->update(['state' => 'limbo']);

            return ['ok' => false, 'reason' => 'transfer_failed', 'cost' => $cost];
        }
    }

    // --- seams to storage + economy -----------------------------------------

    private function limboNode()
    {
        $storage = 'PluggedHost\\Storage\\Services\\StorageService';

        if (class_exists($storage)) {
            try {
                return app($storage)->limboNode();
            } catch (Throwable) {
                //
            }
        }

        return null;
    }

    private function originNode(ServerLimboState $state)
    {
        return $state->origin_node_id ? \App\Models\Node::query()->find($state->origin_node_id) : null;
    }

    private function spendTokens(mixed $user, int $cost): bool
    {
        $service = 'PluggedHost\\Economy\\Services\\TokenService';

        if (!class_exists($service)) {
            return false;
        }

        try {
            return (bool) app($service)->spend($user, $cost, 'limbo_restore', 'Limbo restore', ['reason' => 'limbo restore']);
        } catch (Throwable) {
            return false;
        }
    }

    private function refundTokens(mixed $user, int $cost): void
    {
        $service = 'PluggedHost\\Economy\\Services\\TokenService';

        if (!class_exists($service)) {
            return;
        }

        try {
            app($service)->add($user, $cost, 'limbo_restore_refund', 'Limbo restore refund', ['reason' => 'limbo restore refund']);
        } catch (Throwable) {
            //
        }
    }

    /** @return array<int, int> */
    private function pickAllocations($node, int $needed): array
    {
        try {
            $free = Allocation::query()
                ->where('node_id', $node->getKey())
                ->whereNull('server_id')
                ->orderBy('ip')->orderBy('port')
                ->get(['id', 'ip']);
        } catch (Throwable) {
            return [];
        }

        foreach ($free->groupBy('ip') as $group) {
            if ($group->count() >= $needed) {
                return $group->take($needed)->pluck('id')->map(fn ($id) => (int) $id)->all();
            }
        }

        return [];
    }
}
