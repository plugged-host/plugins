# Changelog

## Unreleased (2.2)

- **New plugin**: noprofit.space is optional now. Sign-in, the account link, account sync,
  `/migrate`, the identity lock, the SFTP check and the `/api/noprofit/*` endpoints moved here
  from plugged-host-core with the same URLs, route names, env names, cache keys and migration
  filename (`011_add_noprofit_account_id_to_users`), so noprofit.space sees no change. Config
  moved from `plugged-host-core.noprofit.*` to `plugged-host-noprofit.*`.
- Binds `AccountPortal` to noprofit.space.
