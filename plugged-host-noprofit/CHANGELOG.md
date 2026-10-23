# Changelog

## 2.2.1 (2026-10-23)

- `NoprofitRuxSettings`: rux's settings from noprofit.space (`GET /api/v1/internal/rux/settings`,
  signed with the sync secret), reused for 60 s and kept encrypted in
  `storage/app/plugged-host/rux/rux-settings.enc` so rux keeps running while noprofit.space cannot
  be reached. `plugged-host:rux-settings-upload` moves this panel's settings there once
  (`--dry-run`, `--verify`, `--replace`).
- neo's backend streams `/turn`, reports model errors as `ModelUnavailableException`, and sends
  `/usage` once more when the connection to noprofit.space drops.
- The plan noprofit.space sends carries `cut_cpu` / `cut_memory` / `cut_disk`, plugged.host's
  share of what lower bases took so far (an admin, a lower tier, a Discord server left; only ever
  counting up). When they grow and the base went down, `NoprofitCreditSync::adopt()` dispatches
  economy's `AccountLimitsLowered` with the smaller of the two (`lowered()`), in the transaction
  that takes on the plan; plugged-host-servers takes it off the servers. A plan that is lower
  only because plugged.cloud uses more moves no total and cuts nothing.

## 2.2.0 (2026-10-17)

- **New plugin**: noprofit.space is optional now. Sign-in, the account link, account sync,
  `/migrate`, the identity lock, the SFTP check and the `/api/noprofit/*` endpoints moved here
  from plugged-host-core with the same URLs, route names, env names, cache keys and migration
  filename (`011_add_noprofit_account_id_to_users`), so noprofit.space sees no change. Config
  moved from `plugged-host-core.noprofit.*` to `plugged-host-noprofit.*`.
- Binds `AccountPortal` to noprofit.space.
- **Credits**: the credit sync (outbox, `/api/noprofit/credits/sync`, `noprofit:credits-sync`,
  migrations 012/013) moved here from plugged-host-economy unchanged; `NoprofitComputePlans`
  gives economy noprofit.space's plans, tiers and prices.
- **Discord**: rux's noprofit.space client (`NoprofitRuxClient`, was plugged-host-ai's
  `RuxNoprofit`) implements plugged-host-discord's `DiscordAccounts` and `DiscordCreditPayouts`.
  The legacy giveaway export command moved here; migration 017 marks the legacy pending
  recipients it exported, so the panel's own payouts never pay them again.
- **AI**: rux's fetch of neo's providers (`NoprofitNeoProviders`, was plugged-host-ai's
  `NeoProviders`) implements plugged-host-ai's `ProviderSource`; same endpoint, cached file
  (`rux/neo-providers.enc`) and cache keys. Config `plugged-host-noprofit.neo_gateway_secret`
  (`NEO_GATEWAY_SECRET`, was `plugged-host-ai.rux.ai.gateway_secret`), `neo_gateway_timeout`
  (`NEO_GATEWAY_TIMEOUT`) and `neo_providers_refresh`.
- **neo**: plugged-host-ai's `NeoGateway` moved here as `Ai\NoprofitNeoBackend`, plugged-host-ai's
  `NeoBackend` and `NeoSettingsStore` while this plugin is enabled (same `/api/v1/internal/neo/*`
  calls, cache keys and the `plugged-host-discord` service name). The neo gateway secret is a
  field of this plugin's settings now (`NEO_GATEWAY_SECRET`, at least 32 characters), and
  `plugged-host:doctor` checks it while plugged-host-ai is on.
- From prod (2.1): `NoprofitDiscordStatus` (noprofit.space's `/internal/discord/status`, a
  minute's cache) is bound as discord's `DiscordServersStatus`; `NoprofitComputePlans::pool()`
  reads `NoprofitCreditSync::pool()` (`total_*` / `elsewhere_*`, else worked out from `base_*`
  and the resources bought).

