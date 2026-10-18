# plugged.host Minecraft

Minecraft onboarding, content, and mod tooling for a Pelican Panel. Extracted from
`plugged-host-core` in v1.2.0 (WP1) and extended with CurseForge (WP12), mod detection (WP8) and
one-click Geyser (WP6).

## What it does

- **Onboarding wizard** (`ServerOnboardingPage`): software + version, players/operators, access
  mode, and an optional modpack — run once for a panel-created server (gated by
  `RequireServerOnboarding` / `RedirectToServerInstallingPage`, which moved here from core).
- **Player roster, content browser, server.properties editor, software switcher.**
- **Content — Modrinth + CurseForge**: `ModrinthCatalog` (keyless) and `CurseForgeCatalog`
  (needs `CURSEFORGE_API_KEY`; degrades cleanly without it). Install mods/plugins, and full
  modpack installs via `InstallModpackJob` (`.mrpack`) and `InstallCurseForgeModpackJob`
  (`.zip` + `manifest.json` + `overrides/`), both two-hop (switch loader → wait for reinstall →
  place files).
- **Mod detection (`ServerModsPage`)**: lists installed jars, identifies them cheaply by name and
  exactly on demand — SHA-1 via Modrinth, else normalized **Murmur2** fingerprint via CurseForge.
- **One-click Geyser + Floodgate (`GeyserService`)**: composes Modrinth artifacts for the loader,
  writes config, reserves a Bedrock UDP allocation, restarts; idempotent (install vs update).

## The egg

`egg/egg-minecraft-java.json` is the Minecraft: Java Edition egg (Purpur, Fabric, Forge and
NeoForge in one, `MC_SOFTWARE` picks), exported from plugged.host's panel. servers' catalog
(`plugged-host-servers.user-creatable-servers.catalog`) is keyed to its UUID
(`2f6a1c58-9e4b-4d7a-8c31-5b0e7f9a4d62`), and the Minecraft pages only appear on servers of a
catalog egg.

```bash
php artisan plugged-host:minecraft-setup             # imports the egg when no egg has its UUID
php artisan plugged-host:minecraft-setup --dry-run   # says what it would do
php artisan plugged-host:minecraft-setup --update    # imports the file over the existing egg
```

Without `--update` an existing egg is left alone. `--update` replaces that egg's install script,
variables, startup commands, images and config with the file's; a variable the file does not
have is deleted, with every server's value for it. Servers are not changed: they keep their own
startup command, image and variable values, and the install script only runs at a reinstall.
Imported by the command, the egg's description, its installer's banner and the User-Agent the
installer sends PaperMC name this panel (`APP_NAME`, `MINECRAFT_USER_AGENT`) instead of
plugged.host. `plugged-host:doctor` warns while an egg the catalog lists is missing.

## Config keys / env

| Config (`plugged-host-minecraft.*`) | Env | Meaning |
|---|---|---|
| `curseforge_api_key` | `CURSEFORGE_API_KEY` | CurseForge Core API key (optional) |
| `spigotmc_enabled` | `MINECRAFT_SPIGOTMC_ENABLED` (`true`) | SpigotMC (through Spiget) as a source on Paper/Purpur servers |
| `overrides_limit` | `MINECRAFT_OVERRIDES_LIMIT` (`2000`) | Cap on files extracted from a CurseForge modpack's `overrides/` (Modrinth packs have a fixed 96 MiB cap instead) |
| `user_agent` | `MINECRAFT_USER_AGENT` (empty) | User-Agent for Modrinth, CurseForge, Spiget, Mojang and the loaders' APIs. Empty: `<APP_NAME>-panel/<release> (<MAIL_FROM_ADDRESS>)`, or the panel URL as the contact while the address is unset or an `example.com` one |

## Migrations

- `007_create_server_onboarding_table.php`.

## Extension points it offers / consumes

- Offers: `ServerOnboarding::markRequired()` (servers → minecraft seam), `ModrinthCatalog`,
  `CurseForgeCatalog`, `ModDetectionService`, `GeyserService`, `ServerSoftwareSwitcher`, and API
  routes `/api/client/servers/{server}/mods` + `/mods/geyser`.
- Consumes: Pelican daemon repositories (`DaemonFileRepository` etc.), servers' `GameCatalog`,
  core's `PerkDiscovery` (installing page).

## Requirements

Pelican Panel `v1.0.0-rc.1`. `plugged-host-core` and `plugged-host-servers`. CurseForge features
need a valid `CURSEFORGE_API_KEY`; Modrinth is the zero-config default.
