# Changelog

## 2.2.0 (unreleased)

- **Model picker in neo's chat**: "Other models" (1x usage) or the premium model (the premium
  provider slot, named after its label, e.g. Haiku 5.5; counted at `NEO_PREMIUM_MULTIPLIER`, 2x;
  PlusPlus only via `Support\NeoModel`, a locked option leads to the Plus page). The pick is per
  user (`neo_user_settings.model`, migration 017). Both backends route and meter by it:
  `ProviderChain::turn(..., $premium)` (premium slots first, other turns and rux never reach them)
  and noprofit.space's gateway (`model` on `/turn`). Usage answers carry `premium_model`.
- **rux works without noprofit.space**: who an order comes from, giveaway payouts and
  give_credits go through plugged-host-discord's `DiscordAccounts` / `DiscordCreditPayouts`
  (the panel's own, or noprofit.space's with plugged-host-noprofit). `RuxNoprofit` moved to
  plugged-host-noprofit as `NoprofitRuxClient`. rux's and neo-on-Discord's copy names the
  account portal (`:portal`) instead of noprofit.space.
- neo's chat only links `/sso/link` where that route exists.
- **One provider chain for rux and neo**: rux's copy of noprofit.space's chain (`RuxAi` and its
  translators, `PromptedTools`, the `Ai*Exception`s) moved to `PluggedHost\Ai\Chat`
  (`ProviderChain`), scoped per caller (`for('rux')` keeps rux's cache keys and logs exactly).
  It calls the providers of the bound `Contracts\ProviderSource`: this panel's own
  (`Chat\ConfiguredProviders`, new `local` config block with noprofit.space's env names
  `NEO_P{1..3}_*`, `NEO_DEFAULT_COOLDOWN`, `NEO_CHAIN_DEADLINE`), or noprofit.space's with
  plugged-host-noprofit (`NeoProviders` moved there). `RuxAi` is now a thin wrapper; rux's
  blocker asks whether there are providers instead of checking `NEO_GATEWAY_SECRET`.
  `plugged-host-ai.rux.ai.*` is gone.
- **neo works without noprofit.space.** New contracts `NeoBackend` (turns, Discord turns,
  usage, the activation link) and `NeoSettingsStore` (the settings form's providers and limits).
  Standalone, `LocalNeoBackend` does what noprofit.space's neo gateway does, on this panel: the
  per-account turn lock and concurrency slots, the anchored 5-hour/weekly windows
  (`NeoUsageMeter`, a port of noprofit.space's `NeoUsageService`, Plus/PlusPlus scaling, Discord
  accounts nobody linked at `NEO_DISCORD_UNLINKED_PERCENT`), the provider chain for 'neo', and
  the same answer shapes and exceptions. New migration `016_create_neo_usage_tables`
  (`neo_usage_windows`, `neo_usage_events`). `EnvNeoSettingsStore` keeps providers and limits in
  the panel `.env` with noprofit.space's env names (`NEO_P{n}_*`, `NEO_SHORT_*`, `NEO_LONG_*`,
  `NEO_DISCORD_UNLINKED_PERCENT`, `NEO_MAX_BUSY_WAIT`, `NEO_MAX_CONCURRENT`, `NEO_GATEWAY_ENABLED`,
  `NEO_TOOLS_PROBE`), masks keys, refuses non-public endpoints (`Chat\EndpointGuard`) and tests
  a slot with a one-shot call. With plugged-host-noprofit, `NeoGateway` moved there as
  `NoprofitNeoBackend` (same API calls, cache keys and service names).
- The gateway secret (`NEO_GATEWAY_SECRET`, `NEO_GATEWAY_TIMEOUT`) moved to
  plugged-host-noprofit's config and settings; `plugged-host-ai.gateway_secret`/`gateway_timeout`
  are gone. neo's chat shows "Activate neo" only when the backend has an activation link
  (`NeoBackend::activationUrl()`). neo's texts, its knowledge and its Discord prompt name the
  account portal (`:portal`) and only mention noprofit.space when it is the account system.
  neo-on-Discord takes the panel user from `discordUsage()['user_id']`.
- From prod (2.1): **neo and rux see images.** Screenshots sent to neo or rux on Discord (and
  the replied-to message's) and images in neo's panel chat (button, paste or drop, up to four,
  `Agent\ChatImages` on the private disk, owner-only `/neo/images/{message}/{index}`) reach the
  model as image parts (`Support\MessageContent`, `Support\ImageInput`: shrunk JPEGs). The
  translators send them as OpenAI `image_url`, Gemini `inlineData` and Anthropic image blocks, and
  `ProviderChain` tries the slots that see images first (`seesImages()`: the slot's "Sees images"
  toggle, `NEO_P{n}_VISION` standalone or noprofit.space's, by driver until set), the others get
  a note in their place.
- From prod (2.1): **rux orders try the premium slot first** (`RuxAi::turn(..., premium: true)`);
  message checks never use it. Provider cooldowns now apply (Redis hands the timestamp back as a
  string; `ProviderChain::coolingDownUntil()` wanted an int, so a slot out of quota was asked
  again every few seconds). **Spam without AI** (`Rux\Watch\SpamGuard`, `RUX_SPAM*`,
  `plugged-host:rux-spam`; guideline G5 is gone). Rule checks get `Rux\Rules\ServerFacts`, each
  rule's exceptions (`not_when`, `rux_rules.exceptions`) and the replied-to message
  (`rux_watch_queue.replied_to`): migration `019_add_exceptions_to_rux_rules_table`.

## Unreleased (2.1)

- **Model picker in neo's chat**: "Other models" (1x usage) or noprofit.space's premium model
  (named after its slot's label, e.g. Haiku 5.5; counted at `NEO_PREMIUM_MULTIPLIER`, 2x;
  PlusPlus only, a locked option leads to the Plus page). The pick is per user
  (`neo_user_settings.model`, migration 017) and sent as `model` on each turn; without PlusPlus
  turns stay standard. Provider slots get a "Premium model" switch and the limits a multiplier;
  rux skips premium slots.
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
