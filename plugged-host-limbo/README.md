# plugged.host Limbo

Idle-server hibernation for a Pelican Panel: a server nobody has touched is stopped, then parked
on a dedicated "limbo" node, and the owner pays a small token ransom to restore it — reclaiming
node capacity that would otherwise be held indefinitely. New in v1.2.0 (WP5). **Ships dark**
(`LIMBO_ENABLED=false`).

## The rule

| Elapsed since last panel activity | Action |
|---|---|
| 48 h | Stop the container (activity log `server:limbo.stop`) |
| 72 h | Transfer to the limbo node (parked = suspended; activity log `server:limbo.hibernate`) |
| Restore ≤ 72 h after entering limbo | 5 tokens |
| Restore > 72 h after entering limbo | 10 tokens |

"Activity" is the most recent `activity_logs` row with that server as subject (falling back to
`servers.created_at`), whoever the actor is: the owner, a subuser or an admin (console commands,
file changes, SFTP, power actions) — and system events too (`server:crashed`, scheduled backups,
limbo's own stop, transfers). Merely opening the console logs nothing and does not count. `IdleDetector` is one seam
(`LimboService::lastActivityAt()`) so switching to daemon-sampling later is a one-method change.

## What it does

- **Sweep** (`plugged-host:limbo-sweep`, scheduled every minute; `--status` / `--dry-run`): finds
  idle servers, stops them, then moves the ones it stopped to the limbo node. Guards: skips
  conflict-state / installing / restoring / suspended / maintenance / unbounded-disk servers;
  checks with wings that the server is offline; caps moves in flight (`LIMBO_MAX_PER_RUN`); backs
  off 24 h after a failed move; global kill switch.
- **Exemptions** (`ExemptionPruner`): before a move, the storage agent's exclude list
  (plugged-host-storage `StorageService::excludePatterns()`, read from `agent.mjs` `EXCLUDES`:
  node_modules, caches, logs, …) is deleted from the server, because a wings transfer cannot skip
  anything. They do not come back on restore, as with a storage failover. `alpine` (FiveM's
  runtime) and everything under it is kept (`prune_keep`). Found with wings' file search and checked
  with rsync semantics; `node_modules`, which that search never sees, is found next to
  `package.json` / lockfiles. `LIMBO_PRUNE=false` turns it off.
- **Circuit breaker**: when the last three moves to the limbo node all failed, no new move starts
  for an hour after the latest failure, then one probe at a time until one lands.
- **Reconcile** (first step of every sweep, also while the kill switch is off): a transfer is
  asynchronous, so markers only change once wings reports the outcome. Landed on limbo → suspend
  (parked). Failed → unsuspend, marker `failed`. Restore landed → `restored`; failed → refund and
  re-park.
- **Restore** (`RestoreFromLimboPage`, server-panel slug `limbo`): shows the cost + balance and one
  button; charges via economy's `TokenService::spend()` (before the transfer, refunded if the move
  fails); owner-only. Goes back to the origin node on the original allocations when they are free.
  Registered as an ad interstitial.
- **Way back**: `RedirectToRestorePage` (server-panel tenant middleware) sends the owner of a
  parked server from its Console to the restore page. Upstream disables the power buttons on a
  suspended server, so this, not the Start button, is the entry point.
- **Start interception**: first link in ads' `PowerActionInterceptor` (ahead of the queue), by
  class-string — a start on a limbo server (from the server list or neo) is redirected to the
  restore page.

Marker states: `stopped` → `hibernating` → `limbo` → `restoring` → `restored`, plus `failed`.

**Owners are not notified.** Stopping and parking only write activity-log entries on the server
(`server:limbo.stop`, `server:limbo.hibernate`, and `server:limbo.restore` on a restore), which
the owner sees in the server's activity; no notification or e-mail is sent.

**Moving limbo to another node.** The limbo node is the node with the `LIMBO_NODE_TAG` tag
(the first one, by id). Each row records the node its server was parked on (`limbo_node_id`,
migration 021), so the tag can move: tag the new node and untag the old one, and new servers are
parked on the new node while the ones on the old node stay parked there, with their restore page
and their way home. A server on a node servers are parked on is never hibernated again (it would
come back to that node), and no restore goes to one. `plugged-host:limbo-sweep --status` names the
limbo node and every node servers are parked on, with their disk use.

**Disk limit.** No new moves while the limbo node's data disk is used past
`LIMBO_MAX_DISK_PERCENT` (85, core's node telemetry): the limbo node is also a storage agent's
box, and that agent stops copying when the disk runs low.

## Config keys / env (all `LIMBO_*`)

| Config | Env | Meaning |
|---|---|---|
| `enabled` | `LIMBO_ENABLED` (`false`) | Global kill switch — ships dark |
| `stop_hours` / `transfer_hours` | `LIMBO_STOP_HOURS` (`48`) / `LIMBO_TRANSFER_HOURS` (`72`) | Thresholds |
| `prune` | `LIMBO_PRUNE` (`true`) | Delete the storage exemptions before a move (`prune_keep`: `alpine`) |
| `node_tag` | `LIMBO_NODE_TAG` (`limbo`) | The limbo node's tag (not a country code; keep out of `UCS_DEPLOYMENT_TAGS`) |
| `max_per_run` | `LIMBO_MAX_PER_RUN` (`3`) | Cap on moves to the limbo node in flight at once (and stops per sweep) |
| `max_disk_percent` | `LIMBO_MAX_DISK_PERCENT` (`85`) | No new moves while the limbo node's disk is used past this (0: off) |
| `cost_early` / `cost_late` / `cost_window_hours` | `LIMBO_COST_*` | Ransom |

## Migrations

- `001_create_server_limbo_states_table.php` (operator-run). `LimboService` is a logged-once
  no-op until it exists.
- `021_add_limbo_node_to_server_limbo_states_table.php`: `limbo_node_id`, filled in for rows that
  are parked or moving (from where the server is, or its transfer; not from the tag). Until it
  runs, limbo knows only the tagged node, as before: run it before moving the tag.

## Extension points it consumes (all by class-string behind `Plugins::available()`)

- storage `StorageService::limboNodes()` / `limboNode()` (the limbo node lookup),
- core `NodeTelemetry::latest()` (the limbo node's disk),
- economy `TokenService::spend()` / `add()` (ransom + refund),
- ads `PowerActionInterceptor` chain + `AdDisplayService::registerInterstitialRoute()`,
- upstream `TransferServerService` (the move to/from limbo).

## Requirements

Pelican Panel `1.0.0-beta38`. `plugged-host-storage` (limbo node), `plugged-host-economy` (ransom),
`plugged-host-ads` (interceptor). A wings node tagged `limbo`, excluded from `UCS_DEPLOYMENT_TAGS`.
