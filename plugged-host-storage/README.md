# plugged.host Storage

The storage server: 30-minute delta-mirror replication of every live server, a daily
panel-visible S3 backup, and automatic node-failure recovery — driven by a signed Node.js agent
that runs on the storage box. New in v1.2.0 (WP9).

## What it does

- **Delta mirror** (agent, every 30 min): the agent `rsync -a --delete --link-dest` pulls each
  server volume off its node into `/mnt/storage/mirror/<uuid>/`, keeping rotating hardlink
  snapshots. The storage box holds a full copy; only changed bytes cross the wire. The agent
  **pulls** — it initiates rsync — so no panel credential ever sits on the nodes.
- **Daily S3 backup**: a point-in-time, user-restorable archive through the panel's own
  `InitiateBackupService` against an S3 `BackupHost` (MinIO/Garage, `use_path_style_endpoint`).
- **Failover**: when a node is unreachable, the server is fenced, reassigned to a live node,
  recreated, and restored from the mirror archive (fact 16/17 — it's not a transfer). **Operator
  override in effect:** fully automatic after ~5 minutes offline (`STORAGE_FAILOVER_MODE=auto`).
- **The agent** (`agent/`): also serves an on-demand tar behind an HMAC-signed URL (wings pulls
  from it during recovery), answers a node-reachability probe, reports mirror size/freshness back,
  and self-updates from a signed bundle the panel serves. Code lives on `/opt` (OS disk), data on
  `/mnt/storage` (HDD), so a full/faulted HDD can't stop it reporting.

## Config keys / env (all `STORAGE_*`)

| Config | Env | Meaning |
|---|---|---|
| `agent_secret` | `STORAGE_AGENT_SECRET` | Shared HMAC with the agent (== `PH_AGENT_SECRET` on the box) |
| `agent_url` | `STORAGE_AGENT_URL` | Agent inbound listener, e.g. `http://<box>:8687` |
| `failover_mode` | `STORAGE_FAILOVER_MODE` (`auto`) | `off` \| `notify` \| `auto` |
| `failover_after_failures` | `STORAGE_FAILOVER_AFTER_FAILURES` (`5`) | Consecutive minute-probe failures before acting |
| `min_free_percent` / `mirror_snapshots` | `STORAGE_*` | Agent free-space floor + snapshot retention |

## Migrations

- `001_create_server_replicas_table.php`, `002_create_node_failovers_table.php` (operator-run).

## The agent

`agent/agent.mjs` (+ `install.sh`, `package.json`, systemd unit). Node ≥20, no npm deps. Install
on the storage box: `sudo PH_PANEL_URL=… PH_AGENT_SECRET=… bash install.sh`, then set the rsync
pull credential (`PH_RSYNC_TEMPLATE` / `PH_RSYNC_SSH_KEY`) in `/etc/plugged-host-storage.env`.

## Extension points it offers / consumes

- Offers: `StorageService::limboNode()` / `archiveUrlFor()` (limbo → storage seam),
  `/api/client/servers/{server}/replica`, the agent HTTP surface (`/plugged-host/storage/agent/*`).
- Consumes: status `NodeHealth::consecutiveFailures()` (failover witness) by class-string; upstream
  `DaemonServerRepository` / `DaemonBackupRepository` / `FindViableNodesService`.

## Requirements

Pelican Panel `1.0.0-beta38`. A storage box with an S3 endpoint, Node.js, and a per-node rsync
pull credential. Optional: `plugged-host-status` (failover witness).
