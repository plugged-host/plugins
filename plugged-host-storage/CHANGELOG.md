# Changelog

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
