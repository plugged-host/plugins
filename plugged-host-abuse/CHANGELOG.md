# Changelog

## 2.2.0 (unreleased)

- **Split out of plugged-host-core.** Same tables, routes (`/plugged-host/abuse/agent/*`),
  `ABUSE_*` settings and node agent, so installed agents keep reporting; the classes moved from
  `PluggedHost\Core\...` to `PluggedHost\Abuse\...` and the config from
  `plugged-host-core.abuse.*` to `plugged-host-abuse.*`. The suspend toggle on the admin server
  list stays in core (it is not only for abuse).
- `plugged-host:doctor` warns about nodes whose agent has not reported for 10 minutes, or never.

For the history before the split, see plugged-host-core's changelog (1.3.0: abuse protection
that sees miners, proxies and botnets).
