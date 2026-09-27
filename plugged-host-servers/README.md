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
  `premium:<cc>` codes; a node tagged both is premium). Plus and Premium are two labels over one
  unlock: a single token purchase opens every paid location permanently. Tiers never share
  hardware. Each card's tier pill has an (i) tooltip with the tier's uptime: free "No uptime
  guarantee", Plus "95% uptime", Premium "99.99% uptime" (`strings.uptime.*`).
- **Node capacity / role limits**: `NodeCapacity`, `RoleResourceLimits`, `AvailableResources`,
  the resource/console widgets, and the admin role-limit resource.
- **Transfers** (`Services/Transfers`, server page `ServerTransferPage`, slug `transfer`): the
  owner, or a subuser with the `transfer.move` permission, picks a *location*; `TransferPlanner`
  picks the node inside it by the same rules as creation (deployment tags, tier, disk pressure,
  coolest node) and claims ports on it, keeping the port numbers where they are free.
  `TransferService` is a state machine over one `server_relocations` row per move
  (preparing → stopping → copying → finalizing → starting → completed, with retrying / rollback /
  failed / cancelled / stuck). The copy is wings' own checksummed transfer (server files + the
  server's local backups); around it: the server is marked transferring before anything happens,
  stopped cleanly (killed after `stop_timeout`), wings' answer is checked, a failed attempt frees
  its ports and retries on another node of the location, a stalled one is aborted through the
  source's own cancel, and a move that gives up starts the server again where it was. Nothing on
  the source is ever deleted by this code — only upstream's success callback does that, after the
  new node verified the copy. After the move: port locks/notes carried over, egg `*PORT`
  variables that held an old port follow it, subdomains repointed, server started if it was
  running.
- **Transfer watcher** (`plugged-host-servers:transfers`, `TransferWatcher`): one background
  process (flock on `storage/framework/plugged-host-transfers.lock`) that steps every move each
  second and reads the source node's websocket (`WingsSocket`, a minimal RFC 6455 client) for
  wings' `Uploading … x / y` lines, which is where the progress bar, speed and ETA come from.
  Started by the first move and by the scheduler each minute while there is work; exits when
  idle, hands over after 10 minutes. It also adopts transfers started elsewhere (admin panel,
  limbo) so they get progress and stall resolution too. `--status` lists running moves.

## Config keys / env

Owns the **UCS_\*** and **NODES_\*** settings (its own `HasPluginSettings` tab), plus:

| Config (`plugged-host-servers.*`) | Env | Meaning |
|---|---|---|
| `user-creatable-servers.default_*` / limits | `UCS_DEFAULT_*` | Default per-user resource allowance |
| `user-creatable-servers.deployment_tags` | `UCS_DEPLOYMENT_TAGS` | Node tags eligible for deployment |
| `user-creatable-servers.plans` / `catalog` | — | Resource presets + per-egg software variants |
| `user-creatable-servers.plus.tag` | `UCS_PLUS_TAG` (`plus`) | Plus node tag |
| `user-creatable-servers.premium.tag` | `UCS_PREMIUM_TAG` (`premium`) | Premium node tag |
| `user-creatable-servers.premium.cost` | `UCS_PREMIUM_COST` (`25`) | Token cost of the one unlock (Plus + Premium) |
| `nodes.hot_percent` / `storage_percent` | `NODES_*` | Node-pressure thresholds |
| `transfers.enabled` | `TRANSFERS_ENABLED` (`true`) | Transfer page for owners / permitted subusers |
| `transfers.cooldown_hours` | `TRANSFERS_COOLDOWN_HOURS` (`6`) | Minimum time between two finished moves of a server |
| `transfers.max_attempts` | `TRANSFERS_MAX_ATTEMPTS` (`3`) | Nodes tried before a move gives up |
| `transfers.stop_timeout` | `TRANSFERS_STOP_TIMEOUT` (`90`) | Seconds a clean stop gets before a kill |
| `transfers.stall_minutes` | `TRANSFERS_STALL_MINUTES` (`10`) | No bytes moving this long while copying = stalled |
| `transfers.finalize_minutes` | `TRANSFERS_FINALIZE_MINUTES` (`20`) | Time the new node gets to confirm after the last byte |

Env variable spellings are unchanged from when these lived in core, so `.env` needs no edit.

## Migrations

- `001_create_user_resource_limits_table.php`, `002_move_user_resource_limits_to_roles.php`
- `010_add_premium_unlocked_at_to_users.php` — nullable per-user premium-tier flag (WP13). **Must
  be run by the operator**; paid (Plus and Premium) locations are omitted until it exists.
- `011_create_server_relocations_table.php` — one row per server move. **Operator-run**; the
  Transfer page and the watcher stay hidden/inert until it exists (`ServerRelocation::ready()`).

## Extension points it offers / consumes

- Offers: `RoleResourceLimits::createServer()`, `LocationCatalog`, `PlanCatalog`, `GameCatalog`,
  `CardSelect`, `PremiumLocationService`, and API routes under `/api/client/provision/*`.
- Consumes (all by class-string behind `class_exists()`): status `NodeLatency` (latency badge),
  economy `TokenService` (paid unlock), minecraft `ServerOnboarding::markRequired` (post-create),
  subdomains `Subdomain::upsertOnCloudflare()` (after a transfer).
- Offers `TransferService::start()` to other plugins: the start queue's "move to a quieter node"
  hands its candidate nodes to it (`origin = queue`, starts the server on arrival).

## Requirements

Pelican Panel `1.0.0-beta38`. `plugged-host-core` (base). Optional: `plugged-host-status`
(latency), `plugged-host-economy` (paid tiers), `plugged-host-minecraft` (onboarding).
