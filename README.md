# plugged.host plugins

> **Deprecated.** These plugins are no longer maintained and this repository is archived. Since
> 27 October 2026 plugged.host runs on **plugged.host Calagopus**, our own panel based on
> [Calagopus](https://github.com/calagopus/panel), with everything these plugins did built in.
> The code stays here for reference only.

The plugins that turn a [Pelican Panel](https://pelican.dev) into [plugged.host](https://plugged.host),
a free host for game servers, bots and small services. Pelican itself stays unmodified: everything
plugged.host adds lives here and hooks into the panel the way Pelican plugins are meant to.

**Status:** these ran plugged.host in production until 27 October 2026. They are written for plugged.host first, so
expect opinions (plans, ads, our look) rather than a polished general-purpose product. The full
setup guide and a page per plugin are at **[docs.plugged.host](https://docs.plugged.host)**; each
plugin's README and CHANGELOG say what changed and why.

## What's in here

| Plugin | What it does |
|---|---|
| `plugged-host-core` | The platform: suspension, account deletion, panel hardening, error pages, node telemetry, the release version, and the hooks the others share (start chain, navigation, welcome tour, perks, `plugged-host:doctor`) |
| `plugged-host-servers` | Server creation, plans, locations, transfers, node balancing and deployment pauses on busy nodes |
| `plugged-host-economy` | Credits, tokens, plugged.host Plus/PlusPlus, the stores and resource plans |
| `plugged-host-minecraft` | Everything Minecraft: onboarding, Modrinth/CurseForge/Spigot, installed-jar detection, worlds, Geyser |
| `plugged-host-fivem` | FiveM: a `server.cfg` form, frameworks from txAdmin's recipes, players, artifacts with auto-update, and the plugged.host FiveM image |
| `plugged-host-devbox` | VS Code on your server: a private Linux box next to a game server with its files, reached by SSH or in the browser |
| `plugged-host-limbo` | Idle-server hibernation: parks servers nobody uses and brings them back on demand |
| `plugged-host-queue` | A start queue when a node is full |
| `plugged-host-ads` | Ad units, sponsored banners and the ad-funded start room |
| `plugged-host-storage` | Off-node mirrors of every server, automatic node failover and a backups portal, with its own agent (`agent/`) |
| `plugged-host-abuse` | Abuse protection: file/startup/console scans, a CPU watch and a node agent (`agent/`) |
| `plugged-host-status` | Incidents, maintenance and the status export behind [status.plugged.host](https://status.plugged.host) |
| `plugged-host-discord` | Discord membership, rewards and the bot framework |
| `plugged-host-ai` | neo (the assistant in the panel and on Discord) and rux (the Discord moderation bot) |
| `plugged-host-noprofit` | Optional: sign-in, accounts, plans and credits from [noprofit.space](https://noprofit.space). Without it the other plugins use the panel's own |
| `plugged-host-theme-v2` | The look |
| `announcements`, `subdomains`, `mclogs-uploader` | Third-party Pelican plugins (GPL-3.0), `subdomains` with plugged.host changes |

Each `plugin.json` lists what a plugin requires and what it works with
(`plugged_host.requires` / `optional`); `php artisan plugged-host:doctor` checks it on a running
panel, along with the scheduler, the queue, the nodes and the agents.

## Running them

You need a working Pelican Panel (v1.0.0-rc.2) with PHP 8.5, a database, the scheduler
(`schedule:run` every minute) and a queue worker. Then, in the panel's directory:

```bash
git clone https://github.com/plugged-host/plugins.git plugins   # the panel's plugins/ directory
php artisan migrate
yarn install && yarn build                                     # the plugins add their own assets
```

Enable the plugins in **Admin › Plugins** (core first), then go through their settings there.
The step-by-step version, with nginx, the queue worker, nodes and agents, is
[docs.plugged.host](https://docs.plugged.host/start/install-order/).
Most settings are plain `.env` keys; each plugin's `config/` file lists them with defaults.
Nodes run unmodified Wings; the abuse and storage agents come with their own installers.

`main` is what runs on plugged.host; development happens on the release branch (now `2.2`).
`VERSION` is the release, and the panel shows it with the commit (`v2.2.0.<commit>`).

## Security

Please report vulnerabilities privately, see [SECURITY.md](SECURITY.md). Never in a public issue.

## Licence

MIT, © noprofit.space, see [LICENSE](LICENSE). The three third-party plugins listed above keep
their own GPL-3.0 licence. Pelican Panel is a separate project under AGPL-3.0.

Come say hi on [Discord](https://discord.gg/plugged-host-1502647500295635085).
