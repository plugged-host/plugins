# Changelog

## 1.3.0

- **Plus location tier**, between free and premium. Nodes tagged `plus` (`UCS_PLUS_TAG`) offer
  `plus:<cc>` locations, unlocked once for `UCS_PLUS_COST` tokens (default 20). Premium stays on
  `premium`, default cost now 40. Tiers are ranked: a premium unlock also opens Plus, so existing
  premium holders keep every location they had. `PremiumAccess` → `TierAccess`,
  `PremiumLocationService` → `TierUnlockService`, `LocationCatalog::resolve()` now returns the tier
  name, and location entries carry `tier` instead of `premium`.
- Each location card's tier pill (Free / Plus / Premium) has an (i) with an uptime tooltip:
  "No uptime guarantee" / "95% uptime" / "99.99% uptime".
- Client API: `GET /provision/locations` adds `tier` (`premium` is kept, true for premium only);
  new `POST /provision/plus/unlock`.
- Migration `011_add_plus_unlocked_at_to_users` (Plus locations stay hidden until it runs).

## 1.2.0

- **New plugin**, extracted from `plugged-host-core` in the v2 re-division: the create/configure
  pages, resource plans, locations, node capacity, role resource limits, and the allocation-safe
  network-ports action.
- **WP3 — server creation rebuilt.** 248 country-flag SVGs vendored and inlined through
  `LocationCatalog::flagSvg()` (no more `public/flags` 404s); a per-location latency pill fed by
  the `NodeLatency` seam on `plugged-host-status`.
- **WP13 — premium locations.** `premium:<cc>` two-tier catalog, `PremiumAccess` flag (guarded by
  `Schema::hasColumn`), charge-then-refund unlock, and a server-side gate in `RoleResourceLimits`
  that refuses premium hardware for a locked user. Migration `010_add_premium_unlocked_at_to_users`
  (operator-run).
- **WP7 — client API**: `GET /provision/games|plans|locations`, `POST /provision/premium/unlock`.
- Owns the create/configure/settings blades and their `strings.*` keys after the core lang/view
  partition; en/de parity holds.
- Version bump to 1.2.0.
