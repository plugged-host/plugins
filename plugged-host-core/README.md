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

## Checking an install

```bash
sudo -u www-data php artisan plugged-host:doctor              # everything
sudo -u www-data php artisan plugged-host:doctor plugged-host-core
sudo -u www-data php artisan plugged-host:sample-nodes        # probe every node now
```
