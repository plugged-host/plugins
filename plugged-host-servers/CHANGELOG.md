# Changelog

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
