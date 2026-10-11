<?php

namespace PluggedHost\Abuse\Services;

use App\Enums\SuspendAction;
use App\Models\Node;
use App\Models\Server;
use App\Services\Servers\SuspensionService;
use Illuminate\Support\Facades\Cache;
use PluggedHost\Abuse\Jobs\ScanServersForAbuseJob;
use PluggedHost\Abuse\Models\AbuseFinding;
use PluggedHost\Core\Support\SuspensionReason;
use PluggedHost\Core\Support\SystemServers;
use Throwable;

/**
 * Where every check's findings end up: recorded (one row per server + rule), acted on, announced.
 *
 * ABUSE_MODE decides the action. `suspend` suspends a server with any critical finding that is
 * not dismissed, through upstream's SuspensionService (Wings stops the server), with the
 * summaries as the reason its owner reads. `report` records and alerts but suspends nothing.
 * `off` records nothing. Review findings never suspend.
 *
 * Dismissing a finding is the one override: it stays dismissed when it is seen again. Unsuspending
 * a server without dismissing gets it suspended again on the next hit, which is the point.
 */
final class AbuseEnforcer
{
    public const MODES = ['suspend', 'report', 'off'];

    public function __construct(
        private readonly SuspensionService $suspensions,
        private readonly AbuseAlerts $alerts,
    ) {}

    public static function mode(): string
    {
        $mode = (string) config('plugged-host-abuse.mode', 'report');

        return in_array($mode, self::MODES, true) ? $mode : 'report';
    }

    /**
     * @param  array<int, Finding>  $findings
     * @param  bool  $enforce  false for a manual run without --suspend: record only
     * @param  bool  $sweep  look at the owner's other servers after a new critical finding
     * @return array{recorded: int, suspended: bool, critical: array<int, string>, error: string|null}
     */
    public function handle(?Server $server, array $findings, bool $enforce, ?Node $node = null, bool $sweep = true): array
    {
        $result = ['recorded' => 0, 'suspended' => false, 'critical' => [], 'error' => null];

        if ($findings === [] || self::mode() === 'off' || !AbuseFinding::ready()) {
            return $result;
        }

        $now = now();
        $fresh = [];
        $actionable = [];

        foreach ($findings as $finding) {
            $row = AbuseFinding::query()->firstOrNew([
                'fingerprint' => AbuseFinding::fingerprintFor($server?->id, $server ? null : $node?->id, $finding->rule),
            ]);

            $reopened = !$row->exists || $row->status === AbuseFinding::RESOLVED;

            if (!$row->exists) {
                $row->forceFill([
                    'server_id' => $server?->id,
                    'node_id' => $server?->node_id ?? $node?->id,
                    'first_seen_at' => $now,
                    'hits' => 0,
                ]);
            }

            if ($reopened) {
                $row->forceFill(['status' => AbuseFinding::OPEN, 'action' => null, 'actioned_at' => null, 'resolved_by' => null, 'resolved_at' => null]);
            }

            $row->forceFill([
                'rule' => $finding->rule,
                'category' => $finding->category,
                'severity' => $finding->severity,
                'source' => $finding->source,
                'summary' => $finding->summary,
                'evidence' => $finding->evidence,
                'last_seen_at' => $now,
                'hits' => $row->hits + 1,
            ])->save();

            $result['recorded']++;

            if ($row->status === AbuseFinding::DISMISSED) {
                continue;
            }

            if ($reopened) {
                $fresh[] = $row;
            }

            if ($finding->isCritical()) {
                $actionable[] = $row;
                $result['critical'][] = $finding->summary;
            }
        }

        $mode = self::mode();

        if ($server !== null && $actionable !== [] && $enforce && $mode === 'suspend') {
            $this->suspend($server, $actionable, $result);
        }

        $freshCritical = array_values(array_filter($fresh, fn (AbuseFinding $row) => $row->severity === Finding::CRITICAL));

        if ($result['suspended'] || $freshCritical !== []) {
            $this->alerts->send($server, $node, $result['suspended'] ? $actionable : $freshCritical, $result['suspended'], $mode);
        }

        if ($sweep && $server !== null && array_filter($freshCritical, fn (AbuseFinding $row) => $row->server_id !== null) !== []) {
            $this->sweepOwner($server, $enforce);
        }

        return $result;
    }

    /**
     * Suspend for the critical findings. The node agent and the scheduled scan can catch the same
     * server within seconds of each other, each holding a copy that still says "running", so the
     * suspension happens under a per-server lock against a fresh status. A server that is already
     * suspended only gets its findings marked as handled.
     *
     * @param  array<int, AbuseFinding>  $actionable
     * @param  array{recorded: int, suspended: bool, critical: array<int, string>, error: string|null}  $result
     */
    private function suspend(Server $server, array $actionable, array &$result): void
    {
        $lock = Cache::lock("ph-abuse:suspend:{$server->id}", 60);

        if (!$lock->block(15)) {
            $result['error'] = 'another check is suspending this server';

            return;
        }

        try {
            $server->refresh();

            if ($server->isSuspended()) {
                foreach ($actionable as $row) {
                    if ($row->status === AbuseFinding::OPEN) {
                        $row->forceFill(['status' => AbuseFinding::ACTIONED, 'action' => 'already suspended', 'actioned_at' => now()])->save();
                    }
                }

                return;
            }

            SuspensionReason::with(
                ['source' => SuspensionReason::SOURCE_ABUSE_SCAN, 'reasons' => array_map(fn (AbuseFinding $row) => $row->summary, $actionable)],
                fn () => $this->suspensions->handle($server, SuspendAction::Suspend),
            );

            foreach ($actionable as $row) {
                $row->forceFill(['status' => AbuseFinding::ACTIONED, 'action' => 'suspended', 'actioned_at' => now()])->save();
            }

            $result['suspended'] = true;
        } catch (Throwable $e) {
            // A server mid-transfer cannot be suspended; the finding stays open and the next
            // check tries again.
            $result['error'] = $e->getMessage();
        } finally {
            $lock->release();
        }
    }

    /**
     * Someone caught mining on one server usually runs the same kit on the rest: check the owner's
     * other servers now instead of at the next full scan. Once an hour per owner, queued.
     */
    private function sweepOwner(Server $server, bool $enforce): void
    {
        try {
            // A devbox belongs to the system user: sweep the person it was made for instead.
            $person = SystemServers::personId($server);
            if ($person === null || !Cache::add("ph-abuse:sweep:{$person}", 1, 3600)) {
                return;
            }

            $ids = Server::query()
                ->where('owner_id', $person)
                ->whereKeyNot($server->id)
                ->pluck('id')
                ->all();

            if ($ids !== []) {
                ScanServersForAbuseJob::dispatch($ids, $enforce);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
