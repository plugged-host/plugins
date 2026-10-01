# rux

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

## Who rux listens to

- **Admins**: Discord accounts linked to a **noprofit.space admin** account (`users.is_admin`).
  They can order everything. noprofit.space is asked on every order, before the model is called.
- **Moderators**: members of a role an admin made a rux moderator role (`set_moderator_role`).
  They can order moderation only: look up members, warn, timeout, kick, ban, unban, purge,
  lock/slowmode, clear warnings, and list rules and giveaways. They can never act on staff,
  noprofit.space admins, other moderators or themselves. Making a role moderators also gives it Discord's Timeout, Kick,
  Ban and Manage Messages permissions, unless the admin says not to. Taking the role back removes
  exactly what rux added.
- **Everyone else** is ignored. In DMs they are told once an hour that rux only takes orders from
  admins; on the server rux stays silent.

rux is a moderation and toolbox bot, not a support bot. Its AI use counts directly against the
providers' API keys, never against anyone's neo limits.

## What rux can do

| Giveaways | Moderation and server |
| --- | --- |
| `create_giveaway`: credits per winner, end time, winners, channel, keep out the winners of the last N giveaways | `lookup_member`: profile, roles, noprofit.space and plugged.host accounts, giveaway wins, past cases |
| `edit_giveaway`: end time, credits, winners, headline | `warn_member`, `timeout_member` (up to 28 days, "0" lifts it), `kick_member`, `ban_member`, `unban_user` |
| `end_giveaway`: draw now | `purge_messages`: the last N messages, optionally from one person |
| `cancel_giveaway`: nobody wins | `channel_settings`: lock/unlock for @everyone, slowmode |
| `reroll_giveaway`: extra winners, paid too | `manage_role`, `send_message`, `server_overview` |
| `list_giveaways` | `clear_warnings` |
| **Rules and access** (admins) | |
| `add_rule`, `remove_rule`, `list_rules` | `watch_channel`, `set_moderator_role` |

Every moderation action is a case in `rux_cases`. It is posted to the mod log channel when one is
set, and Discord's audit log shows the order ("rux for <admin>: <reason>"). The member gets a DM
with the reason; for kicks and bans the DM goes out before they are removed.

## Warnings

Warnings count for good, until an admin or moderator clears them (`clear_warnings`). By default:

- the **3rd** warning (and the 4th) also times the member out for **24 hours**;
- the **5th** and every later warning bans them for **24 hours**. rux lifts the ban itself
  afterwards (`plugged-host:rux-tick`). A permanent ban replaces a running one, so it is not
  lifted.

Manual warnings, rule warnings and watched-channel warnings all count. The steps can be changed
under Admin → Plugins → rux (`RUX_WARN_TIMEOUT_AT`, `RUX_WARN_TIMEOUT`, `RUX_WARN_BAN_AT`,
`RUX_WARN_BAN`; 0 turns a step off).

## Standing rules and watched channels

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

## Giveaways

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

## Production only

`RuxConfig::blockers()` refuses to run unless all of these hold:

- `APP_ENV=production`;
- rux is switched on;
- a token and a server id are set;
- noprofit.space (`NOPROFIT_SYNC_SECRET`) is configured, and at least one AI provider is;
- the tables exist. Even with a token in its `.env`,
the dev panel never connects a second gateway session.

## Setup

1. **Discord Developer Portal → New Application → "rux".** Under **Bot**, reset the token and
   copy it, and switch on **Message Content Intent**. Standing rules, watched channels and
   "hey rux" need it; DMs and @mentions work without it.
2. **Admin → Plugins → rux:** paste the token, switch rux on, and optionally set a default
   giveaway channel and a mod log channel. The supervisor starts the daemon within a minute.
3. **Invite it** with the link shown on the settings page or by
   `php artisan plugged-host:rux-supervise --status`. It asks for the permissions rux uses: view
   channels, send messages (also in threads), embed links, read history, manage messages, manage
   channels, kick, ban, manage roles, time out members. Then drag rux's role above the roles of
   the members it should be able to moderate.

## How it runs

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

## noprofit.space

rux uses three routes on accounts.noprofit.space, signed with `NOPROFIT_SYNC_SECRET`
(`App\Http\Controllers\Api\InternalRuxController`):

- `POST /api/v1/internal/rux/accounts`: the linked accounts behind a list of Discord ids, with
  `is_admin`.
- `POST /api/v1/internal/rux/giveaways/recent`: the newest N giveaways and their winners.
- `POST /api/v1/internal/rux/giveaways`: pay a draw's winners through `App\Services\Giveaways`,
  idempotent on its `rux:` key.

## AI

rux uses the same three providers as neo, 1:1. In order, with neo's failover:

1. main: Groq Qwen;
2. backup: Groq gpt-oss;
3. extra backup: justwoker Opus.

The failover rules are copied from noprofit.space's `ProviderChain`. A provider that fails
cools down: 10 minutes for auth errors; its Retry-After (or a minute) for rate limits and
outages; not at all for "request too large". A cooling-down provider is skipped, and each
provider's daily token cap holds per day. The drivers are copies of neo's (`src/Agent/Ai`:
OpenAI chat for Groq, Anthropic Messages for justwoker).

rux calls the providers directly. Nothing goes through noprofit.space or neo, and no neo limits
apply. Its settings are its own copy of neo's `NEO_P1..3_*` (noprofit.space's `.env`) as
`RUX_AI_P1..3_*` in the panel's `.env`. After changing neo's providers, copy them again:

```bash
sudo -u www-data php /var/www/pelican/artisan plugged-host:rux-copy-ai --dry-run
sudo -u www-data php /var/www/pelican/artisan plugged-host:rux-copy-ai
```
