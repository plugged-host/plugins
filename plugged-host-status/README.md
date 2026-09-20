# plugged.host Status

Public status pipeline for a Pelican Panel: it probes every node and two HTTP endpoints once a
minute, evaluates operational / degraded / outage state, and exports the JSON + RSS a static
status site renders. It runs standalone — it has no dependency on any other plugged.host plugin.

## What it does

- **Collect** (`plugged-host:status-collect`, scheduled every minute): times each node's wings
  utilisation probe and each endpoint probe, stores a `status_snapshots` row and rolls daily
  uptime into `status_daily_uptimes`.
- **Evaluate** (`StatusHealthEvaluator`): maps reachability + resource pressure + maintenance to
  a component status.
- **Export** (`StatusExporter`): writes `data/{current,history,incidents}.json` and `feed.xml`
  atomically to `STATUS_EXPORT_PATH`. Degrades to a logged-once no-op when the path is unset or
  unwritable, so the collector keeps running on a box with no static site attached.
- **Prune** (`plugged-host:status-prune`, daily): drops snapshots and daily rows older than
  `STATUS_HISTORY_DAYS`.
- **Admin resources**: incident management and per-node display settings (admin panel only).

## Config keys / env

| Config (`plugged-host-status.*`) | Env | Default | Meaning |
|---|---|---|---|
| `export_path` | `STATUS_EXPORT_PATH` | `''` | Static-site root; blank disables export |
| `rss_base_url` | `STATUS_RSS_BASE_URL` | `''` | Public status URL for RSS `<link>` |
| `site_health_url` | `STATUS_SITE_HEALTH_URL` | `http://127.0.0.1/healthz` | Website probe |
| `site_health_host` | `STATUS_SITE_HEALTH_HOST` | `''` | Host header for the website probe |
| `panel_health_url` | `STATUS_PANEL_HEALTH_URL` | `''` | Panel probe |
| `history_days` | `STATUS_HISTORY_DAYS` | `90` | Retention |
| `resource_threshold_percent` | `STATUS_RESOURCE_THRESHOLD_PERCENT` | `90` | Pressure line |

## Migrations

- `005_create_status_page_tables.php` — `status_snapshots`, `status_daily_uptimes`,
  `status_incidents`, `status_incident_updates`, `status_node_settings`.

## Extension points it offers

- `PluggedHost\Status\Services\NodeLatency::medianFor(int $nodeId): ?int` and
  `medianForNodes(array $ids): array` — median panel→node latency from the snapshot history,
  consumed by `plugged-host-servers` (WP3) by class-string.
- `PluggedHost\Status\Services\NodeHealth::consecutiveFailures(int $nodeId): int` — the panel's
  witness for `plugged-host-storage` failover (WP9), consumed by class-string.

## Extension points it consumes

None. Reads Pelican core only (`App\Models\Node`, `Http::daemon()`, `DaemonSystemRepository`).

## Requirements

Pelican Panel `1.0.0-beta38`. No API key. Extracted from `plugged-host-core` in v1.2.0.

> License: pending final choice (see monorepo root).
