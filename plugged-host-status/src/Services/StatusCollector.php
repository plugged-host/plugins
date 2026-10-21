<?php

namespace PluggedHost\Status\Services;

use App\Models\Node;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PluggedHost\Core\Nodes\NodeTelemetry;
use PluggedHost\Status\Models\StatusDailyUptime;
use PluggedHost\Status\Models\StatusNodeSetting;
use PluggedHost\Status\Models\StatusSnapshot;
use Throwable;

class StatusCollector
{
    public function __construct(
        private readonly StatusHealthEvaluator $health,
        private readonly NodeTelemetry $telemetry,
    ) {}

    /**
     * The HTTP endpoints this panel probes, as key => public label. One whose health URL is
     * blank is not probed at all: no snapshots, and the exporter leaves it out of the public
     * files and the overall status (a probe of '' would only ever fail and read as an outage).
     *
     * @return array<string, string>
     */
    public static function endpoints(): array
    {
        return array_filter(
            ['site' => 'Website', 'panel' => 'Panel'],
            fn (string $key) => filled(config("plugged-host-status.{$key}_health_url")),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public function collect(): void
    {
        $this->syncNodes();

        $endpoints = self::endpoints();
        if (isset($endpoints['site'])) {
            $this->collectEndpoint(
                'site',
                (string) config('plugged-host-status.site_health_url'),
                (string) config('plugged-host-status.site_health_host'),
            );
        }
        if (isset($endpoints['panel'])) {
            $this->collectEndpoint('panel', (string) config('plugged-host-status.panel_health_url'));
        }

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
                ->withUserAgent((string) config('app.name') . ' status monitor')
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

        // Core samples every node once a minute (NodeTelemetry); read that rather than asking
        // wings a second time. No sample in the last 150 s counts as unreachable.
        $sample = $this->telemetry->latest((int) $node->getKey());
        $reachable = (bool) ($sample['reachable'] ?? false);
        $responseMs = $reachable && is_int($sample['response_time_ms'] ?? null) ? $sample['response_time_ms'] : null;
        $metrics = null;

        if ($reachable && (int) ($sample['cpu_total'] ?? 0) > 0 && (int) ($sample['memory_total'] ?? 0) > 0) {
            $metrics = [
                'cpu_used' => $sample['cpu_used'],
                'cpu_total' => $sample['cpu_total'],
                'memory_used' => $sample['memory_used'],
                'memory_total' => $sample['memory_total'],
                'storage_used' => $sample['disk_used'],
                'storage_total' => $sample['disk_total'],
                'response_time_ms' => $responseMs,
            ];
        } else {
            // The status page has always counted a node it could not read the load of as down.
            $reachable = false;
        }

        $underPressure = $metrics !== null && $this->health->underResourcePressure($metrics);
        $hostUp = !$reachable && (bool) ($sample['refused'] ?? false);
        $state = $this->health->node($setting, $reachable, $underPressure, $node->maintenance_mode, $hostUp);
        $setting->fill($state);

        if ($reachable && $metrics !== null) {
            $setting->last_metrics = $metrics;
            $setting->last_reported_at = now();
        }

        $setting->save();
        $snapshotMetrics = ['reachable' => $reachable] + ($hostUp ? ['host_up' => true] : []);
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
