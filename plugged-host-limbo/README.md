# plugged.host Limbo

Idle-server hibernation for a Pelican Panel: a server nobody has touched is stopped, then parked
on a dedicated "limbo" node, and the owner pays a small token ransom to restore it — reclaiming
node capacity that would otherwise be held indefinitely. New in v1.2.0 (WP5). **Ships dark**
(`LIMBO_ENABLED=false`).

## The rule

| Elapsed since last panel activity | Action |
|---|---|
| 48 h | Stop the container; notify the owner |
| 72 h | Transfer to the limbo node (parked = suspended); notify |
| Restore ≤ 72 h after entering limbo | 5 tokens |
| Restore > 72 h after entering limbo | 10 tokens |

"Activity" is the most recent `activity_logs` row for that server (falling back to
`servers.created_at`) — a single console page view resets the clock. `IdleDetector` is one seam
(`LimboService::lastActivityAt()`) so switching to daemon-sampling later is a one-method change.

## What it does

- **Sweep** (`plugged-host:limbo-sweep`, scheduled every minute; `--status` / `--dry-run`): finds
  idle servers, stops them, then hibernates them to the limbo node. Guards: skips conflict-state /
  installing / restoring / suspended / maintenance / unbounded-disk servers; per-run cap
  (`LIMBO_MAX_PER_RUN`); global kill switch.
- **Restore** (`RestoreFromLimboPage`, server-panel slug `limbo`): shows the cost + balance and one
  button; charges via economy's `TokenService::spend()` (before the transfer, refunds on failure);
  owner-only. Registered as an ad interstitial.
- **Start interception**: first link in ads' `PowerActionInterceptor` (ahead of the queue), by
  class-string — a start on a limbo server is redirected to the restore page.

## Config keys / env (all `LIMBO_*`)

| Config | Env | Meaning |
|---|---|---|
| `enabled` | `LIMBO_ENABLED` (`false`) | Global kill switch — ships dark |
| `stop_hours` / `transfer_hours` | `LIMBO_STOP_HOURS` (`48`) / `LIMBO_TRANSFER_HOURS` (`72`) | Thresholds |
| `node_tag` | `LIMBO_NODE_TAG` (`limbo`) | The limbo node's tag (not a country code; keep out of `UCS_DEPLOYMENT_TAGS`) |
| `max_per_run` | `LIMBO_MAX_PER_RUN` (`3`) | Cap per sweep |
| `cost_early` / `cost_late` / `cost_window_hours` | `LIMBO_COST_*` | Ransom |

## Migrations

- `001_create_server_limbo_states_table.php` (operator-run). `LimboService` is a logged-once
  no-op until it exists.

## Extension points it consumes (all by class-string behind `class_exists()`)

- storage `StorageService::limboNode()` (the limbo node lookup),
- economy `TokenService::spend()` / `add()` (ransom + refund),
- ads `PowerActionInterceptor` chain + `AdDisplayService::registerInterstitialRoute()`,
- upstream `TransferServerService` (the move to/from limbo).

## Requirements

Pelican Panel `1.0.0-beta38`. `plugged-host-storage` (limbo node), `plugged-host-economy` (ransom),
`plugged-host-ads` (interceptor). A wings node tagged `limbo`, excluded from `UCS_DEPLOYMENT_TAGS`.
