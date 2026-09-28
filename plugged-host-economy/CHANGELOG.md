# Changelog

## Unreleased (2.1)

- **Server slots are no longer sold.** Accounts have no server count any more (only their plan's
  CPU, memory and disk), so the store's slot row, `CREDITS_PRICE_SERVER_SLOT`, slot reclaiming
  and `user_credits.extra_server_limit` are gone. Migration
  `014_drop_extra_server_limit_from_user_credits` (operator-run; nobody ever bought a slot).
- **Fix:** `POST /api/client/economy/store/purchase` answered 422 even when the purchase worked
  (it checked `ok`, the store returns `success`).

## 1.2.1

- Version bump to 1.2.1 (plugged.host 2.1).

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
