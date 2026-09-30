# Changelog

## 1.2.2

- Fix: a hibernated server had no way back. Upstream disables every power button on a suspended
  server, so Start never reached the interceptor that sends it to the restore page. Opening a
  parked server's Console now takes its owner straight to the restore page
  (`RedirectToRestorePage`, server-panel tenant middleware); subusers and admins keep the Console.
- Fix: hibernate suspended the server the moment the transfer was *requested*. When wings then
  failed the move, the server stayed suspended on its own node with no restore possible. It is
  now parked only once the transfer has landed on the limbo node; a failed move leaves it
  untouched (marker `failed`, retried after 24 h). The pre-fix victims are unsuspended by the
  same reconcile step.
- Fix: `TransferServerService::handle()` returning false (node not viable) was ignored — restore
  charged tokens and marked the server restored without moving it. Restore now settles only when
  wings reports the outcome; a failed move back is refunded and re-parked.
- New `reconcile()`, run by the sweep every minute even while `LIMBO_ENABLED=false`, so a move in
  flight when limbo is switched off still lands or is undone. New marker states `hibernating` and
  `failed`.
- Restore returns the server to its origin node on its original allocations (same address as
  before hibernation) when they are free, else free ones there, else a node with exactly the
  origin's tags.
- `LIMBO_MAX_PER_RUN` now caps moves to the limbo node **in flight at once**, not per run: at 3 per
  minute and several minutes per move, the first live sweep had dozens of transfers hitting a
  1-CPU limbo node at the same time, and most of them failed.
- The sweep only moves a server it has stopped itself, and checks with wings that it is offline.
- The restore page shows the move back in progress and opens the Console when it has settled.
- Version bump to 1.2.2.

## 1.2.1

- Fix: the idle stop called a `DaemonPowerRepository` that does not exist in this Pelican, so it never stopped anything; it now uses `DaemonServerRepository::power('stop')` and records the stopped state only after Wings accepted it.
- Version bump to 1.2.1.

## 1.2.0

- **New plugin (WP5). Ships dark** (`LIMBO_ENABLED=false`).
- Idle-server hibernation: `plugged-host:limbo-sweep` (scheduled every minute; `--status` /
  `--dry-run`) stops a server idle past 48 h, then parks it on the limbo node past 72 h.
- Restore (`RestoreFromLimboPage`, server-panel slug `limbo`): owner pays a token ransom
  (5 within 72 h, 10 after) via economy's `TokenService::spend()`, refunded on a failed transfer.
- Start interception: first link in ads' `PowerActionInterceptor` (ahead of the queue) — a start
  on a limbo server redirects to the restore page. Registered as an ad interstitial.
- Migration `001_create_server_limbo_states_table` (operator-run); `LimboService` is a
  logged-once no-op until it exists.
- **WP7 — client API**: `GET /limbo`, `GET /limbo/{server}`, `POST /limbo/{server}/restore`
  (outside `AuthenticateServerAccess`, owner-checked). Activity events with en+de descriptions.
- All cross-plugin dependencies (storage, economy, ads, upstream transfer) are reached by
  class-string behind `class_exists()`.
- Version bump to 1.2.0.
