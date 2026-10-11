# Changelog

## Unreleased (2.2)

- Settings tab: the accent colour, one of Tailwind's palettes or a custom colour
  (`THEME_V2_ACCENT`). Fills stay readable with white text and accent text with the sidebar
  behind it (4.5:1); light custom colours are darkened. Also applies to the standalone auth,
  error and backup portal pages.

## Unreleased (2.1)

- Sidebar: Resource Store, plugged.host Plus, Tokens (the Token Store row is gone); the token
  balance opens the Tokens page.

## 1.2.1

- Enforce dark-only mode on all panels (`$panel->darkMode(true, isForced: true)` + `$panel->themeSwitcher(false)`), stripping light mode and the theme switcher dropdown.
- Removed "v2 beta" pills from the sidebar header and auth login/register pages.
- Button behavior and hover jitter fixes: replaced hover scaling with `:active` press transforms, added link button / compact size overrides, and wired notification button to dispatch `open-modal` directly.

## 1.2.0

- **WP2 — Theme v2 for everyone; `nord-theme` deleted.** `ThemeV2Access` collapsed to an
  always-on stub — the per-user preference, `admin_only` mode, identity allowlist, session toggle
  and `/theme-v2/toggle` route all went with nord. Every panel/user now gets Theme v2.
- Profile Theme section removed (`ThemeAwareEditProfile` is now a plain `EditProfile` subclass,
  kept only because it is the registered profile page + Livewire aliases).
- A **"v2 beta" pill** beside the sidebar logo and under the login/register logo.
- `THEME_V2_MODE` rollout switch removed — there is nothing left to configure.
- Deleting nord and flipping the access stub ship together, or a user whose stored
  `customization.theme` was `nord` would 500.
- Version bump to 1.2.0. (Phase C: `yarn build` + the integrity-manifest key swap.)
