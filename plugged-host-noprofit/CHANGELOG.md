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
