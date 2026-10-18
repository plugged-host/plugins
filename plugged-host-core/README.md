# plugged.host Core

The platform the other plugged.host plugins build on. It depends on no other plugin; they
depend on it. What it holds:

- **Plugin dependencies**: every plugin.json declares `plugged_host.requires` / `optional`;
  `Support\Plugins::enabled()` / `available()` answer whether another plugin is really on
  (Pelican autoloads disabled plugins, so `class_exists()` cannot), and
  `php artisan plugged-host:doctor` lists the plugins and runs every enabled plugin's health
  checks (`Doctor\Doctor::check()`), with heartbeats for the scheduler and the queue worker.
- **Shared hooks**: `Power\StartChain` (the one Livewire hook in front of every server start;
  limbo, the start queue and the ad room register as `StartGate`s), `Contracts\AccountPortal`
  (where people manage their account and link Discord: the panel by default,
  noprofit.space with plugged-host-noprofit), `Nodes\NodeTelemetry` (every node probed once a
  minute: reachability, latency, load; servers, storage and status read it), the suspended-server
  page hooks (`claimedWhen()`, `deletionTimeUsing()`).
- **Suspension and accounts**: the suspend toggle on the admin server list, the page a suspended
  server shows its owner, account deletion with its servers.
- **Panel hardening**: a primary allocation for every server, guarded deletion/transfer/build
  services, console and daemon resilience, the session cookie, readable error pages.
- **Navigation and onboarding**: the sidebar order and hidden items, the welcome tour, perks.

Abuse protection is its own plugin (`plugged-host-abuse`), as is the noprofit.space
integration (`plugged-host-noprofit`).

## Settings

Admin › Plugins › plugged.host Core: the welcome tour (`TOUR_ENABLED`, `TOUR_VERSION`) and the
sidebar entries to hide (`NAV_HIDDEN_ITEMS`).

The error pages (5xx and maintenance) read, from `.env`:

- `STATUS_PAGE_URL`: the public status page behind their "Status page" button. Unset, it is
  `STATUS_RSS_BASE_URL` (plugged-host-status's public page); with neither, there is no button.
- The maintenance page's Discord button is `DISCORD_INVITE_URL` (plugged-host-discord), and its
  A-ADS ad is `ADS_MAINTENANCE_AADS_UNIT` / `ADS_MAINTENANCE_AADS_WIDTH` (plugged-host-ads), shown
  only while that plugin is enabled with `ADS_ENABLED` on. Each is left out while empty.
- Name, favicon and logo are the panel's own (`APP_NAME`, `APP_FAVICON`, `APP_LOGO`).

A suspended server's help button links to `NEO_SUPPORT_URL` (plugged-host-ai), else
`DISCORD_INVITE_URL`; with neither the page tells the owner to contact the administrators. The
welcome tour calls the panel by `APP_NAME` and only mentions the Resource Store, Plus and tokens
while plugged-host-economy (and that part of it) is enabled, and Backups while the backups portal
(`BACKUPS_PORTAL_URL`) is set.

## Checking an install

```bash
sudo -u www-data php artisan plugged-host:doctor              # everything
sudo -u www-data php artisan plugged-host:doctor plugged-host-core
sudo -u www-data php artisan plugged-host:sample-nodes        # probe every node now
```
