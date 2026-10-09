# Changelog

## Unreleased (2.1)

- **A full node is not a degraded one.** Only CPU at or above the threshold (now 95% by
  default) for five checks marks a reachable node degraded; memory and disk use no longer do
  (79% of the week's "degraded performance" was nodes with RAM at 90%). A single missed check
  no longer flips a node to degraded (two in a row do, three mean offline), and one blip no
  longer leaves it "recovering".

- **One "Status page" entry in the admin sidebar** (`Filament\Admin\Clusters\StatusPage`,
  `/admin/status`) with Incidents, Nodes and Components as tabs, instead of three sidebar rows.
  Their URLs moved under `/admin/status/…`.

## 1.2.1

- Version bump to 1.2.1 (plugged.host 2.1).

## 1.2.0

- **New plugin**, extracted from `plugged-host-core` in the v2 re-division. Contains the status
  collect/evaluate/export/prune pipeline, the incident and node admin resources, and the five
  `Status*` models — all previously in core.
- Config moved from `plugged-host-core.status.*` to `plugged-host-status.*`; env variable names
  are unchanged (`STATUS_*`).
- Export now degrades to a logged-once no-op when `STATUS_EXPORT_PATH` is unset or unwritable,
  so the pipeline runs standalone on a box with no static site.
- Per-node daemon-probe latency is now recorded in `status_snapshots.metrics.response_time_ms`.
- New `NodeLatency` and `NodeHealth` service seams for `plugged-host-servers` (WP3) and
  `plugged-host-storage` (WP9).
- Public status URL in the RSS feed is now a neutral config default (`STATUS_RSS_BASE_URL`).
