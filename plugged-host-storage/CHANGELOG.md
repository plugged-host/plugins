# Changelog

## 2.2.1 (2026-10-23)

- **Declare a node gone**: `plugged-host:failover-sweep --node=<id|name>` makes the sweep recover
  that node's servers while wings keeps missing its probes, even when the host refuses wings' port
  (which the detection reads as "up": a reinstalled machine, or an IP the provider gave to someone
  else). The declaration ends by itself the first time wings answers again; `--clear-node`
  withdraws it, `--status` lists it.
- Failover counts a node as down only on real outages (`consecutiveOutages()`): a host that
  refuses Wings' port is up, so a crashed or slowly restarting Wings no longer has its servers
  recovered elsewhere. Destinations still need Wings answering.
- **Mirror keys pinned to their addresses**: `STORAGE_MIRROR_FROM` / `STORAGE_BACKUP_MIRROR_FROM`
  (settings: "Mirror key's addresses") are the IPs a box reaches the nodes from; the node installer
  then writes its key as `from="<addresses>" ssh-…` and replaces an older line for the same key.
  Unset: the key works from anywhere, as before.
- **The backups portal's address is a setting.** `BACKUPS_PORTAL_URL` (config `portal_url`,
  Admin › Plugins › plugged.host Storage › Backups portal) defaults to empty instead of
  plugged.host's portal: the portal's routes answer on that URL's host when it is set, and on the
  panel under `/backups` (now with its sign-in and logout routes, signing in with the panel's own
  session) when it is not. The Backups page's links and the header action follow it. The portal
  page takes the panel's name (`APP_NAME`), its status link from `plugged-host-core.status_url`
  and its Discord link from `plugged-host-discord.invite_url` (each left out while empty), shows
  the Store link only with the store page and "Sign in with Discord" only with
  plugged-host-discord, and is translated (en, de). The controller's panel fallback is `url('/')`.
- **A recovered server is recovered again.** A `done` failover fenced its server for good, so a
  server recovered (or drilled) once was never recovered again, also when the node it was
  recovered to failed. A done recovery now fences only the outage it answered: while the server
  is back on the node it was recovered off and that node has not answered a probe since; a done
  drill never fences.
- `plugged-host:failover-sweep --dry-run` no longer notifies owners in `notify` mode. `--drill`
  and `--resume` are refused while the mode is `off`: the sweep that carries them on does not run
  then, and a drill left its server moved, freshly installed and without its files.
- The message after a failed recovery no longer says support was alerted (nothing alerts anyone;
  the failure is logged): it asks the owner to contact support if the server stays unavailable.
- "Max Failovers Per Run" counts servers, not nodes (settings hint).
- Removed the `STORAGE_DAILY_BACKUP` / `STORAGE_DAILY_BACKUP_HOUR` settings: nothing read them.
  The mirror interval and free-space floor hints say that a box picks a change up when its setup
  command is run there again (only the snapshot count travels in the work-plan).
- The agent bundle is rebuilt when `agent/package.json` changes too, not only `agent.mjs`.
- The limbo node's tag is read from `config('plugged-host-limbo.node_tag')`, not `env()`.
- **The backup server as the limbo node.** The backup server no longer copies the servers on the
  storage box's own node (the old limbo node): they are on the storage box's drive already, and
  reading them all over that drive was what it is there to spare. The storage box still copies
  every server, those on the backup server's node included. A server on a box's own node gets its
  archive from that box (its copy is the volume itself; its copy time is when the files last
  changed, so the copy-time rule sent every download of a server parked on the backup server to
  the storage box). `StorageService::limboNodes()` lists every node with the limbo tag;
  failover leaves all of them out.

## 2.2.0 (2026-10-17)

- **Backup server**: an optional second box running the same agent, for a storage box that is
  stable but slow (one thread: packing an archive takes ages). Both copy every server; archives
  for the backups portal, the Backups banner, failover restores and `deleted-servers --url` come
  from the backup server while its copy is no more than one mirror interval older than the storage
  box's, else from the storage box (`StorageService::servingBox()`); a box that does not answer is
  skipped for a minute. The failover second witness asks both. Set up with
  `php artisan plugged-host:storage-setup --backup` (or Admin › Plugins › plugged.host Storage ›
  Backup server), then the node script again on every node, which now authorizes every box's key.
  New settings `STORAGE_BACKUP_AGENT_URL`, `STORAGE_BACKUP_AGENT_SECRET`,
  `STORAGE_BACKUP_MIRROR_PUBLIC_KEY`; migration 004 gives `server_replicas` a row per server and
  box (`box`). The panel knows which box calls by whose secret signs the request; the update
  manifest is signed once per box (`X-PH-Signature`, `X-PH-Signature-Backup`), and the agent and
  installer accept the one that is theirs. `plugged-host:doctor` checks the backup server, and the
  mirror passes and cleanup reports per box.
- The agent runs `PH_MIRROR_JOBS` mirror passes at once (default 1; the installer sets half the
  cores, 1 to 4), never two on the same node. `/health` reports it.
- The installer works without a Let's Encrypt e-mail, and names the mirror key after its box.

- **One command sets a storage box up**: the storage settings (or
  `php artisan plugged-host:storage-setup`) show `curl … /plugged-host/storage/agent/install.sh?code=…
  | sudo bash` with a one-time code (an hour). The installer is guided: it asks where the disk is,
  the nodes' SSH ports and the agent's address (offering nginx + Let's Encrypt), gets the agent
  secret, node list and the agent (verified against the signed manifest) from the panel
  (`/plugged-host/storage/agent/setup`), installs Node.js 26, rsync, pigz, the service with a
  `RequiresMountsFor` drop-in and the `ph-mirror` key, and reports the key and address back
  (`/setup/complete`). Re-running upgrades with the previous answers as defaults; flags and
  `--yes` for unattended runs. Nodes authorize the key with
  `curl -fsSL https://<panel>/plugged-host/storage/node/install.sh | sudo bash`
  (`STORAGE_MIRROR_PUBLIC_KEY`, new setting).
- The agent now targets Node.js 26 (`engines`).
- Failover reads core's node telemetry instead of the status plugin.
- `plugged-host:doctor` checks the agent settings and the last mirror pass.
- README no longer claims a daily S3 backup: it was never scheduled.
- From prod (2.1): **node failover actually recovers servers**: a state machine run by the
  minute sweep (creating: fence, move to a node the transfer planner would pick, wings create
  with the egg install; restoring: wings streams the mirror, adapter s3, a temporary backup row;
  done: `ServerMoved` (subdomains repoint), owner told the new address). Back-off after failures,
  at most one notification a day, `STORAGE_FAILOVER_MAX_IN_FLIGHT` (3), suspended servers stay
  put, `plugged-host:failover-sweep --drill= --resume=`. Needs plugged-host-servers (optional
  dependency). Agent archives list regular files only (format 2) and are typed
  `application/gzip` for non-browser clients.

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
