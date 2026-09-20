# Changelog

## 1.2.0

- **Token earning moved here from `plugged-host-ads`.** The user asked for one home for money, so
  the balance, ledger, store and the way you top it up now all live in economy: `TokenEarnService`,
  `TokenClaim`, `EarnTokensPage`, `EarnOffers` and the `Rewards/*` providers (Linkvertise) moved
  over, along with the two start-credits→tokens migrations and the `tokens_*` lang keys. The start
  gate itself stays in ads — it is an ad surface that mentions tokens, not a token feature. Earn
  wiring is now `EarnPluginServiceProvider`; the `plugged-host-economy::store.after-tokens` hook
  string is unchanged.
- **WP4** — a `store-under-tx` ad slot renders under the transaction ledger.
- **WP7 — client API**: `GET /economy/balances`, `GET /economy/transactions`,
  `POST /economy/store/purchase`.
- Store page header widgets (`PerkDiscoveryWidget`, `UserResourceLimitsOverview`) now resolve
  behind `class_exists`, so a missing core/servers drops the widget instead of 500ing the page.
- Version bump to 1.2.0.
