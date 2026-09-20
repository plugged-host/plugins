<?php

namespace PluggedHost\Status\Services;

use App\Models\Node;
use App\Repositories\Daemon\DaemonSystemRepository;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PluggedHost\Status\Models\StatusDailyUptime;
use PluggedHost\Status\Models\StatusNodeSetting;
use PluggedHost\Status\Models\StatusSnapshot;
use Throwable;

class StatusCollector
{
    public function __construct(private readonly StatusHealthEvaluator $health) {}

    public function collect(): void
    {
        $this->syncNodes();
        $this->collectEndpoint(
            'site',
            (string) config('plugged-host-status.site_health_url'),
            (string) config('plugged-host-status.site_health_host'),
        );
        $this->collectEndpoint('panel', (string) config('plugged-host-status.panel_health_url'));

        StatusNodeSetting::query()
            ->with('node')
            ->where('enabled', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->each(fn (StatusNodeSetting $setting) => $this->collectNode($setting));
    }

    public function prune(): void
    {
        $cutoff = now()->subDays((int) config('plugged-host-status.history_days', 90));
        StatusSnapshot::query()->where('checked_at', '<', $cutoff)->delete();
        StatusDailyUptime::query()->where('date', '<', $cutoff->toDateString())->delete();
    }

    private function syncNodes(): void
    {
        $position = 0;
        Node::query()->orderBy('id')->each(function (Node $node) use (&$position): void {
            $position++;
            $setting = StatusNodeSetting::query()->firstOrCreate(
                ['node_id' => $node->getKey()],
                [
                    'public_id' => (string) Str::uuid(),
                    'display_name' => $node->name,
                    'enabled' => $node->public,
                    'sort_order' => $position,
                ],
            );

            // The public label always mirrors the Pelican node's admin-set name — an operator
            // renames a node once, on the node itself, and the status page follows. enabled and
            // sort_order stay admin-owned (the "Public status nodes" resource), so leave them.
            if ($setting->display_name !== $node->name) {
                $setting->display_name = $node->name;
                $setting->save();
            }
        });
    }

    private function collectEndpoint(string $key, string $url, ?string $host = null): void
    {
        $started = microtime(true);
        $reachable = false;
        $httpStatus = null;

        try {
            /** @var Response $response */
            $response = Http::connectTimeout(3)
                ->timeout(5)
                ->withUserAgent('plugged.host status monitor')
                ->when(filled($host), fn ($request) => $request->withHeaders(['Host' => $host]))
                ->get($url);
            $httpStatus = $response->status();
            $reachable = $response->successful();
        } catch (Throwable) {
        }

        $recent = StatusSnapshot::query()
            ->where('component_key', $key)
            ->latest('checked_at')
            ->limit(3)
            ->get()
            ->pluck('metrics')
            ->filter(fn ($metrics) => is_array($metrics))
            ->values()
            ->all();

        $this->recordSnapshot('endpoint', $key, $this->health->endpoint($reachable, $recent), [
            'reachable' => $reachable,
            'http_status' => $httpStatus,
            'response_time_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }

    private function collectNode(StatusNodeSetting $setting): void
    {
        $node = $setting->node;
        if (!$node instanceof Node) {
            return;
        }

        $reachable = false;
        $metrics = null;
        // Time the daemon probe so NodeLatency (WP3) has a real per-node "panel → node" figure.
        // No migration needed — it rides in the existing status_snapshots.metrics JSON (fact 7).
        $started = microtime(true);
        $responseMs = null;

        try {
            $statistics = Http::daemon($node)
                ->connectTimeout(2)
                ->timeout(3)
                ->get('/api/system/utilization')
                ->throw()
                ->json();
            $responseMs = (int) round((microtime(true) - $started) * 1000);

            $system = (new DaemonSystemRepository())
                ->setNode($node)
                ->getSystemInformation();

            $threads = max(0, (int) ($system['cpu_count'] ?? 0));
            if ($threads > 0 && (int) ($statistics['memory_total'] ?? 0) > 0) {
                $metrics = [
                    'cpu_used' => round(max(0, (float) ($statistics['cpu_percent'] ?? 0)) * $threads, 2),
                    'cpu_total' => $threads * 100,
                    'memory_used' => max(0, (int) ($statistics['memory_used'] ?? 0)),
                    'memory_total' => max(0, (int) ($statistics['memory_total'] ?? 0)),
                    'storage_used' => max(0, (int) ($statistics['disk_used'] ?? 0)),
                    'storage_total' => max(0, (int) ($statistics['disk_total'] ?? 0)),
                    'response_time_ms' => $responseMs,
                ];
                $reachable = true;
            }
        } catch (Throwable) {
        }

        $underPressure = $metrics !== null && $this->health->underResourcePressure($metrics);
        $state = $this->health->node($setting, $reachable, $underPressure, $node->maintenance_mode);
        $setting->fill($state);

        if ($reachable && $metrics !== null) {
            $setting->last_metrics = $metrics;
            $setting->last_reported_at = now();
        }

        $setting->save();
        $snapshotMetrics = ['reachable' => $reachable];
        if ($reachable && $metrics !== null) {
            $snapshotMetrics += $metrics;
        }
        if ($responseMs !== null) {
            $snapshotMetrics['response_time_ms'] = $responseMs;
        }

        $this->recordSnapshot('node', $setting->public_id, $state['status'], $snapshotMetrics);
    }

    /** @param array<string, mixed> $metrics */
    private function recordSnapshot(string $type, string $key, string $status, array $metrics): void
    {
        $checkedAt = now();
        StatusSnapshot::query()->create([
            'component_type' => $type,
            'component_key' => $key,
            'status' => $status,
            'metrics' => $metrics,
            'checked_at' => $checkedAt,
        ]);

        $daily = StatusDailyUptime::query()->firstOrNew([
            'component_key' => $key,
            'date' => $checkedAt->toDateString(),
        ]);
        $daily->component_type = $type;
        $daily->total_checks = (int) $daily->total_checks + 1;
        $daily->operational_checks = (int) $daily->operational_checks + ($status === 'operational' ? 1 : 0);

        if (!$daily->exists || $this->health->priority($status) > $this->health->priority((string) $daily->worst_status)) {
            $daily->worst_status = $status;
        }

        $daily->save();
    }
}
