# plugged.host AI

plugged.host's AI in one plugin:

- **neo** (always lowercase), the agentic assistant: in the panel it carries out tasks on servers
  and accounts with the user's own permissions; on Discord it is account support for anyone.
- **rux**, the Discord moderation and credit giveaway bot.

neo's model calls and usage limits run on this panel (its own AI providers and limits, see
[Standalone setup](#standalone-setup)), or on noprofit.space when plugged-host-noprofit is enabled;
rux uses neo's providers directly either way. Each part has its own tab under Admin → Plugins →
plugged.host AI → Settings.

## neo in the panel

neo (always lowercase) is plugged.host's agentic assistant. Users give it a task in the chat on the
app and server panels and it carries the task out with that user's own permissions: it looks
(console, files, status, account), acts through its tools, checks the result and reports back.

- **On a server:** console and commands, files (list, read, search, write, edit, move, delete,
  compress, extract), power (through the same start queue / waiting room / limbo chain as the
  Start button), startup variables and Docker image, reinstall, backups.
- **Anywhere:** account overview, create / resize / rename / delete servers (plugged-host-servers),
  and reading logs shared on mclo.gs.
- **Minecraft servers:** plugins, mods and modpacks from Modrinth / CurseForge, Geyser, switching
  software (plugged-host-minecraft).

### Where things live

- **This plugin** owns the chat, the agent loop, the tools, approvals, conversation history and
  the profile tab.
- **The AI provider chain** (keys, models, failover), the 5-hour and weekly token limits and the
  usage ledger sit behind three contracts in `src/Contracts`:
  - `NeoBackend`: where a model turn runs and is metered;
  - `NeoSettingsStore`: where the settings form keeps providers and limits;
  - `ProviderSource`: the providers `Chat\ProviderChain` calls (rux, and neo when it runs here).

  `Providers/AiBackendServiceProvider` binds the panel's own (`Services\LocalNeoBackend`,
  `Services\EnvNeoSettingsStore`, `Chat\ConfiguredProviders`). With **plugged-host-noprofit**
  enabled, noprofit.space holds all of it and that plugin binds its own: neo's turns go to
  noprofit.space's `/api/v1/internal/neo/*` (HMAC-signed with `NEO_GATEWAY_SECRET`, set in that
  plugin's settings) and this panel never sees a provider key.

### Settings

Admin → Plugins → plugged.host AI → Settings, tab "neo", edits both: panel values go to this panel's `.env`
(`NEO_ENABLED`, `NEO_MAX_STEPS`, …); providers and limits are saved where the backend keeps them
(this panel's `.env`, or noprofit.space). Use "Test provider" after saving to check a slot answers
and can call tools.

### Standalone setup

Without plugged-host-noprofit, neo runs on this panel:

1. `php artisan migrate` (migration `016_create_neo_usage_tables`: `neo_usage_windows`,
   `neo_usage_events`).
2. Admin → Plugins → plugged.host AI → Settings → neo: paste an API key into at least one provider
   slot, check endpoint and model, save, and press "Test provider".
3. Switch neo on (`NEO_ENABLED`).

The provider and limit settings use noprofit.space's env names, so a chain moves between the two
by copying lines. They go in the panel's `.env` (the form writes them; every one is optional):

| Panel `.env` | Default | |
|---|---|---|
| `NEO_P{1,2,3}_ENABLED`, `_LABEL`, `_DRIVER` (`openai`, `anthropic`, `gemini`), `_ENDPOINT`, `_API_KEY`, `_MODEL`, `_MAX_TOKENS`, `_TIMEOUT`, `_DAILY_TOKEN_CAP` | Groq Qwen, Groq gpt-oss, justwoker Opus, no keys | provider slots, tried in order |
| `NEO_P{n}_EXTRA_HEADERS_B64`, `NEO_P{n}_EXTRA_BODY_B64` | `{}` / the slot's | base64-encoded JSON objects |
| `NEO_SHORT_WINDOW_HOURS`, `NEO_SHORT_TOKENS` | 5, 3000000 | the short window |
| `NEO_LONG_WINDOW_DAYS`, `NEO_LONG_TOKENS` | 7, 15000000 | the long window |
| `NEO_DISCORD_UNLINKED_PERCENT` | 50 | neo on Discord for people who have not linked Discord |
| `NEO_DEFAULT_COOLDOWN`, `NEO_CHAIN_DEADLINE`, `NEO_MAX_BUSY_WAIT`, `NEO_MAX_CONCURRENT` | 60, 80, 60, 4 | failover: cooldown after a failure, time budget of a turn, longest wait that is "busy", model calls at once |
| `NEO_GATEWAY_ENABLED`, `NEO_TOOLS_PROBE` | true, true | neo's turns on/off; ask a slot once whether tools get through |

Limits are tokens (input + output) per account and window, scaled by plugged.host Plus and
PlusPlus (economy), as on noprofit.space. Endpoints must be public `https` URLs
(`Chat\EndpointGuard`, checked on save and test). Every panel user is "linked": there is no
"Activate neo" step.

### Per-user controls (the gear in the chat)

- **Enable neo** (default on): off refuses every request for that user and shrinks the launcher
  to a small dimmed button that only offers "Turn neo on".
- **Let neo make changes without asking** (default off): while off, writes, deletes, console
  commands and power actions wait for Approve / Decline in the chat. Reads never ask.

### Safety

Owners, and subusers the owner gave the **neo → access** permission (admin override access does
not enable neo); permissions re-checked right before each action; secrets masked before anything
reaches a provider; tool output is treated as untrusted; every change is written to the server's
activity log as `server:neo.*`.

### mclo.gs logs ("Ask neo")

With the upstream `mclogs-uploader` plugin installed, neo watches for its "Upload logs" console
action (Laravel's HTTP `ResponseReceived` event, so that plugin stays byte-for-byte upstream).
When mclo.gs finds problems or errors in the upload, the user also gets a notification listing
them with **Ask neo to fix it**. That opens a new chat which starts with `read_mclogs` already run
on the upload (fenced as untrusted tool output, never pasted into the user's message). Only
uploads the user made from that server in the last two hours can be handed over.

### Extending

Other plugins can add tools with `PluggedHost\Ai\Tools\NeoToolRegistry::register(MyTool::class)`
from a provider's `boot()`; a tool implements `PluggedHost\Ai\Tools\Tool`.

### Commands

- `plugged-host-ai:neo-chat {user} "message" --server=<uuid_short> [--approve] [--new]` — drive neo
  from the terminal as that user.
- `plugged-host-ai:neo-prune` — scheduled daily; deletes conversations past `NEO_RETENTION_DAYS`.

## neo on Discord

neo also answers on the plugged.host Discord server, as its own bot. There it is **account
support**: anyone may ask, linked or not, about their plugged.host account (and noprofit.space
account, with plugged-host-noprofit) and how plugged.host works (sign-in, linking Discord, the
allowance, credits and tokens, stores, rewards, locations, hibernation, deleting an account,
where to get help). It is **not server support**: for anything about a server itself it sends people to neo
in the panel (open the server, click neo), which can read the console and files and fix it.

- **When it answers:** DMs, @mentions, replies to neo's messages, and "hey neo, …" (the last
  needs the Message Content intent). Bots are ignored; a few messages per minute per person.
  Several messages sent in a row are answered together.
- **What it sees:** neo's backend says whether the Discord account is linked and to which
  account (this panel's Discord links through plugged-host-discord's `DiscordAccounts`, or
  noprofit.space). In a **DM** with a linked account that uses the panel, neo can look that
  account up with read-only tools from neo's registry: `account_overview`, `list_servers`,
  `list_create_options`. They only ever see that one user. In public channels it has no tools
  and asks people to DM it for their own account. It changes nothing, anywhere.
- **Limits:** every answer is metered by neo's backend (service `plugged-host-discord`). A linked
  Discord account uses its account's 5-hour and weekly windows, shared with neo in the panel. An unlinked one gets windows of its own at
  `NEO_DISCORD_UNLINKED_PERCENT` (50 %) of the limits, set on the neo tab's limits section.
  When a window is used up, neo says when it resets (and, unlinked, that linking gives the full
  limits).
- **Memory:** `neo_bot_messages`, one conversation per person per channel/DM; the last hour is
  replayed. Pruned with neo's conversations after `NEO_RETENTION_DAYS`.
- **Production only:** `NeoBotConfig::blockers()` needs `APP_ENV=production`, the switch on, a
  token, a server id, neo's AI (a usable provider here, or `NEO_GATEWAY_SECRET` with
  plugged-host-noprofit) and the table, so the dev panel never connects a second gateway on the
  same token.

### Setup

1. **Discord Developer Portal → New Application → "neo".** Under **Bot**, reset the token and
   copy it; switch on **Message Content Intent** (for "hey neo"; DMs, @mentions and replies work
   without it).
2. **Admin → Plugins → plugged.host AI → Settings → "neo on Discord":** paste the token and
   switch it on. The supervisor starts the daemon within a minute.
3. **Invite it** with the link on that tab (or `plugged-host:neo-bot-supervise --status`). It
   asks only for view channels, send messages (and in threads), embed links and read history.

### How it runs

Like rux: `plugged-host:neo-bot-gateway` holds the gateway socket (the shared
`Discord\GatewayDaemon`) and stores each message for a detached worker,
`plugged-host:neo-bot-handle <id>`; `plugged-host:neo-bot-supervise` runs every minute, keeps the
daemon up (log `storage/logs/neo-bot.log`) and restarts workers for messages left waiting.

```bash
sudo -u www-data php /var/www/pelican/artisan plugged-host:neo-bot-supervise --status
sudo -u www-data php /var/www/pelican/artisan plugged-host:neo-bot-supervise --stop     # stays down
sudo -u www-data php /var/www/pelican/artisan plugged-host:neo-bot-supervise --start
# Ask as a Discord account, without Discord (metered like a real message; --dm offers the lookups):
sudo -u www-data php /var/www/pelican/artisan plugged-host:neo-bot-ask <discord_id> "how do I get more ram?" --dm
```

## rux

rux is the plugged.host Discord bot for moderation and credit giveaways. Admins give it orders
in plain language (slang and typos are fine) in a DM, by @mentioning it, or by starting a
message with "hey rux":

> hey, can u make me a giveaway that ends on oct 4th at 5pm for 500 credits. dont let anyone who
> won the last 2 gws join

rux posts the giveaway with an **Enter** button. At 17:00 Paris time it draws the winner and
noprofit.space pays them. Nobody who won one of the last two giveaways can enter.

> hey rux, if anyone asks for any new locations can u give them warn

This becomes a standing rule that rux enforces by itself from then on.

> @Helpers can u give them mod permissions, they are helping with moderation on the server

The Helpers role's members can now give rux moderation orders, and the role gets Discord's own
moderation permissions.

### Who rux listens to

- **Admins**: Discord accounts linked to a **noprofit.space admin** account (`users.is_admin`).
  They can order everything except giving credits. noprofit.space is asked on every order, before the model is called.
- **The main admin** (`RUX_CREDIT_GRANTERS`, Discord ids; Admin → Plugins → plugged.host AI → rux)
  can also have rux give someone credits (`give_credits`, at most `RUX_CREDIT_MAX`, 10,000 by
  default, at once). The tool checks the order's author itself, so other admins, moderators,
  standing rules and text rux reads cannot make it pay. noprofit.space books the credits as an
  admin adjustment on the account the person linked Discord to (`POST /api/v1/internal/rux/credits`,
  idempotent per grant) and the credit sync brings the balance to plugged.host.
- **Moderators**: members of a role an admin made a rux moderator role (`set_moderator_role`).
  They can order moderation only: look up members, warn, timeout, kick, ban, unban, change
  nicknames, read, delete and purge messages, lock/slowmode, clear warnings, and see the rules,
  giveaways, case log, bans and role permissions. They only read or delete in channels they can
  see on Discord themselves, and never touch the mod log channel. They can never act on staff,
  noprofit.space admins, other moderators or themselves. Making a role moderators also gives it Discord's Timeout, Kick,
  Ban and Manage Messages permissions, unless the admin says not to. Taking the role back removes
  exactly what rux added.
- **Everyone else** is ignored. In DMs they are told once an hour that rux only takes orders from
  admins; on the server rux stays silent.

rux is a moderation and toolbox bot, not a support bot (neo on Discord is the support bot). Its AI use counts directly against the
providers' API keys, never against anyone's neo limits.

### What rux can do

| Giveaways | Moderation and server |
| --- | --- |
| `create_giveaway`: credits per winner, end time, winners, channel, keep out the winners of the last N giveaways | `lookup_member`: profile, roles, noprofit.space and plugged.host accounts, giveaway wins, past cases |
| `edit_giveaway`: end time, credits, winners, headline | `warn_member`, `timeout_member` (up to 28 days, "0" lifts it), `kick_member`, `ban_member`, `unban_user` |
| `end_giveaway`: draw now | `purge_messages`: the last N messages, optionally from one person |
| `cancel_giveaway`: nobody wins | `read_messages`: the latest in a channel (or one person's), or one message by link |
| `reroll_giveaway`: extra winners, paid too | `delete_messages`: particular messages by link or id, any age |
| `list_giveaways` | `edit_message`: fix rux's own posts, pin/unpin any message (admins) |
| `give_credits`: credits for one person (main admin only) | |
| | `channel_settings`: lock/unlock for @everyone, slowmode |
| | `set_nickname`, `clear_warnings`, `list_cases` (the case log, filterable), `list_bans` |
| | `manage_role`, `role_info` (a role's permissions), `send_message`, `server_overview` |
| **Rules and access** (admins) | |
| `add_rule`, `edit_rule`, `remove_rule`, `list_rules` (moderators too) | `watch_channel`, `set_moderator_role` |

Every moderation action is a case in `rux_cases`. It is posted to the mod log channel when one is
set, and Discord's audit log shows the order ("rux for <admin>: <reason>"). The member gets a DM
with the reason; for kicks and bans the DM goes out before they are removed. When rux acts by
itself (a rule, a watched channel) or deletes chosen messages, the case keeps what the member
said (300 characters), so "why was he warned?" has an answer in `list_cases` and the mod log.

### Warnings

Warnings count for good, until an admin or moderator clears them (`clear_warnings`). By default:

- the **3rd** warning (and the 4th) also times the member out for **24 hours**;
- the **5th** and every later warning bans them for **24 hours**. rux lifts the ban itself
  afterwards (`plugged-host:rux-tick`). A permanent ban replaces a running one, so it is not
  lifted.

Manual warnings, rule warnings and watched-channel warnings all count. The steps can be changed
under Admin → Plugins → plugged.host AI → rux (`RUX_WARN_TIMEOUT_AT`, `RUX_WARN_TIMEOUT`, `RUX_WARN_BAN_AT`,
`RUX_WARN_BAN`; 0 turns a step off).

### Standing rules and watched channels

Both need Discord's **Message Content Intent** (Developer Portal → rux → Bot). If it is off,
Discord refuses the connection (close 4014). rux then reconnects without the intent and tries
again every 6 hours: DMs and @mentions keep working, but rules, watched channels and "hey rux"
do not. The settings page says so.

- **Standing rules** (`add_rule`): "if anyone asks for new locations, warn them". The model turns
  the order into a description and a list of keywords; everyday words are dropped. The gateway
  daemon compares every member message with the keywords, without the network. A hit goes to a
  worker (`plugged-host:rux-rule-check`), which asks the model whether the message really breaks
  the rule. Only then does rux act: warn, timeout, delete, kick or ban, plus a short notice in the
  channel. These checks are paid by the rule's creator.
- **Watched channels** (#chat and #bot-commands from the start; `watch_channel` adds or removes
  more): every member message is queued, and `plugged-host:rux-watch` checks up to 20 of them per
  model call. They are checked against every standing rule (keywords or not) and against the
  always-on guidelines: scams/phishing, slurs, harassment, sexual content, spam, advertising.
  Breaking a guideline means the message is deleted and its author warned
  (`RUX_WATCH_GUIDELINES=false` turns the guidelines off).
- **Limits.** Groq's free tier allows about 8,000 tokens a minute per model, shared with neo's
  own use of the same keys, so limits can be reached. When that happens:
  - A batch that waits too long for a busy AI is skipped.
  - Messages that waited more than 10 minutes are not checked.
  - When the AI refuses rux outright (a bad key), watching pauses for 10 minutes.
- Staff are never checked: the owner, anyone with kick/ban/timeout/manage-messages/manage-server
  permissions, and rux moderators. One rule acts on one member at most once every 5 minutes.

### Giveaways

- **Entering always needs a Discord account linked to noprofit.space.** noprofit.space is asked
  on each entry (the panel's own `discord_links` still holds pre-2.1 links, so it is not used).
  People who are not linked get a button to link on noprofit.space.
- **"The last N giveaways"** are the newest N giveaways on noprofit.space (rux's own, the ones
  made in Admin › Giveaways, and the imported ones). The list is taken when the giveaway is
  created, and rux's reply names those giveaways.
- **The draw** runs at the end time; the daemon checks every 10 seconds, and the scheduler checks
  every minute as a fallback. One process claims the giveaway (`active → drawing`). Winners are
  picked at random (`random_int`) among entries that are still linked on noprofit.space and
  still in the server. noprofit.space pays them under the idempotency key `rux:<id>`
  (`rux:<id>:r<n>` for rerolls), so a retried payout pays nobody twice. The giveaway shows up in
  noprofit.space's Admin › Giveaways as "Drawn by rux on Discord".
- If noprofit.space is down at the end time, the draw waits and is retried every minute. If it is
  down when paying, the payout is retried every minute.
- Members see times as Discord timestamps (in their own time zone). rux reads and writes every
  time in `RUX_TIMEZONE` (Europe/Paris).

### Production only

`RuxConfig::blockers()` refuses to run unless all of these hold:

- `APP_ENV=production`;
- rux is switched on;
- a token and a server id are set;
- the account directory is configured (with plugged-host-noprofit: `NOPROFIT_SYNC_SECRET`), and
  neo's AI providers are (a usable provider here, or `NEO_GATEWAY_SECRET` with
  plugged-host-noprofit);
- the tables exist. Even with a token in its `.env`,
the dev panel never connects a second gateway session.

### Setup

1. **Discord Developer Portal → New Application → "rux".** Under **Bot**, reset the token and
   copy it, and switch on **Message Content Intent**. Standing rules, watched channels and
   "hey rux" need it; DMs and @mentions work without it.
2. **Admin → Plugins → plugged.host AI → rux:** paste the token, switch rux on, and optionally set a default
   giveaway channel and a mod log channel. The supervisor starts the daemon within a minute.
3. **Invite it** with the link shown on the settings page or by
   `php artisan plugged-host:rux-supervise --status`. It asks for the permissions rux uses: view
   channels, send messages (also in threads), embed links, read history, manage messages, manage
   channels, kick, ban, manage roles, time out members. Then drag rux's role above the roles of
   the members it should be able to moderate.

### How it runs

- `plugged-host:rux-gateway` is the daemon: one gateway WebSocket that resumes its session after
  reconnects. It answers giveaway buttons itself. Everything slow runs in a detached worker, so
  the socket keeps heartbeating:
  - orders (`rux_messages`) go to `plugged-host:rux-handle <id>`;
  - messages from watched channels (`rux_watch_queue`) go to `plugged-host:rux-watch`;
  - keyword hits go to `plugged-host:rux-rule-check <key>`.
- `plugged-host:rux-supervise` runs every minute. It reuses the plugged.host bot's supervisor
  (flock liveness, heartbeat, backoff, sticky `--stop`, bounded `storage/logs/rux.log`) with
  rux's own lock under `storage/app/plugged-host/rux/`.
- `plugged-host:rux-tick` runs every minute. It draws due giveaways, retries payouts, refreshes
  entry counts, lifts bans that ran out, and restarts the watched-channel checker when messages
  are waiting.
- `rux_messages` is rux's memory: the last 30 minutes of a conversation (one admin in one
  channel or DM) are replayed. Rows are pruned after 30 days.
- One order at a time per conversation. An order that arrives while the last one is still being
  carried out is answered "still on your last order" and dropped; people resend orders that seem
  slow, and running both would do the thing twice.
- An order whose worker died (a crash, a deploy) gets a new worker from the tick after a minute.
  It is given up if the same person has sent another order since, or after 10 minutes.
- If the AI gives out after tools already ran, rux lists what it did instead of saying it
  could not think.

```bash
sudo -u www-data php /var/www/pelican/artisan plugged-host:rux-supervise --status
sudo -u www-data php /var/www/pelican/artisan plugged-host:rux-supervise --stop     # stays down
sudo -u www-data php /var/www/pelican/artisan plugged-host:rux-supervise --start
sudo -u www-data php /var/www/pelican/artisan plugged-host:rux-supervise --restart
```

### noprofit.space

rux uses three routes on accounts.noprofit.space, signed with `NOPROFIT_SYNC_SECRET`
(`App\Http\Controllers\Api\InternalRuxController`):

- `POST /api/v1/internal/rux/accounts`: the linked accounts behind a list of Discord ids, with
  `is_admin`.
- `POST /api/v1/internal/rux/giveaways/recent`: the newest N giveaways and their winners.
- `POST /api/v1/internal/rux/giveaways`: pay a draw's winners through `App\Services\Giveaways`,
  idempotent on its `rux:` key.

### AI

rux uses neo's providers, 1:1, in neo's order and with neo's failover: whatever is set in
neo's settings (Admin → Plugins → plugged.host AI → neo) is what rux runs (the bound
`ProviderSource`). Standalone they are read from this panel's `.env` as neo uses them. With
plugged-host-noprofit, rux fetches the chain from noprofit.space
(`GET /api/v1/internal/neo/providers`, signed with `NEO_GATEWAY_SECRET`) and fetches it again
once a fetch is a minute old, so a change in neo's settings reaches rux within a minute. The last
chain fetched is kept, encrypted with the app key, in
`storage/app/plugged-host/rux/neo-providers.enc`: while noprofit.space is unreachable rux keeps
using it.

The chain is `Chat\ProviderChain`, shared with neo when neo runs on this panel; rux's cooldowns,
token counts and cache keys (`plugged-host-ai:rux:ai:*`) are its own. The failover rules are
copied from noprofit.space's `ProviderChain`. A provider that fails cools down: 10 minutes for
auth errors; its Retry-After (or Gemini's retry delay, or a minute) for rate limits and outages;
not at all for "request too large". A cooling-down provider is skipped, and each provider's daily
token cap holds per day. The drivers are copies of neo's on noprofit.space (`src/Chat`: OpenAI
chat, Anthropic Messages, and Google AI Studio's Gemini API).

Some gateways drop the tools of a request and put in their own (justwoker since 2026-10-03,
which even runs them on its side). Before giving a provider tools, rux asks it once with a tiny
call whether a tool of rux's gets through, and keeps the answer for 6 hours. For a provider that
drops them, rux describes its tools in the system prompt (`PromptedTools`, names prefixed
`rux_`), names the gateway's own tools as off limits, and reads the model's
`<rux_tool_calls>` block back into ordinary tool calls.

rux calls the providers directly. The model calls never go through neo, and no neo limits
apply. To see what rux has (with plugged-host-noprofit it fetches now):

```bash
sudo -u www-data php /var/www/pelican/artisan plugged-host:rux-ai
```
