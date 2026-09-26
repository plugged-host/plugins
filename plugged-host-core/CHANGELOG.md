# Changelog

## 1.2.1

- **noprofit.space SSO hardening (account takeover).** `/sso/callback` finds the panel user only by the stored noprofit account id (`users.noprofit_account_id`, migration `011_add_noprofit_account_id_to_users`, operator-run) or by the plugged.host id noprofit.space linked through a password-proven migration; never by email or username. An email already used by an unlinked panel account shows a "connect your account" page instead of signing in.
- Tokens are audience-scoped (`plugged-host:sso` / `noprofit:migrate`) with separate secrets (`NOPROFIT_SSO_SECRET`, `NOPROFIT_MIGRATION_SECRET`), `iat`/`exp` and single-use nonces. Committed secret defaults removed.
- The panel's own 2FA (authenticator app with recovery codes, email codes) is now enforced after SSO and before `/migrate` issues a token; SSO sign-ins bypassed it before.
- `/migrate`: throttled, fixed callback target, no password hash in the token. New `/sso/link` for signed-in users.
- Fix: a "403 | Forbidden" modal no longer covers the console when a server stops or crashes with the page open (Pelican's charts 403 their own poll once `canView()` turns false; `ConsoleResiliencePluginProvider` lets that poll pass).
- New shared `Services\NoprofitClient` (HMAC-signed calls to noprofit.space's internal API) and `Support\NoprofitLink`.
- Version bump to 1.2.1.

## 1.2.0

- **Split into focused plugins.** The monolith went from 141 to 44 src files: server
  provisioning left for `plugged-host-servers`, Minecraft tooling for `plugged-host-minecraft`,
  the status pipeline for `plugged-host-status`, and the new `plugged-host-storage` /
  `plugged-host-limbo` plugins were carved out. Every moved class's FQN was remapped tree-wide.
- **Lang + view partition.** The create/configure/onboarding/settings blades and the
  `strings.*` keys they use moved to the plugins that own them; core keeps only what its own
  remaining surfaces render. Nested keys and en/de parity preserved.
- `PerkDiscovery` stays here as the shared perk surface; consumers (`plugged-host-minecraft`
  install screen, `plugged-host-economy` store) now reach it behind a `class_exists` guard, so
  a core that is ever absent degrades rather than 500s.
- Open-source prep: README, this changelog, `@source` annotations, per-panel discovery.
- Version bump to 1.2.0.
