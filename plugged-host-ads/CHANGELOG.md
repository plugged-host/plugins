# Changelog

## 2.2.0 (unreleased)

- **Fix: saving the settings no longer wipes fields the form is not showing.** Filament leaves a
  hidden field out of the submitted data, and `saveSettings()` wrote false/empty for it: with the
  start gate off a save switched Linkvertise off and cleared its publisher id and link (no more
  token earning), turned every `ADS_START_GATE_*` waiting-room format off (also on the start queue's
  waiting page) and cleared the gate script and Direct Link; with ad-block detection off it turned
  `ADS_ANTIBLOCK_PROBE` and `ADS_ANTIBLOCK_SKIP_CONSOLE` off. A key missing from the form data now
  keeps its current value. The token-earning section (Linkvertise, with the claim lifetime moved
  into it) and the waiting-room ads section show whether the start gate is on or not; only "Also
  gate server navigation" stays with the gate.
- **Maintenance page ad**: `maintenance.aads_unit` (`ADS_MAINTENANCE_AADS_UNIT`, default empty = no
  ad) and `maintenance.width` (`ADS_MAINTENANCE_AADS_WIDTH`, `70%`), with a settings section; core's
  503 page renders the A-ADS unit from them (it used a fixed unit id before).
- Self-hosting: the in-game message defaults to "This server is powered by `APP_NAME`"
  (`ADS_IN_GAME_MESSAGE` empty; saving the default keeps it following the name), the ad-block notice
  names `APP_NAME` and the host of `APP_URL`, and Plus is named after the panel in the settings.
- README: clear caches with `config:clear`/`route:clear`/`view:clear`/`event:clear`, never
  `optimize:clear`.

- **Sponsored banners**: banners the owner sells himself, uploaded in **Admin › Panel › Sponsored
  banners** (PNG, JPG, WebP or GIF up to 4 MB on the public disk under `plugged-host-ads/banners/`;
  link, advertiser, placement, optional start/end, active switch; the list shows status and clicks).
  Placement **Adsterra slots**: the image must be an Adsterra size or twice it (`1456x180` is a
  `728x90`, `AdBannerService::family()`), and a live one takes the place of the Adsterra unit of its
  size wherever `AdsterraService` hands one out (page foot, the `300x250` slots, the server-tools
  strip, the waiting rooms' leaderboard and extra zones), at most once per page, several taking
  turns; Adsterra's zones are still taken, so every other placement is unchanged, and with none live
  Adsterra is exactly as before. Placement **Top banner**: full width at `CONTENT_START`, above the
  page heading on every app and server page, any size, rotating per page load. Both scale down to the
  container and never past their size, carry a "Sponsored" caption (the waiting rooms keep their one
  caption) and the alt text "Sponsored: advertiser", and link through `GET /ads/b/{id}`, which counts
  the click (one atomic UPDATE, prefetches not counted) and redirects to the stored https link only.
  Same audience as every ad (`AdDisplayService::showsAdsTo()`, split out of `shouldDisplay()`), so
  PlusPlus and exempt roles see none; `hasUnit()` also counts a live slot banner, so the slots show
  one without Adsterra configured. The live list is cached for 60 s and cleared on every save.
  Migration `2026_10_17_000000_create_plugged_host_ad_banners_table`.

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
