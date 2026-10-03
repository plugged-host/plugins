<?php

namespace PluggedHost\Core\Console\Commands;

use App\Models\Node;
use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use PluggedHost\Core\Models\AbuseFinding;
use PluggedHost\Core\Services\Abuse\AbuseEnforcer;
use PluggedHost\Core\Services\Abuse\AbuseScanner;
use PluggedHost\Core\Services\Abuse\ConsoleInspector;
use PluggedHost\Core\Services\Abuse\Finding;
use Throwable;

/**
 * Every minute: which servers are running at their CPU limit. A miner pins its CPU, and the three
 * on fr3 on 2026-10-03 sat at 411-417% of a 400% limit for hours.
 *
 * One Wings call per node (GET /api/servers carries every server's live usage). A server at
 * ABUSE_CPU_PERCENT of its limit gets its console read straight away (once per 10 minutes); one
 * that stays there for ABUSE_CPU_MINUTES gets a review finding and the full check (files,
 * startup, console; once per 2 hours), which is what suspends a miner. Full CPU alone never
 * suspends: a Minecraft server generating chunks looks the same.
 */
class AbuseWatchCommand extends Command
{
    protected $signature = 'plugged-host:abuse-watch';

    protected $description = 'Watch for servers pinned at their CPU limit and check them for abuse.';

    private const CONSOLE_EVERY = 600;

    private const FULL_CHECK_EVERY = 7200;

    public function handle(AbuseScanner $scanner, ConsoleInspector $console, AbuseEnforcer $enforcer): int
    {
        if (AbuseEnforcer::mode() === 'off' || !AbuseFinding::ready()) {
            return self::SUCCESS;
        }

        $percent = max(50, min(100, (int) config('plugged-host-core.abuse.cpu_percent', 90)));
        $minutes = max(5, min(1440, (int) config('plugged-host-core.abuse.cpu_minutes', 20)));

        foreach (Node::query()->get() as $node) {
            try {
                $list = (new DaemonServerRepository())->setNode($node)->getHttpClient()
                    ->connectTimeout(3)
                    ->timeout(10)
                    ->get('/api/servers')
                    ->throw()
                    ->json();
            } catch (Throwable) {
                continue;
            }

            if (!is_array($list)) {
                continue;
            }

            $servers = Server::query()->where('node_id', $node->id)->get()->keyBy('uuid');

            foreach ($list as $entry) {
                $server = $servers->get((string) ($entry['configuration']['uuid'] ?? ''));
                $limit = (int) ($entry['configuration']['build']['cpu_limit'] ?? 0);

                if ($server === null || $limit <= 0 || ($entry['is_suspended'] ?? false) || $server->isSuspended()) {
                    continue;
                }

                try {
                    $this->sample($server, (float) ($entry['utilization']['cpu_absolute'] ?? 0), $limit, $percent, $minutes, $scanner, $console, $enforcer);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        return self::SUCCESS;
    }

    private function sample(Server $server, float $cpu, int $limit, int $percent, int $minutes, AbuseScanner $scanner, ConsoleInspector $console, AbuseEnforcer $enforcer): void
    {
        $key = "ph-abuse:cpu:{$server->id}";
        $threshold = $limit * $percent / 100;
        $state = Cache::get($key);

        if ($cpu < $threshold) {
            // A little below the line keeps the streak (a miner wobbles); well below ends it.
            if ($state !== null && $cpu < $threshold * 0.75) {
                Cache::forget($key);
            }

            return;
        }

        $now = time();
        $state = is_array($state) ? $state : ['since' => $now, 'samples' => 0, 'sum' => 0.0, 'console_at' => 0, 'full_at' => 0];
        $state['samples']++;
        $state['sum'] += $cpu;

        if ($now - $state['console_at'] >= self::CONSOLE_EVERY) {
            $state['console_at'] = $now;
            $findings = $console->inspect($server) ?? [];

            if ($findings !== []) {
                $enforcer->handle($server, $findings, true);
            }
        }

        $pinnedFor = intdiv($now - $state['since'], 60);

        if ($pinnedFor >= $minutes) {
            $average = $state['sum'] / max(1, $state['samples']);

            $enforcer->handle($server, [new Finding(
                rule: 'cpu.pinned',
                category: 'resources',
                severity: Finding::REVIEW,
                source: 'cpu',
                summary: sprintf('CPU at %d%% of its %d%% limit for %d minutes', round($average * 100 / $limit), $limit, $pinnedFor),
                evidence: ['average_cpu' => round($average, 1), 'limit' => $limit, 'minutes' => $pinnedFor, 'samples' => $state['samples']],
            )], true);

            if ($now - $state['full_at'] >= self::FULL_CHECK_EVERY) {
                $state['full_at'] = $now;
                $scanner->scan($server, true);
            }
        }

        Cache::put($key, $state, 3600);
    }
}
