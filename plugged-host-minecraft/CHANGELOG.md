# Changelog

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
