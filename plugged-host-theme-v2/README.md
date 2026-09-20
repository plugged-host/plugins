# plugged.host Theme v2

The panel's theme — deep-blue accents, Space Grotesk display + JetBrains Mono numerics, a
sidebar-only chrome (no topbar) with the wallet chips, notification bell and "New Server" CTA
moved into the sidebar. As of v1.2.0 (WP2) it is the **only** theme: `nord-theme` is deleted and
every panel/user gets Theme v2.

## What it does

- **Serves the theme** on all three panels: `PluggedHostThemeV2Plugin::register()` sets
  `theme()` to the v2 stylesheet, `darkMode(true, isForced: true)` / `themeSwitcher(false)` (dark-only,
  no light mode switcher), `topbar(false)` / `topNavigation(false)`, eager database
  notifications, and the app-panel sidebar navigation.
- **`ThemeV2Access`** collapsed to an always-on stub (`canAccess()` / `shouldRenderTheme()` both
  `true`) — the per-user preference, the `admin_only` mode, the identity allowlist, the session
  toggle and the `/theme-v2/toggle` route all went with nord.
- **Sidebar render hooks** (service provider): the notification mirror button, the "New Server"
  CTA, and the token/credit balance (`ThemeV2Balance`). Every hook renders inside a `try` and returns
  an empty `HtmlString` on failure — a throwing render hook would take the panel down.
- **`ThemeAwareEditProfile`**: the panel profile page, now a plain `EditProfile` subclass (the
  Theme section is gone). Kept as a class because it's the registered `profile()` page + two
  Livewire aliases.

## Config keys / env

None. The former `THEME_V2_MODE` rollout switch was removed — there is nothing left to configure.

## Assets / build

`resources/css/theme.css` (dark-only, ~1650 lines). Fonts (Space Grotesk, JetBrains Mono) load
from Google Fonts via a `HEAD_START` hook — the panel's CSP allows it (the *static* sites
self-host instead). Run `yarn build` after any view/CSS change; the manifest key is
`plugins/plugged-host-theme-v2/resources/css/theme.css`.

## Notes

- Category is `plugin`, not `theme`, on purpose — it does not opt into Filament's theme
  mutual-exclusion logic, and with nord gone there is no other theme to exclude.
- Deleting nord and flipping `ThemeV2Access` must ship in the same change, or users whose stored
  `customization.theme` was `nord` 500 the panel (the theme closure no longer references nord).

## Requirements

Pelican Panel `1.0.0-beta38`. Pairs with `plugged-host-core` (chips fallback) and
`plugged-host-economy` (the sidebar balance).
