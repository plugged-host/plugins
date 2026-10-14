# plugged.host FiveM

Server pages for FiveM (any egg tagged `cfx`):

- **Server configuration**: the server.cfg txAdmin runs (`txData/default/config.json` →
  `server.dataPath`) as a form: identity, game build, database, keys, resources, group
  memberships and access control, plus the whole file as text. Only changed lines are written.
- **Frameworks**: txAdmin's official recipes (ESX Legacy, QBCore, Qbox, vMenu, the plain Cfx
  server; VORP and the RedM server on RedM), installed in one click into a new
  `txData/<Name>_<HEX>.base` while the server runs, with a database from the server's slots.
  The previous folder is kept.
- **Players**: who is online, from FXServer's `/players.json`, refreshed every 12 seconds.
- **Artifacts** (under More): every Linux build Cfx offers, with Cfx's support dates. Follow
  Recommended (the default) or Latest, so every start updates, or stay on a build.
- **GTAV Enhanced** (under More): switch between FiveM and FiveM for GTAV Enhanced.

## The image

`image/` is `ghcr.io/plugged-host/fivem` (`.github/workflows/fivem-image.yml`). It is the eggs'
install container and the servers' image; `fivem-runtime` keeps `alpine/` on what the egg
variables ask for at every start:

| Variable | Values |
|---|---|
| `FIVEM_VERSION` | `recommended`, `latest` (each start installs that channel's current build) or a build number |
| `FIVEM_EDITION` | `legacy` or `enhanced` (Enhanced always runs Cfx's current build) |

It downloads only from Cfx's hosts, swaps `alpine/` only once a download is complete and
checked, starts the installed build when Cfx cannot be reached, and records what it installed
in `alpine/.plugged-host.json`. `bash image/tests/run.sh` runs its tests offline (the image
build runs them too).

## Setup

```bash
php artisan plugged-host:fivem-setup --servers   # eggs: image, install script, FIVEM_EDITION; servers: image
```

Run it again after importing the FiveM egg. `plugged-host:doctor` reports eggs and servers that
are not on the image.
