# Changelog

## 2.2.1 (2026-10-23)

- The staff alert webhook posts as "<app name> abuse" (`APP_NAME`) instead of a hardcoded
  "plugged.host abuse".
- Docs: resetting a node's Wings token does not re-key a running agent (it reads the token at
  start): restart it with `systemctl restart plugged-host-abuse-agent` or re-run the installer.
  The installer's example URL is `panel.example.com`.

## 2.2.0 (2026-10-17)

- **Split out of plugged-host-core.** Same tables, routes (`/plugged-host/abuse/agent/*`),
  `ABUSE_*` settings and node agent, so installed agents keep reporting; the classes moved from
  `PluggedHost\Core\...` to `PluggedHost\Abuse\...` and the config from
  `plugged-host-core.abuse.*` to `plugged-host-abuse.*`. The suspend toggle on the admin server
  list stays in core (it is not only for abuse).
- `plugged-host:doctor` warns about nodes whose agent has not reported for 10 minutes, or never.

For the history before the split, see plugged-host-core's changelog (1.3.0: abuse protection
that sees miners, proxies and botnets).

