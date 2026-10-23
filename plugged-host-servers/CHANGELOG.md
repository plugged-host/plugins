# Changelog

## 2.2.1 (2026-10-23)

- The settings page's SFTP description, copy buttons, identifier and node labels, account
  password row, custom startup and danger-zone note, and the create page's login/game/name
  messages are translatable (`server_settings.*`, `notifications.login_required|select_game|name_invalid`).
- **Vietnamese** (`lang/vi`), in step with en and de.
- **A Go egg that works** (imported over upstream's golang template, egg 16): Go 1.23/1.22
  yolks, an empty server gets a starter web server, a project with a `go.mod` is built on every
  start, or `GO_PACKAGE` installs a Go program. `update_url` is cleared so an egg update does not
  bring the template back.
- **neo's creates link their ports**: `ServerProvisioner` creates and resizes without a Filament
  tenant, so a server neo makes from another server's chat gets its allocation (it used to stay
  "installing" with no ports).
- Settings cleanup: `NODES_HOT_PERCENT` ("Node is busy above") is gone from the settings and the
  config. Nothing acted on it any more: the start queue's own line (`START_QUEUE_THRESHOLD_PERCENT`)
  holds starts back, and the deployment pause takes a node over that line out of new servers.
  `NodeCapacity::hotPercent()`, `isHot()` and `pressureReason()` went with it. `UCS_PREMIUM_COST`
  (unread since the one-time unlock was replaced by Plus) is gone from the config;
  `provision_lock_wait` (`UCS_PROVISION_LOCK_WAIT`, 30 s) is now in it. Leftover `.env` lines are
  harmless.
- Self-hosting: "Plus"/"PlusPlus" are named after the panel (`APP_NAME`) in the notifications,
  settings, transfer refusals and neo's tools, instead of a fixed "plugged.host Plus".
- The README's latency source (core's `NodeTelemetry`), requirements (Pelican `v1.0.0-rc.1`) and
  the deployment pause are up to date.
- **A lower base on noprofit.space takes effect on the servers**: whenever an account's base
  resources go down there (an admin, a lower tier, a Discord server left), what its servers use
  beyond the new allowance, up to plugged.host's share of what was taken away (noprofit.space
  splits it between its services by their servers), comes off every server evenly, CPU, memory
  and disk (`AllowanceTrim`, table
  `allowance_cuts`, migration 022, `plugged-host:allowance-cuts` every 5 min, doctor check
  "Lowered allowances"). `MemoryTrim` (`plugged-host:trim-memory`) and PlusPlusExpiry's CPU trim
  now run under the account's provisioning lock and take no more than is over at that moment, so
  two decreases at once never take the same overage twice.

## 2.2.0 (2026-10-17)

- The server settings' VS Code section (plugged-host-devbox) shows only to people who may have a
  devbox there (`DevboxAccess::isMember()`: owner, or a subuser with VS Code, SFTP and every
  file permission), not to every admin or subuser with the VS Code permission alone, for whom
  it stood empty.
- servers no longer knows plugged-host-minecraft: the settings page's "Server software" section
  goes through a `SoftwareSwitcher` contract a game plugin binds, a new server announces
  `ServerProvisioned` (minecraft sets up onboarding and the EULA), a finished move announces
  `ServerMoved` (subdomains repoints DNS). The settings page styles moved here from core.
- From prod (2.1): a create wings rejects is retried once and then removed, never left
  `installing`; a new server's allocation is reserved with a conditional query (`notReserved()`,
  10 minutes; Geyser's Bedrock claim skips reserved rows too). An egg with `SERVER_WEBPORT`
  (Multi Theft Auto) gets two ports like FiveM (`secondPortVariable()`,
  `findOrCreateAllocationPair()`). `plugged-host:trim-memory` takes back what a noprofit.space
  memory-trim plan asks, evenly (`MemoryTrim`, `Support\EvenCuts`).

## Unreleased (2.1)

- **Plus locations need plugged.host Plus, Premium locations PlusPlus** (`PremiumAccess`, tier
  aware). The one-time unlock (`PremiumLocationService`, `POST /provision/premium/unlock`,
  neo's `unlock_paid_locations`, `UCS_PREMIUM_COST`) is gone; locked cards link to `/plus`.
- **PlusPlus adds its CPU bonus to the allowance**, and `plugged-host:plusplus-expire`
  (`PlusPlusExpiry`, every 5 min) carries out a lapse: servers leave Premium locations (admin
  move, same country, Plus before free) and CPU over the allowance comes off evenly (min 25%).

- **Self-service transfers.** New server page *Transfer*: pick a location (never a node), see the
  data size, backups and an offline-time estimate, press Move. A progress card shows the route,
  a live bar with bytes / speed / ETA read from the source node's websocket, a stepper
  (prepare, stop, copy, backups, verify, start) and a timeline; a strip on every other server
  page links to it while a move runs. Subuser permission `transfer.move`. Settings on the plugin
  tab (`TRANSFERS_*`).
- **Failover around wings' transfer.** Clean stop with kill fallback instead of wings' 15 s stop
  that refuses the transfer; wings' answer is checked (upstream leaves a dangling transfer when
  the source refuses); failed attempts free their ports and retry on another node of the
  location; stalled copies are aborted through the source's own cancel and resolved by probing
  the new node; a move that gives up starts the server again on its old node. The source copy
  is only ever deleted by upstream after the new node verified the checksum. Local backups move
  with the server so they stay restorable.
- Transfers started elsewhere (admin panel, limbo) are adopted by the watcher for progress and
  stall resolution. Owners and admins get database notifications; activity log entries
  `server:transfer.*`.
- **Admin server page**: an icon-only Transfer header action replaces upstream's Manage-tab
  button. Pick a location (automatic node) or a node; ports are created automatically and
  previewed; it runs through the same orchestrator. New Transfers tab with live progress, admin
  details, Cancel, Release for a STUCK move, and history.
- `deploymentPortRanges()` is now public static (used by the planner).
- Migration `011_create_server_relocations_table` (operator-run).
- **No more role limits.** What an account may use is only its noprofit.space plan (default
  tier, or the Discord tier while in the server, plus resources bought there). The *Role Server
  Limits* admin page, the Roles relation tab, the *Servers per account* setting
  (`UCS_DEFAULT_SERVER_LIMIT`) and the server count are gone. `RoleResourceLimits` (an Eloquent
  model) became `Support\AccountAllowance`, a plain value object with the same placement helpers.
  Migration `012_drop_role_resource_limits_table` (operator-run).
- Removed the old two-step create page (`ConfigureServerPage`, `create-server/{game}`), which
  nothing linked to since the single create page replaced it.
- **Fix:** Plus/Premium location cards on the create page lost their tier pill and could be
  selected while locked (the page still read the pre-tier `premium` flag); they show the pill
  and the Unlock button again and cannot be picked until unlocked.

## 1.2.1

- Version bump to 1.2.1 (plugged.host 2.1).

## 1.3.0

- **Plus location tier** alongside Premium. Nodes tagged `plus` (`UCS_PLUS_TAG`) offer `plus:<cc>`
  locations. Plus and Premium are two labels over one unlock: the existing premium purchase
  (`UCS_PREMIUM_COST`, default now 25) opens every paid location, so existing premium holders keep
  everything they had. `LocationCatalog::resolve()` now returns the tier name, and location
  entries carry `tier` instead of `premium`.
- Each location card's tier pill (Free / Plus / Premium) has an (i) with an uptime tooltip:
  "No uptime guarantee" / "Best Performance" / "99.99% uptime".
- Client API: `GET /provision/locations` adds `tier` (`premium` is kept, true for premium only).

## 1.2.0

- **New plugin**, extracted from `plugged-host-core` in the v2 re-division: the create/configure
  pages, resource plans, locations, node capacity, role resource limits, and the allocation-safe
  network-ports action.
- **WP3 — server creation rebuilt.** 248 country-flag SVGs vendored and inlined through
  `LocationCatalog::flagSvg()` (no more `public/flags` 404s); a per-location latency pill fed by
  the `NodeLatency` seam on `plugged-host-status`.
- **WP13 — premium locations.** `premium:<cc>` two-tier catalog, `PremiumAccess` flag (guarded by
  `Schema::hasColumn`), charge-then-refund unlock, and a server-side gate in `RoleResourceLimits`
  that refuses premium hardware for a locked user. Migration `010_add_premium_unlocked_at_to_users`
  (operator-run).
- **WP7 — client API**: `GET /provision/games|plans|locations`, `POST /provision/premium/unlock`.
- Owns the create/configure/settings blades and their `strings.*` keys after the core lang/view
  partition; en/de parity holds.
- Version bump to 1.2.0.
