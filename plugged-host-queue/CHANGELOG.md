# Changelog

## 2.2.1 (2026-10-23)

- **Vietnamese** (`lang/vi`), in step with en and de.
- The README, plugin description and settings texts describe what the queue does: every start of
  a user who is not exempt and holds no pass waits at least `START_QUEUE_MIN_WAIT`, releases on one
  node are spaced by `START_QUEUE_RELEASE_INTERVAL`, and the node's load is checked at release
  (they used to say a start is only held on a node over the line). Core's `StartChain` owns the
  Livewire hook (the queue is gate 20), and the line is `START_QUEUE_THRESHOLD_PERCENT`, which
  servers' deployment pause reads too. The behaviour is unchanged.
- Removed the dead `START_QUEUE_PACED_ON_UNAVAILABLE` setting (nothing read it) and the unused
  `StartQueueService::pacedOnUnavailable()`, `hasWaitingQueue()` and `NodePressure::measurable()`.
  `NodePressure::limit()` no longer falls back to servers' removed `NODES_HOT_PERCENT`; a busy
  line outside 1-100 now falls back to 85, as the deployment pause already did.
- Self-hosting: "Plus"/"PlusPlus" on the waiting page and in the settings are named after the
  panel (`APP_NAME`).

## Unreleased (2.1)

- **Priority comes from plugged.host Plus (level 1) and PlusPlus (level 2)**, read through
  `PriorityPassService::level()`; `start_queue_entries.priority` holds the level. Selling passes
  is gone (store, waiting page, settings, `priority.*` config); the waiting page links to the
  plugged.host Plus page. Open passes were refunded in full by
  `plugged-host:queue-refund-priority-passes` (2026-10-03: 80 tokens to 3 accounts).
- **An ad exemption no longer skips the queue.** `skips()` asks ads' `isExemptByRole()` only,
  so PlusPlus (ad-free) still queues, at the front.

- The "move to a quieter node" offer hands the move to plugged-host-servers' transfer
  orchestrator when it is installed: live progress on the Transfer page, retries on the next
  candidate node, and the server starts by itself on arrival (it used to be a bare upstream
  transfer the user had to start afterwards). Missing destination ports are created instead of
  hiding the offer.

## 1.2.1

- Version bump to 1.2.1 (plugged.host 2.1).

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
