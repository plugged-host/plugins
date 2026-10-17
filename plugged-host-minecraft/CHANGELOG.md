# Changelog

## 2.2.0 (unreleased)

- Binds servers' `SoftwareSwitcher`, sets new servers up from `ServerProvisioned`, and draws the
  console's "not set up yet" callout itself (moved from core).
- From prod (2.1): Geyser's config goes where each platform reads it (`config/Geyser-NeoForge`
  on NeoForge, not `config/Geyser-Fabric`), and its Bedrock port claim skips allocations a server
  create has reserved.

## 1.2.1

- Fix: the post-install Geyser restart used the nonexistent `DaemonPowerRepository`; it now uses `DaemonServerRepository::power('restart')`.
- Security: the client-API routes now check subuser permissions like the ServerContent page (`file.read` for `/mods` and `/content`, `file.create` for modpack installs, `file.create` + `control.restart` for Geyser). Any subuser could trigger installs before.
- **SpigotMC** as a third content source on Paper/Purpur servers (new `Services\SpigotCatalog`, read through Spiget; no key, `MINECRAFT_SPIGOTMC_ENABLED` / settings toggle). Free plugins only; installs the latest build from Spiget's CDN, or the linked GitHub release jar for externally hosted ones; others show "External download" with a link. Also available to neo's `search_content` / `install_content` / `content_versions` and the client API (`source=spigotmc`).
- Fix: the content page's search box and "View on …" link follow the selected source; CurseForge modpacks install through the CurseForge job, and modpack builds are no longer limited to the server's current loader.
- **Installed plugins/mods, v2.** Every jar is identified automatically in the background: its own descriptor (plugin.yml / paper-plugin.yml / fabric.mod.json / quilt.mod.json / mods.toml) gives the real name, version and authors; then an exact Modrinth hash or CurseForge fingerprint match, then a strict name match on Modrinth or SpigotMC. Jars are streamed to disk, so memory stays flat. Shows icons, source links, available Modrinth updates and missing plugin dependencies.
- Delete (optionally with the plugin's data folder), enable/disable and one-click update per jar, through the new `InstalledContentService` (also used by the client API's listing).
- **Worlds page** (Aternos-style): every world as a card with Download (zip, queued, link in the notification bell, removed after two hours), Upload (straight to the node, level.dat found anywhere in the archive, Paper `_nether`/`_the_end` kept), Options (level-name, seed, generator settings, type, structures, data packs: starts a new world), Generate (new world, optionally new seed), Use this world, Files and Delete. Replacing a world needs the server stopped and can keep a zip of the old one. New `WorldService`, `WorldTaskJob`, `DeleteServerFileJob`.
- Version bump to 1.2.1.

## 1.2.0

- **New plugin**, extracted from `plugged-host-core` in the v2 re-division: the onboarding
  wizard, player roster, content browser, `server.properties` handling and software switcher.
- **WP12 — CurseForge.** `CurseForgeCatalog` (Core API, degrades cleanly without a key) alongside
  Modrinth; `ServerContent` gained a **Source** toggle (hidden until a key is configured), and
  `InstallCurseForgeModpackJob` installs `.zip` packs (manifest + overrides). Per-row install and
  version lookups branch on the record's platform.
- **WP8 — mod/plugin detection.** `ServerModsPage` lists installed jars and identifies them —
  SHA-1 via Modrinth, else a normalized Murmur2 fingerprint via CurseForge.
- **WP6 — one-click Geyser + Floodgate.** `GeyserService` composes Modrinth artifacts per loader,
  writes config, reserves a Bedrock UDP allocation and restarts (idempotent). Added a **Bedrock
  support** onboarding step, hidden for loaders with no Geyser build; the install runs after any
  reinstall settles so a disk wipe can't drop the jars.
- Owns the onboarding/installing blades and their `strings.*` keys after the core lang/view
  partition (still renders core's `perk-list`, now behind a `class_exists` guard); en/de parity holds.
- **WP7 — client API**: `GET /servers/{server}/mods`, `POST /mods/geyser`.
- Version bump to 1.2.0.
