# plugged.host Economy

Both of plugged.host's currencies, their ledgers, and the resource store.

Split out of `plugged-host-core` and `plugged-host-ads` on 2026-08-31, because the
two currencies had ended up in the two plugins that *earn* them rather than in one
place that defines them. Earning stays where it was; the money lives here.

## The two currencies

|  | Credits | Tokens |
|---|---|---|
| Earned by | Discord invites and boosts (`plugged-host-discord`) | Linkvertise trips (`plugged-host-ads`) |
| Spent on | resources: CPU, memory, disk; PlusPlus | plugged.host Plus and PlusPlus (`MembershipService`) |
| Ledger | `user_credits` + `credit_transactions` | `user_tokens` + `token_transactions` |
| Model | `UserCredits`, `CreditTransaction` | `UserTokens`, `TokenTransaction` |
| Service | `ResourceStoreService`, `CreditRecoveryService` | `TokenService` |

They are not exchangeable, and that is the design rather than a missing feature.
Credits grow the Discord server; tokens pay for hardware. One currency would let
either goal quietly fund the other, and neither number would mean anything.

## Tokens were "start credits"

Until this plugin existed, tokens lived in `plugged-host-ads` as "start credits" and
bought a way past the start-gate ad. That mechanic is gone — an ad currency whose
only use is removing ads earns the host nothing. What survived is the balance: the
migration `plugged-host-ads/database/migrations/002_rename_start_credits_to_tokens.php`
renames the tables in place, so everything earned under the old name is still there.

The rename lives in the **ads** plugin on purpose. Laravel sorts migration files by
name across every registered path, so `002_…` there is guaranteed to run after
`001_create_start_credits_tables` there — which is the table it renames. A `001_` in
this plugin would sort *before* that create on a fresh database and rename nothing.

| Was | Is |
|---|---|
| `ads_start_credits` | `user_tokens` |
| `ads_start_credits.credits` | `user_tokens.tokens` |
| `ads_start_credit_transactions` | `token_transactions` |
| `ads_start_credit_claims` | `token_claims` (stays in `plugged-host-ads` — it is an earn-pipeline artefact) |

## plugged.host Plus and PlusPlus

`MembershipService` + `user_memberships` (migration 015, which made every holder of the old
one-time location unlock Plus). Sold on the plugged.host Plus page (`PlusPage`, `/plus`; the old
`/token-store` redirects there). Plus: tokens, once — Plus locations, queue priority, no in-game
ads, more neo usage. PlusPlus: needs Plus, tokens or credits for N days, extended from its end —
Premium locations, no ads anywhere (an ads exemption registered by
`MembershipServiceProvider`), the front of the queue, `plusplus_cpu_bonus` CPU, and more neo
usage again (`aiUsagePercent()`, sent to noprofit.space as `usage_percent`). Prices and
percentages are in the plugin settings (`membership_*`); the page's "a week, or credits for two
weeks" line and its FAQ are built from the day settings. Every user-facing "Plus"/"PlusPlus" is
named after the panel (`APP_NAME` + " Plus"). What happens when PlusPlus ends is
plugged-host-servers' `PlusPlusExpiry`. The Tokens page (`EarnTokensPage`, `/tokens`) is
earning and history only.

## Resource prices

There is no ceiling on CPU, memory or disk; the price is the brake. noprofit.space owns the
numbers (Admin › Settings: step size and step price per resource, plus the curve) and sends them
with every sync; `NoprofitCreditSync::pricing()` keeps them, asks for them again when the store
renders (older than a minute) and before each purchase (older than 30 s), and stops selling when
the last ones are over 15 minutes old. `Support\ComputePricing` is a copy of noprofit.space's
`App\Services\ComputePricing`; change the two together.

The curve, against the plan's limit (`max_*`):

- up to the limit a step costs its share of the way there (half price at half the limit, never
  under `curve_floor_percent`), the full step price at the limit;
- from the limit to the soft limit (`soft_limit_percent`, 125%) it climbs to
  `soft_limit_multiplier` (4×);
- past the soft limit it doubles every `doubling_percent` (5%) of the limit.

Prices are the area under the curve, so four steps at once cost what four single steps do.
noprofit.space re-prices every `resource_purchase` it receives and refuses an underpaid one with
409, which the panel undoes. `CreditRecoveryService` values reclaimed resources the same way,
off the top of the curve.

## What is not here

- **Earning.** `DiscordVerificationService` awards credits; `TokenEarnService` awards
  tokens. Both call into this plugin's models and neither is owned by it.
- **The resource limits themselves.** They are the account's plan on noprofit.space,
  enforced by `AccountAllowance` in `plugged-host-servers`; the store buys extras that
  noprofit.space adds to the plan. The limits (`max_*`) only set where prices climb.
- **The start queue.** `plugged-host-queue` owns it and reads the priority level from
  `MembershipService` (Plus 1, PlusPlus 2).

## Settings

Admin Area → Plugins → plugged.host Economy. Every field is one flat `.env` key
(`HasPluginSettings` + `EnvironmentWriterTrait` cannot do nested keys), and the form
field names are prefixed `credits_*` / `tokens_*` because the form is flat too.

None of the `CREDITS_*` keys is written to `.env` until someone saves the form, so
until then `config/plugged-host-economy.php` holds the live values.

## Console

```bash
sudo -u www-data php artisan plugged-host:credits-reset --dry-run
```

Zeroes every credit balance and Discord invite counter. Keeps `user_credits.extra_*`
— resources someone already bought stay bought, because taking them back would leave
users allocated above their limit with no way out but deleting servers. Token
balances are not touched.
