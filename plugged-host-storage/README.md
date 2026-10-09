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
- **The agent** (`agent/`): also serves an on-demand tar behind an HMAC-signed URL (wings pulls
  from it during recovery), answers a node-reachability probe, reports mirror size/freshness back,
  and self-updates from a signed bundle the panel serves. Code lives on `/opt` (OS disk), data on
  `/mnt/storage` (HDD), so a full/faulted HDD can't stop it reporting.

## Config keys / env (all `STORAGE_*`)

| Config | Env | Meaning |
|---|---|---|
| `agent_secret` | `STORAGE_AGENT_SECRET` | Shared HMAC with the agent (== `PH_AGENT_SECRET` on the box) |
| `agent_url` | `STORAGE_AGENT_URL` | Agent inbound listener, e.g. `https://storage.<domain>` |
| `mirror_public_key` | `STORAGE_MIRROR_PUBLIC_KEY` | The box's `ph-mirror` key, served to nodes by `/plugged-host/storage/node/install.sh` |
| `failover_mode` | `STORAGE_FAILOVER_MODE` (`auto`) | `off` \| `notify` \| `auto` |
| `failover_after_failures` | `STORAGE_FAILOVER_AFTER_FAILURES` (`5`) | Consecutive minute-probe failures before acting |
| `min_free_percent` / `mirror_snapshots` | `STORAGE_*` | Agent free-space floor + snapshot retention |

## Migrations

- `001_create_server_replicas_table.php`, `002_create_node_failovers_table.php` (operator-run).

## Setting up the storage box (from zero)

`agent/agent.mjs` (+ `install.sh`, `package.json`, systemd unit). Node 20+, no npm deps.

1. Give the box a separate disk mounted at `/mnt/storage` (`nofail` in fstab). If the box is also
   a Wings node, keep Wings' data on that disk too and bind-mount it back to `/var/lib/pelican`.
2. Copy `agent/` to the box and run, as root:

   ```bash
   sudo PH_PANEL_URL=https://<panel> PH_AGENT_SECRET=<random 64 hex> \
        PH_SSH_PORTS="node1.example.com=22 *.example.com=2222" bash install.sh
   ```

   It installs Node (if needed), rsync and pigz, the agent under `/opt/plugged-host-storage`,
   `/etc/plugged-host-storage.env`, the systemd unit with a `RequiresMountsFor` drop-in, the
   `ph-mirror` SSH key (printed at the end) and, with `PH_SSH_PORTS`, the nodes' SSH ports.
3. Put the agent's port 8687 behind HTTPS (e.g. nginx at `https://storage.<domain>`).
4. Panel, Admin › Plugins › plugged.host Storage: agent URL, the same secret, and the printed
   mirror public key.
5. On every Wings node: `curl -fsSL https://<panel>/plugged-host/storage/node/install.sh | sudo bash`
   (authorizes the mirror key; prints the node's SSH port).
6. `systemctl restart plugged-host-storage-agent`, then `php artisan plugged-host:doctor
   plugged-host-storage` on the panel.

## Extension points it offers / consumes

- Offers: `StorageService::limboNode()` / `archiveUrlFor()` (limbo → storage seam),
  `/api/client/servers/{server}/replica`, the agent HTTP surface (`/plugged-host/storage/agent/*`).
- Consumes: core's `NodeTelemetry::consecutiveFailures()` (failover witness); upstream
  `DaemonServerRepository` / `DaemonBackupRepository` / `FindViableNodesService`.

## Requirements

A storage box with its own disk and Node.js (the installer brings it), SSH from it to every
node (the mirror key), and `plugged-host-core`.
