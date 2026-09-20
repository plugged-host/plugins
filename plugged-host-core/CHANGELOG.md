# Changelog

## 1.2.0

- **Split into focused plugins.** The monolith went from 141 to 44 src files: server
  provisioning left for `plugged-host-servers`, Minecraft tooling for `plugged-host-minecraft`,
  the status pipeline for `plugged-host-status`, and the new `plugged-host-storage` /
  `plugged-host-limbo` plugins were carved out. Every moved class's FQN was remapped tree-wide.
- **Lang + view partition.** The create/configure/onboarding/settings blades and the
  `strings.*` keys they use moved to the plugins that own them; core keeps only what its own
  remaining surfaces render. Nested keys and en/de parity preserved.
- `PerkDiscovery` stays here as the shared perk surface; consumers (`plugged-host-minecraft`
  install screen, `plugged-host-economy` store) now reach it behind a `class_exists` guard, so
  a core that is ever absent degrades rather than 500s.
- Open-source prep: README, this changelog, `@source` annotations, per-panel discovery.
- Version bump to 1.2.0.
