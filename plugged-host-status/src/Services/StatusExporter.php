<?php

namespace PluggedHost\Status\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use JsonException;
use PluggedHost\Status\Models\StatusComponentOverride;
use PluggedHost\Status\Models\StatusDailyUptime;
use PluggedHost\Status\Models\StatusIncident;
use PluggedHost\Status\Models\StatusNodeSetting;
use PluggedHost\Status\Models\StatusSnapshot;
use RuntimeException;

class StatusExporter
{
    /** Logged-once guard so a missing export directory does not spam the log every minute. */
    private static bool $missingRootLogged = false;

    public function __construct(private readonly StatusHealthEvaluator $health) {}

    /** @throws JsonException */
    public function export(): void
    {
        // WP15: the pipeline still collects and stores snapshots on a box with no static-site
        // directory. Export degrades to a logged-once no-op rather than fataling — the house
        // pattern TokenService/LimboService use for their own missing tables.
        if ($this->exportRoot() === null) {
            return;
        }

        $settings = StatusNodeSetting::query()
            ->where('enabled', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $incidents = StatusIncident::query()
            ->with('updates')
            ->latest('started_at')
            ->limit(50)
            ->get();

        $current = $this->currentPayload($settings, $incidents);
        $history = $this->historyPayload($settings);
        $incidentPayload = $this->incidentPayload($incidents, $settings);

        $this->atomicWrite('data/current.json', $this->json($current));
        $this->atomicWrite('data/history.json', $this->json($history));
        $this->atomicWrite('data/incidents.json', $this->json($incidentPayload));
        $rssContent = $this->rss($incidentPayload['incidents']);
        $this->atomicWrite('data/feed.xml', $rssContent);
        $this->atomicWrite('feed.xml', $rssContent);
    }

    /** @param Collection<int, StatusNodeSetting> $settings @param Collection<int, StatusIncident> $incidents @return array<string, mixed> */
    private function currentPayload(Collection $settings, Collection $incidents): array
    {
        $latestEndpoints = StatusSnapshot::query()
            ->whereIn('component_key', ['site', 'panel'])
            ->latest('checked_at')
            ->get()
            ->unique('component_key')
            ->keyBy('component_key');

        $components = [
            'site' => ['key' => 'site', 'label' => 'Website', 'status' => $latestEndpoints->get('site')?->status ?? 'unknown'],
            'panel' => ['key' => 'panel', 'label' => 'Panel', 'status' => $latestEndpoints->get('panel')?->status ?? 'unknown'],
        ];

        foreach ($settings as $setting) {
            $components["node:{$setting->public_id}"] = [
                'key' => "node:{$setting->public_id}",
                'label' => $setting->display_name,
                'status' => $setting->status,
            ];
        }

        $activeIncidents = $incidents->where('status', '!=', 'resolved');
        foreach ($activeIncidents as $incident) {
            $override = match ($incident->impact) {
                'critical' => 'major_outage',
                'major' => 'partial_outage',
                'maintenance' => 'maintenance',
                default => 'degraded',
            };
            $affected = $incident->affected_components ?: ['all'];

            foreach ($components as $key => &$component) {
                if ((in_array('all', $affected, true) || in_array($key, $affected, true)) && $this->health->priority($override) > $this->health->priority($component['status'])) {
                    $component['status'] = $override;
                }
            }
            unset($component);
        }

        // Manual component controls (A5) run last and win outright — an operator forcing a
        // component's status from the panel is the final word over both live probes and
        // incident-derived status. An optional public note rides along in current.json.
        foreach (StatusComponentOverride::query()->active()->get() as $control) {
            foreach ($components as $key => &$component) {
                if ($control->component_key === 'all' || $control->component_key === $key) {
                    $component['status'] = $control->status;
                    if (filled($control->note)) {
                        $component['note'] = $control->note;
                    }
                }
            }
            unset($component);
        }

        $nodes = [];
        $global = [
            'reporting_nodes' => 0, 'total_nodes' => $settings->count(),
            'cpu_used' => 0.0, 'cpu_total' => 0, 'memory_used' => 0, 'memory_total' => 0,
            'storage_used' => 0, 'storage_total' => 0,
        ];

        foreach ($settings as $setting) {
            $metrics = $setting->last_metrics ?: $this->emptyMetrics();
            foreach (['cpu_used', 'cpu_total', 'memory_used', 'memory_total', 'storage_used', 'storage_total'] as $field) {
                $global[$field] += $metrics[$field] ?? 0;
            }
            if ($setting->last_reported_at?->isAfter(now()->subMinutes(3)) && $setting->status !== 'offline') {
                $global['reporting_nodes']++;
            }

            $nodes[] = [
                'label' => $setting->display_name,
                'status' => $components["node:{$setting->public_id}"]['status'],
                'last_reported_at' => $setting->last_reported_at?->toIso8601String(),
                // Surfaced flat for the status page's conditional latency stat; null when the
                // node has never reported (older rows) so the frontend can omit the row.
                'latency_ms' => isset($metrics['response_time_ms']) ? (int) $metrics['response_time_ms'] : null,
                'metrics' => $metrics,
            ];
        }
        $global['cpu_used'] = round($global['cpu_used'], 2);

        $componentValues = array_values($components);
        $overallStatus = $this->overallStatus($componentValues, $settings->count());

        return [
            'schema_version' => 1,
            'generated_at' => now()->toIso8601String(),
            'overall' => [
                'status' => $overallStatus,
                'label' => $this->label($overallStatus),
                'message' => $overallStatus === 'operational'
                    ? 'All monitored systems are reporting normally.'
                    : 'One or more monitored systems require attention.',
            ],
            'components' => array_map(fn (array $component) => array_filter([
                'label' => $component['label'],
                'status' => $component['status'],
                'note' => $component['note'] ?? null,
            ], fn ($value) => $value !== null), $componentValues),
            'global' => $global,
            'nodes' => $nodes,
        ];
    }

    /** @param Collection<int, StatusNodeSetting> $settings @return array<string, mixed> */
    private function historyPayload(Collection $settings): array
    {
        $recentSnapshots = StatusSnapshot::query()
            ->where('component_type', 'node')
            ->where('checked_at', '>=', now()->subMinutes(60))
            ->oldest('checked_at')
            ->get()
            ->groupBy('component_key');
        $recent = [];

        foreach ($settings as $setting) {
            // times[] runs parallel to cpu[]/memory[] so the status page can plot on a real
            // time axis (unreachable minutes are skipped, leaving gaps) and label each point.
            $series = ['label' => $setting->display_name, 'times' => [], 'cpu' => [], 'memory' => []];
            foreach ($recentSnapshots->get($setting->public_id, collect()) as $snapshot) {
                if (($snapshot->metrics['reachable'] ?? false) !== true) {
                    continue;
                }
                $series['times'][] = $snapshot->checked_at->toIso8601String();
                $series['cpu'][] = (float) ($snapshot->metrics['cpu_used'] ?? 0);
                $series['memory'][] = (int) ($snapshot->metrics['memory_used'] ?? 0);
            }
            $recent[] = $series;
        }

        $componentLabels = ['site' => 'Website', 'panel' => 'Panel'];
        foreach ($settings as $setting) {
            $componentLabels[$setting->public_id] = $setting->display_name;
        }

        $daily = StatusDailyUptime::query()
            ->where('date', '>=', now()->subDays(89)->toDateString())
            ->get()
            ->groupBy('component_key');
        $uptime = [];

        foreach ($componentLabels as $key => $label) {
            $records = $daily->get($key, collect())->keyBy(fn (StatusDailyUptime $row) => $row->date->toDateString());
            $days = [];
            $checks = 0;
            $operational = 0;

            for ($date = now()->subDays(89)->startOfDay(); $date->lte(now()->startOfDay()); $date->addDay()) {
                $record = $records->get($date->toDateString());
                $day = ['date' => $date->toDateString(), 'status' => $record?->worst_status ?? 'unknown'];
                if ($record !== null) {
                    // Lets the status page weight its 7/30/90-day windows by checks, the same
                    // way uptime_percent is, instead of scoring a day with one bad minute as 0%.
                    $day['total_checks'] = (int) $record->total_checks;
                    $day['operational_checks'] = (int) $record->operational_checks;
                }
                $days[] = $day;
                $checks += (int) ($record?->total_checks ?? 0);
                $operational += (int) ($record?->operational_checks ?? 0);
            }

            $uptime[] = [
                'label' => $label,
                'uptime_percent' => $checks > 0 ? round(($operational / $checks) * 100, 3) : 0,
                'days' => $days,
            ];
        }

        return ['schema_version' => 1, 'generated_at' => now()->toIso8601String(), 'recent' => $recent, 'uptime' => $uptime];
    }

    /** @param Collection<int, StatusIncident> $incidents @param Collection<int, StatusNodeSetting> $settings @return array<string, mixed> */
    private function incidentPayload(Collection $incidents, Collection $settings): array
    {
        $labels = ['all' => 'All systems', 'site' => 'Website', 'panel' => 'Panel'];
        foreach ($settings as $setting) {
            $labels["node:{$setting->public_id}"] = $setting->display_name;
        }

        return [
            'schema_version' => 1,
            'generated_at' => now()->toIso8601String(),
            'incidents' => $incidents->map(fn (StatusIncident $incident) => [
                'title' => $incident->title,
                'impact' => $incident->impact,
                'status' => $incident->status,
                'components' => collect($incident->affected_components ?: ['all'])->map(fn ($key) => $labels[$key] ?? 'Service component')->values()->all(),
                'started_at' => $incident->started_at?->toIso8601String(),
                'resolved_at' => $incident->resolved_at?->toIso8601String(),
                'updates' => $incident->updates->map(fn ($update) => [
                    'status' => $update->status,
                    'message' => $update->message,
                    'published_at' => $update->published_at?->toIso8601String(),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /** @param array<int, array<string, mixed>> $components */
    private function overallStatus(array $components, int $nodeCount): string
    {
        $siteOrPanelOffline = collect($components)->take(2)->contains(fn (array $component) => in_array($component['status'], ['offline', 'major_outage'], true));
        if ($siteOrPanelOffline || collect($components)->contains(fn (array $component) => $component['status'] === 'major_outage')) {
            return 'major_outage';
        }

        $nodes = collect($components)->slice(2);
        if ($nodeCount > 0 && $nodes->every(fn (array $component) => $component['status'] === 'offline')) {
            return 'major_outage';
        }
        if (collect($components)->contains(fn (array $component) => in_array($component['status'], ['offline', 'partial_outage'], true))) {
            return 'partial_outage';
        }
        if (collect($components)->contains(fn (array $component) => $component['status'] === 'degraded')) {
            return 'degraded';
        }
        if (collect($components)->contains(fn (array $component) => $component['status'] === 'maintenance')) {
            return 'maintenance';
        }
        if (collect($components)->contains(fn (array $component) => $component['status'] === 'unknown')) {
            return 'unknown';
        }

        return 'operational';
    }

    /** @return array<string, int|float> */
    private function emptyMetrics(): array
    {
        return ['cpu_used' => 0.0, 'cpu_total' => 0, 'memory_used' => 0, 'memory_total' => 0, 'storage_used' => 0, 'storage_total' => 0];
    }

    private function label(string $status): string
    {
        return match ($status) {
            'operational' => 'All systems operational',
            'degraded' => 'Degraded performance',
            'maintenance' => 'Maintenance in progress',
            'partial_outage' => 'Partial system outage',
            'major_outage' => 'Major system outage',
            default => 'Status unknown',
        };
    }

    /** @param array<int, array<string, mixed>> $incidents */
    private function rss(array $incidents): string
    {
        // WP1 open-source prep: the public status URL is a neutral config default, set via
        // STATUS_RSS_BASE_URL in .env here, so a published copy carries no plugged.host identity.
        $base = rtrim((string) config('plugged-host-status.rss_base_url'), '/') . '/';

        $items = '';
        foreach ($incidents as $incident) {
            $description = $incident['updates'][0]['message'] ?? $incident['status'];
            $date = Carbon::parse($incident['started_at'] ?? now())->toRssString();
            $items .= '<item><title>' . e($incident['title']) . '</title><link>' . e($base) . '</link><guid isPermaLink="false">plugged-host-' . sha1($incident['title'] . $date) . '</guid><pubDate>' . $date . '</pubDate><description>' . e($description) . '</description></item>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>plugged.host incidents</title><link>' . e($base) . '</link><description>Operational updates for plugged.host.</description><lastBuildDate>' . now()->toRssString() . '</lastBuildDate>' . $items . '</channel></rss>';
    }

    /** @param array<string, mixed> $payload @throws JsonException */
    private function json(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The configured export root, or null when it is unset or unwritable.
     *
     * A blank STATUS_EXPORT_PATH (or a path the panel cannot create/write) is logged once and
     * then treated as "no static site attached", so the collector keeps running.
     */
    private function exportRoot(): ?string
    {
        $root = rtrim((string) config('plugged-host-status.export_path'), '/');

        if ($root === '') {
            $this->logMissingRootOnce('STATUS_EXPORT_PATH is not set');

            return null;
        }

        if (!File::isDirectory($root)) {
            try {
                File::ensureDirectoryExists($root, 0755, true);
            } catch (\Throwable $e) {
                $this->logMissingRootOnce("status export path is not creatable: $root");

                return null;
            }
        }

        if (!is_writable($root)) {
            $this->logMissingRootOnce("status export path is not writable: $root");

            return null;
        }

        self::$missingRootLogged = false;

        return $root;
    }

    private function logMissingRootOnce(string $reason): void
    {
        if (self::$missingRootLogged) {
            return;
        }

        self::$missingRootLogged = true;
        Log::warning("plugged-host-status: status export skipped — $reason");
    }

    private function atomicWrite(string $relativePath, string $contents): void
    {
        $root = $this->exportRoot();

        if ($root === null) {
            return;
        }

        $path = "$root/$relativePath";
        $directory = dirname($path);
        File::ensureDirectoryExists($directory, 0755, true);
        $temporary = tempnam($directory, '.status-');

        if ($temporary === false || file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $path)) {
            if (is_string($temporary) && file_exists($temporary)) {
                @unlink($temporary);
            }
            throw new RuntimeException("Unable to atomically export status file: $relativePath");
        }

        chmod($path, 0644);
    }
}
