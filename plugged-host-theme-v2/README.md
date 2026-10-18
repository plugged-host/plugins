# plugged.host Theme v2

The panel's theme — deep-blue accents, Space Grotesk display + JetBrains Mono numerics, a
sidebar-only chrome (no topbar) with the wallet chips, notification bell and "New Server" CTA
moved into the sidebar. As of v1.2.0 (WP2) it is the **only** theme: `nord-theme` is deleted and
every panel/user gets Theme v2.

## What it does

- **Serves the theme** on all three panels: `PluggedHostThemeV2Plugin::register()` sets
  `theme()` to the v2 stylesheet, `darkMode(true, isForced: true)` / `themeSwitcher(false)` (dark-only,
  no light mode switcher), `topbar(false)` / `topNavigation(false)`, eager database
  notifications, and the app-panel sidebar navigation (`ThemeV2Navigation`; its Backups row
  appears only with `plugged-host-storage` enabled and opens that plugin's backups portal, on
  its own host when `BACKUPS_PORTAL_URL` is set, else at the panel's `/backups`).
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

`THEME_V2_ACCENT` (Admin › Plugins › Theme v2 › Settings): the accent colour, a Tailwind preset
name (`blue`, the default, `indigo` … `sky`) or a `#rrggbb` colour. `Support\Accent` maps it onto
every property theme.css sets from the accent (`--phv2-accent*`, `--primary-*`) and prints a
`:root:root` block at `STYLES_AFTER`; blue prints nothing. The standalone pages that declare the
tokens themselves (core's auth/error partial, storage's backup portal) include
`plugged-host-theme-v2::accent` after their own block. No rebuild needed.

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

Pelican Panel `1.0.0-beta38`. Pairs with `plugged-host-core` (chips fallback),
`plugged-host-economy` (the sidebar balance) and `plugged-host-storage` (the Backups row).
