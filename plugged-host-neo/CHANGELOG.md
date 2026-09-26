# Changelog

## 1.2.1

- **New plugin.** Ships dark (`NEO_ENABLED=false`).
- Floating chat on the app and server panels, driven one step per request (no queue worker),
  with approval cards for changes and a usage chip for the 5-hour / weekly windows.
- Tools: server status, console read, send command, power (via ads' `interceptFor()` start
  chain), list / read / search / write / edit / create folder / move / delete / compress /
  extract files, and list servers on the app panel. `NeoToolRegistry::register()` for plugins.
- Chat history (clock icon): earlier chats of this server (or the dashboard), newest first; reopen one to continue it, or delete it.
- Settings inside the chat (gear icon): enable neo, let neo act without asking, usage with reset times, delete history. No profile tab; with neo off, a small dimmed launcher turns it back on.
- Settings tab edits panel values and noprofit.space's provider slots and limits.
- neo is an agentic assistant: it plans, acts with its tools, verifies the result and reports,
  and acts straight away instead of asking in the chat (approval cards are the only confirmation).
- Tools from other plugins: Minecraft content and software (plugged-host-minecraft), account and
  server management (plugged-host-servers). Subusers need the new "neo → access" permission.
- mclo.gs: `read_mclogs` reads a shared log with its analysis; with the `mclogs-uploader` plugin
  installed, uploads with problems or errors get an "Ask neo to fix it" notification.
- The chat can be resized by dragging its top-left corner (double-click resets; remembered per browser) and minimized while neo keeps working; the launcher shows progress and whether neo finished or needs an approval.
- Chat redesign: mobile-first (full-screen sheet on phones, safe areas, touch-friendly targets, Enter makes a new line on touch screens), icon buttons, compact tool tiles without descriptions (changes waiting for approval keep a collapsible preview), send button that turns into a stop button, 5-hour / weekly usage bars above the message box, and suggestion chips on an empty chat.
- Migrations `012_create_neo_conversations_table`, `013_create_neo_messages_table`,
  `014_create_neo_user_settings_table` (operator-run).
