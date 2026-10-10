# plugged.host Storage

The storage server: 30-minute delta-mirror replication of every live server and automatic
node-failure recovery, driven by a signed Node.js agent that runs on the storage box, plus the
backups portal (backups.<domain>). Requires `plugged-host-core` (its node telemetry is the
failover witness); works with `plugged-host-discord` for the portal's sign-in.

## What it does

- **Delta mirror** (agent, every 30 min): the agent `rsync -a --delete --link-dest` pulls each
  server volume off its node into `/mnt/storage/mirror/<uuid>/`, keeping rotating hardlink
  snapshots. The storage box holds a full copy; only changed bytes cross the wire. The agent
  **pulls** — it initiates rsync — so no panel credential ever sits on the nodes.
- **Daily S3 backup: not implemented.** The `STORAGE_DAILY_BACKUP*` settings exist, but nothing
  schedules a backup yet; recovery uses the mirror archive.
- **Failover**: when a node is unreachable, the server is fenced, reassigned to a live node,
  recreated, and restored from the mirror archive (fact 16/17 — it's not a transfer). **Operator
  override in effect:** fully automatic after ~5 minutes offline (`STORAGE_FAILOVER_MODE=auto`).
- **Deleted servers**: a server deleted by an admin, or by the panel itself (abuse purge, an
  account deleted through noprofit, ...), keeps its copy on the storage box for
  `STORAGE_DELETED_HOLD_DAYS` (7) days and stays listed, under its ID, for its owner on the backups
  portal, in case it was deleted by mistake. A server its owner deletes loses its copy within the
  hour. Admins: `php artisan plugged-host:deleted-servers` (list; `--url=<uuid>` signed download
  link; `--extend=<uuid> --days=N` keep longer).
- **No copy kept twice**: once an hour the agent reports the folders it holds and removes the ones
  the panel names (servers gone, hold over; never one in the plan). A folder the panel has no
  record of is kept 7 days after its last copy and at least a day after it was first reported.
  Cached archives older than a day go too. A server on the storage box's own wings node (limbo)
  gets a mirror of hardlinks to its volume instead of a second copy, and the agent removes wings
  volumes there that belong to no server on that node (a move away that left them behind).
  Snapshots kept per server follow the panel's `STORAGE_MIRROR_SNAPSHOTS`.
- **The agent** (`agent/`): also serves an on-demand tar behind an HMAC-signed URL (wings pulls
  from it during recovery), answers a node-reachability probe, reports mirror size/freshness back,
  and self-updates from a signed bundle the panel serves. Code lives on `/opt` (OS disk), data on
  `/mnt/storage` (HDD), so a full/faulted HDD can't stop it reporting.

## Config keys / env (all `STORAGE_*`)

| Config | Env | Meaning |
|---|---|---|
| `agent_secret` | `STORAGE_AGENT_SECRET` | Shared HMAC with the agent (== `PH_AGENT_SECRET` on the box) |
| `agent_url` | `STORAGE_AGENT_URL` | Agent inbound listener, e.g. `https://storage.<domain>` (the installer sets it) |
| `mirror_public_key` | `STORAGE_MIRROR_PUBLIC_KEY` | The box's `ph-mirror` key (the installer sets it), served to nodes by `/plugged-host/storage/node/install.sh` |
| `failover_mode` | `STORAGE_FAILOVER_MODE` (`auto`) | `off` \| `notify` \| `auto` |
| `failover_after_failures` | `STORAGE_FAILOVER_AFTER_FAILURES` (`5`) | Consecutive minute-probe failures before acting |
| `min_free_percent` / `mirror_snapshots` | `STORAGE_*` | Agent free-space floor + snapshot retention (sent to the agent in the plan) |
| `deleted_hold_days` | `STORAGE_DELETED_HOLD_DAYS` (`7`) | Days an admin-deleted server's copy is kept |
| `purge_deleted` | `STORAGE_PURGE_DELETED` (`true`) | Kill switch for the agent's cleanup (holds are still recorded) |

## Migrations

- `001_create_server_replicas_table.php`, `002_create_node_failovers_table.php`,
  `003_create_storage_deleted_servers_table.php` (operator-run).

## Setting up the storage box (from zero)

The agent (`agent/agent.mjs`, no npm dependencies) runs on Node.js 26; the installer brings it.

1. Give the box a separate disk mounted at `/mnt/storage` (`nofail` in fstab). If the box is also
   a Wings node, keep Wings' data on that disk too and bind-mount it back to `/var/lib/pelican`.
2. Copy the setup command from the panel, Admin › Plugins › plugged.host Storage › **Setup
   command** (or run `php artisan plugged-host:storage-setup` on the panel), and run it on the
   storage box:

   ```bash
   curl -fsSL https://<panel>/plugged-host/storage/agent/install.sh?code=<one-time code> | sudo bash
   ```

   It asks where the storage disk is, the nodes' SSH port (and any node on another one), and the
   address the agent answers on, and offers to put nginx with a Let's Encrypt certificate in front
   of it. It gets the rest from the panel with the code (agent secret, node list, the agent
   itself, checked against the panel's signed manifest), installs Node.js 26, rsync and pigz, the
   agent and its systemd service, creates the `ph-mirror` key, and sends the key and the address
   back to the panel. Nothing to copy by hand. The code works once, for an hour.
3. On every Wings node: `curl -fsSL https://<panel>/plugged-host/storage/node/install.sh | sudo bash`
   (authorizes the mirror key). The installer can then test SSH to every node.
4. `php artisan plugged-host:doctor plugged-host-storage` on the panel.

Run the setup command again to upgrade the agent or change an answer: the previous answers are
the defaults. Unattended: `… | sudo bash -s -- --yes` plus flags for anything without a default
(`--agent-url`, `--ssh-port`, `--ssh-port-for host=port`, `--nginx --email …`; all listed at
the top of `agent/install.sh`).

## Extension points it offers / consumes

- Offers: `StorageService::limboNode()` / `archiveUrlFor()` (limbo → storage seam),
  `/api/client/servers/{server}/replica`, the agent HTTP surface (`/plugged-host/storage/agent/*`).
- Consumes: core's `NodeTelemetry::consecutiveFailures()` (failover witness); upstream
  `DaemonServerRepository` / `DaemonBackupRepository` / `FindViableNodesService`.

## Requirements

A storage box with its own disk (the installer brings Node.js 26), SSH from it to every
node (the mirror key), and `plugged-host-core`.
