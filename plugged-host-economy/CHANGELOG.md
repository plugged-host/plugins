# Changelog

## 2.2.1 (2026-10-23)

- **Vylo (vylo.cc) as a second way to earn tokens**: each Earn click shortens a one-time return
  URL carrying the claim's nonce and an HMAC proof, checked against that claim plus a minimum trip
  time. Settings under Ads › Tokens — Earning (`ADS_START_VYLO_*`), off until an API key is set.
  Fix: the Earn button on a static-link card did nothing.
- The Plus page's PlusPlus term ("a week, or credits for two weeks") and the credits FAQ ("credits
  buy two weeks at a time") are built from `PLUSPLUS_TOKEN_DAYS`/`PLUSPLUS_CREDIT_DAYS` instead of
  being written out, so they stay right when the days change (whole weeks read as weeks, anything
  else as days; just the token term when credits are off).
- Self-hosting: "Plus"/"PlusPlus" on the Plus page, its notifications and ledger lines, the Tokens
  page, the admin Economy tab and the settings are named after the panel (`APP_NAME`), not a fixed
  "plugged.host Plus".
- `Events\AccountLimitsLowered`: part of an account's limits was taken away (its base went down
  on noprofit.space) and its servers have to give it back (plugged-host-servers' `AllowanceTrim`
  listens).

## 2.2.0 (2026-10-17)

- The topbar's `AccountSummary` and the "get more resources" perks (`PerkDiscovery`, its widget
  and view) moved here from core; `AccountLimits` is the one definition of what an account may
  use, read by servers' `AccountAllowance` too.
- **Works without noprofit.space.** Plans, tiers, limits and store prices come from
  `ComputePlans`: set in this plugin's settings (Plans & store prices, `COMPUTE_*`,
  `CREDITS_STARTING_BALANCE`) by default, or from noprofit.space when plugged-host-noprofit is
  enabled. The credit sync with noprofit.space moved to that plugin.
- The `user_credits`/`credit_transactions` migration moved here from core (same filename).
- From prod (2.1): `ComputePlans::pool()`: where the plan is this panel's share of a pool other
  services use (noprofit.space's, which plugged.cloud shares), the sidebar, the Resource Store
  and neo show the whole pool and every service's use, as plugged.cloud does. What is left and
  what is enforced are unchanged; `LocalComputePlans` has no pool. The Discord perk points to the
  Discord servers tab with what joining the rest would still add (`DiscordServersStatus`).
- The Plus page lists **VS Code on your server** (plugged-host-devbox) on both plans and in the
  comparison, with each tier's devbox resources from the devbox settings, only while devboxes
  are switched on and migrated (`DevboxAccess::ready()`).

## Unreleased (2.1)

- **plugged.host Plus and PlusPlus** replace the queue's priority passes and the one-time
  Plus/Premium location unlock. `MembershipService` + `user_memberships` (migration 015, run
  2026-10-03: all 54 unlock holders became Plus). Plus: 25 tokens once — Plus locations, queue
  priority, no in-game ads, +25% neo usage. PlusPlus: needs Plus, 10 tokens / 7 days or 150
  credits / 14 days (settings `membership_*`) — Premium locations, no ads anywhere (ads
  exemption), front of the queue, +50% CPU, another +25% neo usage.
- **The Token Store became the plugged.host Plus page** (`PlusPage`, `/plus`, Plus only;
  `/token-store` redirects). The Tokens page (`/tokens`) is in the sidebar again and is earning
  and history only; the store's earn block (`store-earn` view, `STORE_HOOK`) is gone.

- **No resource cap; prices climb instead.** CPU, memory and disk are priced on noprofit.space's
  curve (`Support\ComputePricing`, a copy of noprofit.space's): cheaper on the way to the limit
  (half price at half of it, full price at it), climbing to 4× at the soft limit (125% of the
  limit), doubling every 5% of the limit past it. The store offers 1, 2 and 4 steps, priced as
  the area under the curve; the meter runs to the soft limit. `getPriceForCpu/Memory/Disk()`
  became `price($user, $resource, $amount)` (and `value()` for taking resources back);
  `getUserResourceSummary()` and the `max_*_reached` strings are gone.
- **Prices always come from noprofit.space.** Asked for again when the store renders (older than a
  minute) and before a purchase (older than 30 s); nothing is sold on prices over 15 minutes
  old. noprofit.space pushes new prices the moment an admin saves them (the sync endpoint now
  accepts an empty `items`), and refuses a purchase that paid less than its price (409, undone
  here).
- Credit clawbacks value reclaimed resources at the top of the curve.

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
