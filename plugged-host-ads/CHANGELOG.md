# Changelog

## Unreleased (2.1)

- **Unfilled units fold away**: a sandboxed unit whose document has loaded but holds no ad after
  5 s (Adsterra declines VPN traffic) is folded, and a slot, waiting-room cell or stack left empty
  gives its space back; a late fill unfolds it. Refresh waits for a first fill; the ad-block
  detector ignores folded units.
- **Banner pool**: `ADS_ADSTERRA_BANNERS` (`key:WIDTHxHEIGHT:host`, host per zone; settings field
  "Banner Zones" also takes pasted Adsterra snippets) plus the main banner. Zones are handed out
  once per page. Foot of every page: leaderboard `728x90|468x60|320x50`, the widest that fits
  (phones no longer get a cropped 728x90) + native; named slots: `300x250` (they used to repeat the
  foot's banner and native); `server-tools` is now placed: a `468x60|320x50` strip under the page
  header on server pages, not the console or waiting pages. The waiting rooms take the rest of the
  pool. Replaces the gate-only `ADS_START_GATE_BANNERS`. `scripts/refresh.blade.php` is now
  `scripts/units.blade.php` (size picking + refresh).
- **Ad refresh**: sandboxed units reload after `ADS_REFRESH_SECONDS` (5) in view, or
  `ADS_REFRESH_PLUS_SECONDS` (10) for plugged.host Plus; only while half the unit is on screen in a
  visible tab. `AdDisplayService::refreshSeconds()`, `scripts/refresh.blade.php` at `SCRIPTS_AFTER`,
  settings section "Ad Refresh".
- **In-game ads**: `plugged-host:ads-in-game` (every minute) sends `say <message>` to running
  Minecraft servers (not proxies) whose owner has neither plugged.host Plus nor an exemption,
  each once per interval at its own minute. Settings section "In-game Ads" (`ADS_IN_GAME_*`).
- `AdDisplayService::isExemptByRole()`; the PlusPlus exemption is registered by economy (the
  queue's ad-free pass is gone).

## 1.2.1

- `PowerActionInterceptor::interceptFor(User, Server, action)` extracted as the single public definition of the start chain (limbo → start queue → ad gate); the Livewire hook and plugged-host-neo both use it.
- The waiting-room URL is built before the start is sent, so a failure after dispatch can no longer lead to a second start.
- Version bump to 1.2.1.

## 1.2.0

- **Token earning moved out to `plugged-host-economy`.** `TokenEarnService`, `TokenClaim`,
  `EarnTokensPage`, `EarnOffers`, the `Rewards/*` providers, their views and the two start-credits
  migrations all left; the earn render hooks (Linkvertise full-script, store-earn block) went with
  them. Ads keeps the **start gate** (`StartGatePage`, `StartGateService`,
  `PowerActionInterceptor`) and the impression surfaces. The start gate reaches the (now economy)
  earn page by class-string.
- **WP4 — named ad slots + per-page cap.** `AdDisplayService::slot(name)` with a
  `max_units_per_page` cap (default 2) and no-zone-twice, plus a `placements` config map.
  A `<x-plugged-host-ads::slot name="…" />` anonymous component; the sidebar-footer and
  server-list-footer placements render through Filament render hooks (surfaces with no owned
  blade) via the same component.
- Fixed the limbo interstitial registration to pass a callable to `registerInterstitialRoute()`.
- Version bump to 1.2.0.
