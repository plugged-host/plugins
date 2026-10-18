# Changelog

## 2.2.0 (unreleased)

- **No plugged.host defaults for other panels**: the error pages link the status page from
  `STATUS_PAGE_URL` (falling back to `STATUS_RSS_BASE_URL`; new key `plugged-host-core.status_url`)
  and hide the button without one; the maintenance page's Discord button has no built-in invite,
  and its A-ADS ad comes from plugged-host-ads' `maintenance.aads_unit` / `maintenance.width`
  (`ADS_MAINTENANCE_AADS_UNIT`, `ADS_MAINTENANCE_AADS_WIDTH`), shown only while that plugin is
  enabled. Name/favicon fall back to Pelican's own. The suspended page's help button is
  `NEO_SUPPORT_URL`, else `DISCORD_INVITE_URL`, else hidden (with an "ask the administrators"
  line). The welcome tour says `APP_NAME` instead of plugged.host, mentions the Resource Store,
  Plus and tokens only with plugged-host-economy, Backups only with a backups portal, and the
  Minecraft pages only with plugged-host-minecraft.
- **Two panel boxes**: prod's sessions, cache, queue and maintenance mode can live in the
  (replicated) database, so either box can answer any request. Migration
  `010_create_database_cache_tables` adds Laravel's `cache`/`cache_locks` tables and an index on
  `sessions.last_activity`; `DatabaseCachePluginProvider` deletes expired cache rows and locks
  hourly (in batches) while the default cache store is a database one.
- **One version for plugged.host**: `v<major major>.<major>.<patch>.<commit>` (e.g. v2.2.0.9b53c14),
  from the plugins repo's `VERSION` file plus the checkout's commit (`Support\Release`). Shown at
  the foot of every panel page, on error pages, the backups portal, `plugged-host:doctor`, in the
  status export (`release`, for the sites' footers) and to neo. Every plugged.host plugin reports
  that same version, commit included (plugin.json holds `2.2.0`; the commit is added when the
  plugin list is read). `PLUGGED_HOST_RELEASE` overrides it for a deploy without `.git`.
- The console's onboarding callout moved to minecraft and the server settings page styles to
  servers; core's runtime provider no longer names either plugin's pages.
- `AccountSummary` and `PerkDiscovery` moved to plugged-host-economy: core no longer reaches
  into economy, servers or discord.
- **noprofit.space moved out to plugged-host-noprofit** (sign-in, account link and sync,
  `/migrate`, identity lock, SFTP check, `/api/noprofit/*`). Without it the panel uses
  Pelican's own sign-in again. New `AccountPortal` contract: where people manage their account
  and link Discord (the panel itself by default, noprofit.space with that plugin).
- **Abuse protection is its own plugin now** (`plugged-host-abuse`): same tables, routes,
  `ABUSE_*` settings and agent. Core keeps suspension itself (the suspend toggle on the admin
  server list, now `SuspendActionPluginProvider`, and the suspended-server page, which other
  plugins hook with `claimedWhen()` (limbo) and `deletionTimeUsing()` (abuse)).
- **Plugin dependencies are declared and checked**: `plugged_host.requires/optional` in each
  plugin.json, `Plugins::enabled()` / `Plugins::available()` instead of `class_exists()`, and
  `php artisan plugged-host:doctor` with heartbeats for the scheduler and the queue worker.
- **The start chain is core's** (`StartChain`, `StartGate`): one Livewire hook in front of every
  server start; limbo, the start queue and the ad room register as gates. Lived in ads before.
- **Node telemetry is core's** (`NodeTelemetry`, `plugged-host:sample-nodes`): every node probed
  once a minute; servers, storage and status read it instead of the status plugin's tables.
- The console widget swap moved to servers, the credit tables' migration to economy.
- From prod (2.1): **panel backups off per node** (`BACKUPS_DISABLED_NODES`, core settings ›
  Backups; `Support\NodeBackups`): those nodes' servers get no Backups page, its URL goes to
  the console, and `InitiateBackupService` is bound to `NodeAwareInitiateBackupService`, which
  refuses new backups there (panel, client API, schedules, neo).

## 2.1.0

- **One version for plugged.host**: `v<major major>.<major>.<patch>.<commit>` (now v2.1.0.<commit>),
  from the plugins repo's `VERSION` file plus the checkout's commit (`Support\Release`). Shown at
  the foot of every panel page, on error pages, the backups portal, in the status export
  (`release`, for the sites' footers) and to neo. Every plugged.host plugin reports that same
  version, commit included.

## Unreleased (2.1)

- **The welcome tour shows the real panel** (`Livewire\PanelTourOverlay`, steps in
  `Support\PanelTourSteps`): instead of a text card on its own page, it dims the panel and
  spotlights each part in place, with the step beside it (a sheet on phones). Part one runs on
  the server list: the sidebar, usage and balance, the account menu, Create server and the
  server cards, ending with "Show me <server>" (or "Create my first server" for a new account).
  Part two runs on a server's console the first time one is opened: console, power buttons,
  address, the server sidebar, Settings and the way back. Sidebar steps open the sidebar on
  phones. `RequirePanelTour` now sends first-time users to the server list, `/welcome` only
  restarts the tour (user menu, now on the server panel too), and `users.panel_tour` gains
  `server_step` / `server_done_at` (no migration, it is JSON). Tour version default `2`, so
  everyone sees the new tour once.
- **Sign-in details are noprofit.space's only** (`AccountIdentityLockPluginProvider`): the panel
  never changes a user's username, email, password or "managed externally" flag, whatever route
  tries (admin form, application API, client API; the change is put back and logged). On the
  admin user page username and email are read-only, the password field, the toggle and
  upstream's OAuth section with its Unlink buttons are gone, and "create user" is closed
  (`/admin/users/create` 404s). Roles, credits, language and time zone stay editable.
- **Unlinked panel sessions go through noprofit.space once** (`RequireNoprofitLink`, on every
  panel): a user still signed in from before the move (session or remember-me) is signed out
  and sent to sign in, where "Continue with plugged.host" or Discord links the account and
  brings them back. Inert until the link column exists and `NOPROFIT_SSO_SECRET` is set.
- **Admin sidebar regrouped** (`AdminNavigation`, applied by `HiddenNavigationManager`): Servers,
  Users, Announcements & status, Subdomains, Panel, and a collapsed Advanced group.

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
