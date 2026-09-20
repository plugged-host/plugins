# Flag assets

The SVG country flags in `4x3/` are vendored from **flag-icons** by Panayiotis Lipiridis
(https://github.com/lipis/flag-icons), licensed **MIT**. Only the ISO 3166-1 alpha-2 codes that
`PluggedHost\Core\Support\Countries` knows about are included; they are inlined at render time by
`LocationCatalog::flagSvg()` rather than served from `public/`, so they survive a panel tarball
extract and do not collide with Filament's `.fi-*` CSS namespace.

Refresh with:

```
for cc in <codes>; do
  curl -fsS -o 4x3/$cc.svg https://raw.githubusercontent.com/lipis/flag-icons/main/flags/4x3/$cc.svg
done
```
