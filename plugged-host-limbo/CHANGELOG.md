# Changelog

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
