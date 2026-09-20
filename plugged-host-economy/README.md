# plugged.host Economy

Both of plugged.host's currencies, their ledgers, and the resource store.

Split out of `plugged-host-core` and `plugged-host-ads` on 2026-08-31, because the
two currencies had ended up in the two plugins that *earn* them rather than in one
place that defines them. Earning stays where it was; the money lives here.

## The two currencies

|  | Credits | Tokens |
|---|---|---|
| Earned by | Discord invites and boosts (`plugged-host-discord`) | Linkvertise trips (`plugged-host-ads`) |
| Spent on | resources: CPU, memory, disk, server slots | priority in the start queue (`plugged-host-queue`) |
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

## What is not here

- **Earning.** `DiscordVerificationService` awards credits; `TokenEarnService` awards
  tokens. Both call into this plugin's models and neither is owned by it.
- **The resource limits themselves.** `RoleResourceLimits` stays in
  `plugged-host-core`: it is the thing the store *modifies*, and server creation
  needs it whether or not an economy exists.
- **`UserResourceLimitsOverview`** — the dashboard stat widget stays in core, for the
  same reason. It reads balances from here.
- **The start queue and its priority pass.** `plugged-host-queue` owns those and
  spends tokens through `TokenService`.

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
