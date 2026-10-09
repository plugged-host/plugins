# Changelog

## Unreleased (2.1)

- neo sends `usage_percent` with every turn and usage call (Plus 125%, PlusPlus 156%; Discord
  turns look the account up through the Discord link), which noprofit.space applies to the limits.
- neo knows there are no private tickets (human help in the Discord server's public channels)
  and that legal/privacy complaints go to legal@plugged.host / privacy@plugged.host; its
  knowledge covers plugged.host Plus/PlusPlus and the new store pricing.

## 1.3.0

- **plugged.host AI.** The plugin was `plugged-host-neo` ("plugged.host neo"); it is now
  `plugged-host-ai` (namespace `PluggedHost\Ai`, config `plugged-host-ai.*`, translations and
  views `plugged-host-ai::`). Env keys (`NEO_*`, `RUX_*`) and tables are unchanged. Commands:
  `plugged-host-ai:neo-chat`, `plugged-host-ai:neo-prune`.
- **rux moved in** from `plugged-host-rux` (removed): its code under `src/Rux`, its config under
  `plugged-host-ai.rux`, its texts under `strings.rux`, its migrations (001–006) and commands
  (`plugged-host:rux-*`, unchanged). Its settings are the plugin's "rux" tab.
- **neo on Discord** (ships dark, `NEO_BOT_ENABLED=false`): account support on the plugged.host
  Discord server for anyone, linked or not, by DM, @mention, reply or "hey neo". Not server
  support: for a server, neo sends people to neo in the panel. Read-only account lookups
  (`account_overview`, `list_servers`, `list_create_options`) in DMs for linked accounts.
  Metered by noprofit.space: a linked Discord account uses its account's limits, an unlinked
  one gets windows of its own at `NEO_DISCORD_UNLINKED_PERCENT` (50) of them. Its own Discord
  application and token, production panel only. Commands `plugged-host:neo-bot-gateway`,
  `-supervise`, `-handle`, and `plugged-host:neo-bot-ask` to try it from the terminal.
  Migration `015_create_neo_bot_messages_table`.
- rux and neo on Discord share one gateway daemon (`src/Discord/GatewayDaemon`), REST client
  and supervisor base.
- Settings: one tab each for neo, neo on Discord and rux; neo's limits section also sets the
  unlinked Discord share.
- Settings: Google AI Studio (Gemini API) as a provider API format, for Gemini and Gemma models.
- Knowledge: servers can be moved to another location from the Transfer page (plugged-host-servers).

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

## rux (before 1.3.0, as plugged-host-rux)

### rux 1.1.0

- AI: rux fetches neo's providers from noprofit.space by itself and fetches them again every
  minute, so it always runs the chain set in neo's settings. The copy (`RUX_AI_P1..3_*`,
  `plugged-host:rux-copy-ai`) is gone; `plugged-host:rux-ai` shows what rux has. The last chain
  fetched is kept for when noprofit.space is unreachable.
- AI: Google AI Studio (Gemini API: Gemini and Gemma models) as a provider format, like neo.

### rux 1.0.0

- First version: rux, the plugged.host Discord bot for moderation and credit giveaways. It takes
  plain-language orders (slang welcome) by DM, by @mention or by "hey rux".
  - Admins (Discord accounts linked to noprofit.space admins) can order everything. Members of
    rux moderator roles can order moderation only.
  - Giveaways require a linked noprofit.space account, can keep out the winners of the last N
    giveaways, and are drawn and paid automatically.
  - Standing rules ("if anyone asks for new locations, warn them").
  - Watched channels (#chat, #bot-commands) where every message is checked in batches.
  - Warning steps: 3 warnings = 24h timeout, 5 = 24h ban, which rux lifts itself.
  - AI: neo's three providers 1:1 (Groq Qwen, Groq gpt-oss, justwoker) with neo's failover,
    called directly with rux's own copy of the settings (`plugged-host:rux-copy-ai`). No neo
    limits apply.
  - Runs on the production panel only.
