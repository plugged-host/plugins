# plugged.host Servers

User server provisioning for a Pelican Panel: the create/configure flow, resource plans,
locations (with inline flags + latency), node capacity, role-based resource limits, the
allocation-safe network-ports action, and paid (Plus/Premium) locations. Extracted from
`plugged-host-core` in v1.2.0 (WP1/WP3/WP13).

## What it does

- **Create flow** (`CreateServerPage` → `ConfigureServerPage`, slugs `create-server` /
  `create-server/{game}`): pick a game, location, plan; deploy. All creation goes through
  `RoleResourceLimits::createServer()` — never a hand-rolled upstream call — and plans/locations
  are re-resolved **server-side** on submit so a tampered payload can't request resources or a
  location it isn't entitled to.
- **Flags**: vendored `flag-icons` SVGs (`resources/flags/4x3/<cc>.svg`) inlined by
  `LocationCatalog::flagSvg()` — no `public/flags` dependency, no HTTP, immune to a tarball
  extract and to flag-icons' `.fi-*` CSS clashing with Filament.
- **Latency**: a "panel → node" badge per location, median of the daemon-probe times the status
  plugin records — read via `PluggedHost\Status\Services\NodeLatency` by class-string.
- **Location tiers (WP13)**: nodes tagged `plus` or `premium` form paid tiers (`plus:<cc>` /
  `premium:<cc>` codes; a node tagged both is premium); one token purchase unlocks a whole tier
  permanently, and unlocking premium also unlocks Plus. Tiers never share hardware. Each card's
  tier pill has an (i) tooltip with the tier's uptime: free "No uptime guarantee", Plus "95%
  uptime", Premium "99.99% uptime" (`strings.uptime.*`).
- **Node capacity / role limits**: `NodeCapacity`, `RoleResourceLimits`, `AvailableResources`,
  the resource/console widgets, and the admin role-limit resource.

## Config keys / env

Owns the **UCS_\*** and **NODES_\*** settings (its own `HasPluginSettings` tab), plus:

| Config (`plugged-host-servers.*`) | Env | Meaning |
|---|---|---|
| `user-creatable-servers.default_*` / limits | `UCS_DEFAULT_*` | Default per-user resource allowance |
| `user-creatable-servers.deployment_tags` | `UCS_DEPLOYMENT_TAGS` | Node tags eligible for deployment |
| `user-creatable-servers.plans` / `catalog` | — | Resource presets + per-egg software variants |
| `user-creatable-servers.plus.tag` | `UCS_PLUS_TAG` (`plus`) | Plus node tag |
| `user-creatable-servers.plus.cost` | `UCS_PLUS_COST` (`20`) | Token cost to unlock the Plus tier |
| `user-creatable-servers.premium.tag` | `UCS_PREMIUM_TAG` (`premium`) | Premium node tag |
| `user-creatable-servers.premium.cost` | `UCS_PREMIUM_COST` (`40`) | Token cost to unlock the premium tier |
| `nodes.hot_percent` / `storage_percent` | `NODES_*` | Node-pressure thresholds |

Env variable spellings are unchanged from when these lived in core, so `.env` needs no edit.

## Migrations

- `001_create_user_resource_limits_table.php`, `002_move_user_resource_limits_to_roles.php`
- `010_add_premium_unlocked_at_to_users.php` — nullable per-user premium-tier flag (WP13). **Must
  be run by the operator**; premium locations are omitted until it exists.
- `011_add_plus_unlocked_at_to_users.php` — nullable per-user Plus-tier flag. Plus locations are
  omitted until it exists.

## Extension points it offers / consumes

- Offers: `RoleResourceLimits::createServer()`, `LocationCatalog`, `PlanCatalog`, `GameCatalog`,
  `CardSelect`, `TierUnlockService`, and API routes under `/api/client/provision/*`
  (`POST /provision/{plus,premium}/unlock`).
- Consumes (all by class-string behind `class_exists()`): status `NodeLatency` (latency badge),
  economy `TokenService` (tier unlocks), minecraft `ServerOnboarding::markRequired` (post-create).

## Requirements

Pelican Panel `1.0.0-beta38`. `plugged-host-core` (base). Optional: `plugged-host-status`
(latency), `plugged-host-economy` (paid tiers), `plugged-host-minecraft` (onboarding).
