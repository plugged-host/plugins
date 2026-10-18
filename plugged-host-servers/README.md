# plugged.host Servers

User server provisioning for a Pelican Panel: the create/configure flow, resource plans,
locations (with inline flags + latency), node capacity, role-based resource limits, the
allocation-safe network-ports action, and paid (Plus/Premium) locations. Extracted from
`plugged-host-core` in v1.2.0 (WP1/WP3/WP13).

## What it does

- **Create flow** (`CreateServerPage` → `ConfigureServerPage`, slugs `create-server` /
  `create-server/{game}`): pick a game, location, plan; deploy. All creation goes through
  `AccountAllowance::createServer()` — never a hand-rolled upstream call — and plans/locations
  are re-resolved **server-side** on submit so a tampered payload can't request resources or a
  location it isn't entitled to.
- **Flags**: vendored `flag-icons` SVGs (`resources/flags/4x3/<cc>.svg`) inlined by
  `LocationCatalog::flagSvg()` — no `public/flags` dependency, no HTTP, immune to a tarball
  extract and to flag-icons' `.fi-*` CSS clashing with Filament.
- **Latency**: a "panel → node" badge per location, median of the daemon-probe times the status
  plugin records — read via `PluggedHost\Status\Services\NodeLatency` by class-string.
- **Location tiers (WP13)**: nodes tagged `plus` or `premium` form paid tiers (`plus:<cc>` /
  `premium:<cc>` codes; a node tagged both is premium). Plus locations need plugged.host Plus,
  Premium locations need PlusPlus (plugged-host-economy `MembershipService`, read by
  `Support/PremiumAccess`). When PlusPlus runs out, `plugged-host:plusplus-expire`
  (`Services/PlusPlusExpiry`, every 5 min) moves servers off Premium locations (same country,
  Plus before free) and takes CPU over the allowance off the servers evenly (never below 25%).
  PlusPlus also adds its CPU bonus in `AccountAllowance::forUser`. Tiers never share
  hardware. Each card's tier pill has an (i) tooltip with the tier's uptime: free "No uptime
  guarantee", Plus "Best Performance", Premium "99.99% uptime" (`strings.uptime.*`).
- **Node capacity / account allowance**: `NodeCapacity`, `AvailableResources`, the resource/console
  widgets, and `Support/AccountAllowance`: what an account may use is its noprofit.space plan
  (default tier, or the Discord tier while in the server, plus bought resources). There are no
  role limits and no server count.
- **Lowered allowances**: when an admin lowers an account's base on noprofit.space (economy's
  `AccountLimitsLowered`, from plugged-host-noprofit), `Services/AllowanceTrim` brings its servers
  down to fit the way the other decreases do: what they use beyond the allowance, up to what was
  taken away, comes off every server evenly (`Support/EvenCuts`), CPU never below 25%, memory
  never below 1024 MB, disk never below what the server stores plus 1 GB (a server whose node
  does not answer keeps its disk). An account that was over before keeps that part. Each one is
  kept in `allowance_cuts` (migration 022) by the sync that took on the lower plan, carried out
  right after it (Wings synced live, the owner notified) and swept by
  `plugged-host:allowance-cuts` every 5 min; the doctor warns about any waiting 15 min.
  `AllowanceTrim`, `MemoryTrim` and PlusPlusExpiry's CPU trim run under the account's
  provisioning lock and take only what is over at that moment, so no decrease is taken twice.
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
- **Admin transfers** (Admin → Servers → edit): an icon-only Transfer header action
  (`AdminTransferAction`) replaces upstream's button in the Manage tab, which is hidden through
  `Action`/`ToggleButtons::configureUsing` (names `exclude_transfer` / `server_transfer`). The
  admin picks a location ("best node", automatic) or a specific node from the nodes they can
  access; ports are planned and created automatically (the modal previews them), all backups
  move, and the same orchestrator runs it (`origin = admin`: no self-service switch, cooldown,
  suspension or paid-tier gate). A **Transfers** tab (`AdminTransfers`, `?tab=ph-transfers`)
  shows the running move with nodes, attempt, nodes tried and raw wings errors, Cancel, a
  Release control for a move that ended STUCK (keeps the server on its old node), and history.
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
| `user-creatable-servers.{database,allocation,backup}_limit` | `UCS_DEFAULT_{DATABASE,ALLOCATION,BACKUP}_LIMIT` | Per-server feature limits of a created server |
| `user-creatable-servers.deployment_tags` | `UCS_DEPLOYMENT_TAGS` | Node tags eligible for deployment |
| `user-creatable-servers.plans` / `catalog` | — | Resource presets + per-egg software variants |
| `user-creatable-servers.plus.tag` | `UCS_PLUS_TAG` (`plus`) | Plus node tag |
| `user-creatable-servers.premium.tag` | `UCS_PREMIUM_TAG` (`premium`) | Premium node tag |
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
- `010_add_premium_unlocked_at_to_users.php` — the old one-time Plus/Premium unlock (WP13). No
  longer read: its holders became plugged.host Plus (economy migration 015); kept as the record.
- `011_create_server_relocations_table.php` — one row per server move. **Operator-run**; the
  Transfer page and the watcher stay hidden/inert until it exists (`ServerRelocation::ready()`).
- `012_drop_role_resource_limits_table.php` — drops the old role limits table (role limits are
  gone, see `AccountAllowance`). **Operator-run**; nothing reads the table any more.

## Extension points it offers / consumes

- Offers: `AccountAllowance::createServer()`, `LocationCatalog`, `PlanCatalog`, `GameCatalog`,
  `CardSelect`, `PremiumAccess`, `PlusPlusExpiry`, and API routes under `/api/client/provision/*`.
- Consumes (all by class-string behind `class_exists()`): status `NodeLatency` (latency badge),
  economy `MembershipService` (Plus/PlusPlus), minecraft `ServerOnboarding::markRequired` (post-create),
  subdomains `Subdomain::upsertOnCloudflare()` (after a transfer).
- Offers `TransferService::start()` to other plugins: the start queue's "move to a quieter node"
  hands its candidate nodes to it (`origin = queue`, starts the server on arrival).

## Requirements

Pelican Panel `1.0.0-beta38`. `plugged-host-core` (base). Optional: `plugged-host-status`
(latency), `plugged-host-economy` (paid tiers), `plugged-host-minecraft` (onboarding).
