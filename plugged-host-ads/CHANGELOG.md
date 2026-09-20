# Changelog

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
