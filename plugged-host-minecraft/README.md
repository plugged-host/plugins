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

## Config keys / env

| Config (`plugged-host-minecraft.*`) | Env | Meaning |
|---|---|---|
| `curseforge_api_key` | `CURSEFORGE_API_KEY` | CurseForge Core API key (optional) |
| `overrides_limit` | `MINECRAFT_OVERRIDES_LIMIT` (`2000`) | Cap on files extracted from a modpack's `overrides/` |

## Migrations

- `007_create_server_onboarding_table.php`.

## Extension points it offers / consumes

- Offers: `ServerOnboarding::markRequired()` (servers → minecraft seam), `ModrinthCatalog`,
  `CurseForgeCatalog`, `ModDetectionService`, `GeyserService`, `ServerSoftwareSwitcher`, and API
  routes `/api/client/servers/{server}/mods` + `/mods/geyser`.
- Consumes: Pelican daemon repositories (`DaemonFileRepository` etc.), servers' `GameCatalog`,
  core's `PerkDiscovery` (installing page).

## Requirements

Pelican Panel `1.0.0-beta38`. `plugged-host-core`. CurseForge features need a valid
`CURSEFORGE_API_KEY`; Modrinth is the zero-config default.
