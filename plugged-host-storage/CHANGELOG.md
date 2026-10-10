# Changelog

## Agent 1.4.0: deleted servers, nothing kept twice (2026-10-10)

- **Deleted servers keep their copy 7 days.** Every server deletion is recorded
  (`storage_deleted_servers`, migration 003). Deleted by an admin or by the panel itself: the copy
  on the storage box stays `STORAGE_DELETED_HOLD_DAYS` (7) days and the owner still sees the
  server, under its ID, in a "Deleted servers" section of the backups portal, with a download.
  Deleted by its owner: the copy goes at the next cleanup. `plugged-host:deleted-servers` lists
  them for admins, prints a signed download link (`--url`) or keeps one longer (`--extend`).
- **Cleanup.** Nothing ever removed a deleted server's mirror: on 2026-10-10 the box held 1,308
  mirror folders for 513 live servers (about 0.8 TB of 1.4 TB used). The agent now reports its
  folders hourly (`POST /plugged-host/storage/agent/inventory`) and removes the ones the panel
  names; folders the panel has no record of are kept 7 days after their last copy and at least a
  day after first reported. Cached archives older than a day are dropped. `STORAGE_PURGE_DELETED=false`
  stops removals.
- **Limbo servers are not copied twice.** A server on the storage box's own node (the limbo node,
  matched by the agent URL's host) gets a mirror of hardlinks to its wings volume, and the copy
  from its old node goes. Wings volumes there that no longer belong to a server on that node are
  removed (a move away that left them behind).
- Snapshot retention follows the panel's `STORAGE_MIRROR_SNAPSHOTS` (the agent kept its own 3);
  a snapshot an archive is being built from is never removed under tar.
- Reports for a server deleted mid-pass no longer 500 on the panel.
- Rollout: `STORAGE_AGENT_VERSION=1.4.0` on the panel; the agent updates itself.

## 1.2.2

- `StorageService::excludePatterns()`: the agent's `EXCLUDES` list, read from `agent/agent.mjs`, so
  limbo can apply the same exemptions before a move without keeping a second copy.
- Version bump to 1.2.2.

## Agent 1.3.0 (plugged.host 2.1)

- **Downloads start at once.** A browser download (backups portal, `stream=1`, or a browser
  User-Agent) is streamed while tar packs it, instead of after the whole archive is built.
  Restores (wings) still get the complete file with a Content-Length, as before.
- Archives are built from the newest snapshot (a pass running meanwhile cannot change files
  under tar), cached until the next mirror pass, and served with a size and range support
  (resumable). An aborted download still finishes the cache, so a retry is instant.
- `pigz -1` when the box has pigz, else `gzip -1` (was single-threaded gzip -6).
- At most `PH_MAX_ARCHIVE_JOBS` (3) builds at once; more answer 503 + Retry-After.
- `/info` reports `archive_bytes` for a ready archive and no longer runs `du` on every call.
- Portal: agent lookups for all of a user's servers go out concurrently and are cached for a
  minute; sizes in GB; the Download button says the download has started.
- Rollout: the agent updates itself from the panel it polls (prod) once that panel's
  `STORAGE_AGENT_VERSION` is 1.3.0.

## 1.2.1

- Version bump to 1.2.1 (plugged.host 2.1).

## 1.2.0

- **New plugin (WP9).** Storage-server replication, backup and failover, driven by a signed
  Node.js agent (`agent/`) that runs on the storage box (no npm deps, Node ≥20).
- **Delta mirror**: the agent `rsync -a --delete --link-dest` pulls each server volume every
  30 minutes into rotating hardlink snapshots — the agent initiates, so no panel credential sits
  on the nodes.
- **Daily S3 backup**: a user-restorable archive through the panel's `InitiateBackupService`
  against an S3 `BackupHost` (Garage/MinIO, path-style endpoint).
- **Failover**: fence → reassign → recreate → restore-from-mirror when a node is unreachable.
  **Ships automatic after ~5 minutes offline** (`STORAGE_FAILOVER_MODE=auto`, operator override),
  with the status plugin as a second witness.
- Migrations `001_create_server_replicas_table`, `002_create_node_failovers_table` (operator-run).
- Offers the `StorageService::limboNode()` / `archiveUrlFor()` seams consumed by
  `plugged-host-limbo`, and `GET /servers/{server}/replica`.
- Version bump to 1.2.0.
