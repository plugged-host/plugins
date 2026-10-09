# Changelog

## Unreleased (2.2)

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
