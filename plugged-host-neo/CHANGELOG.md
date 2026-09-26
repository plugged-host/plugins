# Changelog

## 1.2.1

- **New plugin.** Ships dark (`NEO_ENABLED=false`).
- Floating chat on the app and server panels, driven one step per request (no queue worker),
  with approval cards for changes and a usage chip for the 5-hour / weekly windows.
- Tools: server status, console read, send command, power (via ads' `interceptFor()` start
  chain), list / read / search / write / edit / create folder / move / delete / compress /
  extract files, and list servers on the app panel. `NeoToolRegistry::register()` for plugins.
- Profile tab: enable neo, let neo act without asking, usage meter, delete history.
- Settings tab edits panel values and noprofit.space's provider slots and limits.
- neo is an agentic assistant: it plans, acts with its tools, verifies the result and reports,
  and acts straight away instead of asking in the chat (approval cards are the only confirmation).
- Tools from other plugins: Minecraft content and software (plugged-host-minecraft), account and
  server management (plugged-host-servers). Subusers need the new "neo → access" permission.
- mclo.gs: `read_mclogs` reads a shared log with its analysis; with the `mclogs-uploader` plugin
  installed, uploads with problems or errors get an "Ask neo to fix it" notification.
- Migrations `012_create_neo_conversations_table`, `013_create_neo_messages_table`,
  `014_create_neo_user_settings_table` (operator-run).
