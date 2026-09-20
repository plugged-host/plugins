# Changelog

## 1.2.0

- **WP11 — start queue repair.** `interceptStart` now checks `nodeSlotFree()` and
  `hasWaitingQueue()` on entry (line preservation), so a start is only held when the node is
  genuinely over capacity.
- Dedicated `START_QUEUE_THRESHOLD_PERCENT` (85), decoupled from the 95% capacity ceiling.
- Pass-through now stamps the node slot — a paced fallback for nodes whose live usage can't be
  measured.
- Activity logging (`server:queue.enqueue` / `release` / `timeout`) with `Lang::addLines` en+de.
- **WP7 — client API**: `GET /servers/{server}/queue`.
- The `EarnTokensPage` link now points at `plugged-host-economy` (earn-half move).
- Version bump to 1.2.0.
