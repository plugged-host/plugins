# plugged.host Status

Public status pipeline for a Pelican Panel: once a minute it reads every published node's state
from core's node telemetry, probes up to two HTTP endpoints (the public website and the panel),
evaluates operational / degraded / outage state, and exports the JSON + RSS a static status site
renders. Incidents, maintenance and manual component controls are managed in the admin panel.

## What it does

- **Collect** (`plugged-host:status-collect`, scheduled every minute): reads each published node's
  latest sample from core's `NodeTelemetry` (core probes wings once a minute; this plugin does not
  ask wings itself), probes each configured endpoint over HTTP, stores a `status_snapshots` row
  per component and rolls daily uptime into `status_daily_uptimes`. An endpoint whose health URL
  is blank is not probed at all.
- **Evaluate** (`StatusHealthEvaluator`): maps reachability + CPU pressure + maintenance mode to a
  component status. A node is degraded from the second missed check in a row and offline from the
  third; CPU at or above `STATUS_RESOURCE_THRESHOLD_PERCENT` for five checks marks it degraded (a
  node that is only full on memory or disk stays operational).
- **Export** (`StatusExporter`, also `plugged-host:status-export`): writes
  `data/{current,history,incidents,targets}.json`, `data/feed.xml` and `feed.xml` atomically to
  `STATUS_EXPORT_PATH`. Degrades to a logged-once no-op when the path is unset or unwritable, so
  the collector keeps running on a box with no static site attached. `current.json` carries the
  panel's release (`release`) for the sites' footers; `targets.json` lists each published node's
  label and wings address for an outside prober (such as a status Worker) that keeps checking
  the nodes when the panel is down.
- **Prune** (`plugged-host:status-prune`, daily at 03:17; the collector prunes too): drops
  snapshots and daily rows older than `STATUS_HISTORY_DAYS`.
- **Admin** (one "Status page" entry, `/admin/status`, admin panel only): Incidents, Nodes (which
  nodes are published, their order; the label follows the Pelican node's name) and Components
  (manual controls that pin a component's status, with an optional public note, over probes and
  incidents).

Endpoints with no health URL are left out of `current.json`, `history.json` and the overall
status, so a panel that only publishes its nodes does not show an outage for a probe it never
runs. The RSS channel title and the probe's User-Agent use the panel's `APP_NAME`.

## Config keys / env

All keys are also editable in the plugin's settings tab (written back to `.env`).

| Config (`plugged-host-status.*`) | Env | Default | Meaning |
|---|---|---|---|
| `export_path` | `STATUS_EXPORT_PATH` | `''` | Static-site root; blank disables export |
| `rss_base_url` | `STATUS_RSS_BASE_URL` | `''` | Public status URL for the RSS `<link>` |
| `site_health_url` | `STATUS_SITE_HEALTH_URL` | `''` | Website probe; blank = not probed or listed |
| `site_health_host` | `STATUS_SITE_HEALTH_HOST` | `''` | Host header for the website probe |
| `panel_health_url` | `STATUS_PANEL_HEALTH_URL` | `''` | Panel probe (e.g. `https://panel.example.com/up`); blank = not probed or listed |
| `history_days` | `STATUS_HISTORY_DAYS` | `90` | Retention |
| `resource_threshold_percent` | `STATUS_RESOURCE_THRESHOLD_PERCENT` | `95` | CPU pressure line (five checks in a row) |

## Migrations

- `005_create_status_page_tables.php` — `status_snapshots`, `status_daily_uptimes`,
  `status_incidents`, `status_incident_updates`, `status_node_settings`.
- `006_create_status_component_overrides.php` — `status_component_overrides` (manual controls).

## Extension points it offers

None for other plugins. The exported files are its interface: their shapes (`schema_version`
1) are what the static status page and an outside prober read.

## Extension points it consumes

- `plugged-host-core`: `PluggedHost\Core\Nodes\NodeTelemetry::latest()` for node reachability,
  latency and load (no sample in the last 150 s counts as unreachable), and
  `PluggedHost\Core\Support\Release` for the release in `current.json`.
- Pelican core: `App\Models\Node` (names, FQDN, wings port, maintenance mode).

## Requirements

Pelican Panel `v1.0.0-rc.2` and `plugged-host-core`. No API key.
