# Changelog

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
