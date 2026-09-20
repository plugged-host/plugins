# plugged.host Discord Plugin

A Pelican Panel plugin providing Discord account linking, guild membership verification via the Discord REST API, and automatic panel role assignment (`Discord Linked`).

## Features

- **Discord Account Linking**: Integrates with Pelican's Discord OAuth2 authentication.
- **Guild Membership Verification**: Uses a Discord Bot Token to check whether a linked user is an active member in your Discord server (`GET /guilds/{guild_id}/members/{user_id}`).
- **Panel Role Assignment**: Automatically assigns the configured panel role (e.g. `Discord Linked`) when membership is confirmed.
- **Invite Rewards & 30-Day Anti-Alt Verification**: Tracks invites and verifies joined user account ages via Snowflake timestamps. Only accounts $\ge 30\text{ days}$ old are rewarded.
- **Server Boost Rewards**: Automatically detects active Discord server boosters (1 boost = ~10 invites / 500 credits, 2 boosts = ~25 invites / 1,250 credits) and awards credit bonuses.
- **Discord Bot & `/invites [user]` Slash Command**: Slash command allowing members and administrators to inspect anyone's invite count, valid invites, ineligible alt joins, boost status, and panel credits.
- **Resource Store Synergy**: Credits earned from invites and boosts can be spent directly in the Resource Store to permanently upgrade CPU, RAM, Disk, and Server limits.
- **Resource Limits Synergy**: Assigning the panel role grants the user the resource limits configured on that role under **Admin Area → Roles → Role Resource Limits**.
- **Automated Revocation**: Automatically revokes the panel role if a user unlinks their Discord account or leaves the Discord server.
- **Invite Escrow & Clawback**: An invite counts on join but only pays after the invited member has stayed for the hold period, and is uncounted (with credits recovered) if they leave. A departure is only ever recorded on an affirmative Discord `404` — a timeout, a rate limit or a revoked token leaves the row alone and retries, because uncounting on a maybe silently deletes an inviter's credit. `--restore` re-checks rows marked as gone and puts back any member Discord still reports as present.
- **Sync on Login & Hourly Cron**: Continuously verifies members upon panel login and via scheduled hourly artisan tasks.
- **User Dashboard & Server Banner**: Beautiful user dashboard at `/discord` and server list banner encouraging users to link their Discord account.

## Installation & Setup

1. **Create a Discord Bot & Application**:
   - Visit the [Discord Developer Portal](https://discord.com/developers/applications).
   - Under **Bot**, enable **Server Members Intent** (Privileged Gateway Intents).
   - Copy the **Bot Token**.
   - Invite the bot to your Discord server with member view permissions.
   - Copy your **Server (Guild) ID**.

2. **Configure in Admin Area**:
   - Navigate to **Admin Area → Plugins → plugged.host Discord**.
   - Fill in:
     - **Discord Guild / Server ID**: Your Discord server snowflake ID.
     - **Discord Bot Token**: Your bot's secret token.
     - **Minimum Account Age for Invites**: Default `30` days.
     - **Discord Server Invite URL**: e.g., `https://discord.gg/pluggedhost`.
     - **Assigned Panel Role**: The role to assign (default: `Discord Linked`).
   - Click **Save**.

3. **Register Slash Commands & Start the Bot Gateway**:
   ```bash
   # Register /invites slash command with Discord API
   sudo -u www-data php /var/www/pelican/artisan plugged-host:discord-register-commands

   # Install supervision for the gateway daemon and start it
   sudo -u www-data php /var/www/pelican/artisan plugged-host:discord-bot-install
   ```
   The daemon tracks joins and leaves in real time and answers slash commands over
   the WebSocket gateway. It is optional — membership verification and the hourly
   reconcile work without it — but invite attribution needs it. See
   [Gateway daemon supervision](#gateway-daemon-supervision).

   You should not have to remember that command: if supervision ever stops (a
   rebuilt host, a wiped crontab), the panel notices and reinstalls it by itself.

4. **Configure Role Resource Limits**:
   - Go to **Admin Area → Roles → Discord Linked → Role Resource Limits**.
   - Set the desired CPU, Memory, Disk, and Server limit for Discord-verified users.

## Artisan Commands

```bash
# Synchronize all linked users
sudo -u www-data php /var/www/pelican/artisan plugged-host:discord-sync

# Synchronize a specific user
sudo -u www-data php /var/www/pelican/artisan plugged-host:discord-sync --user=5

# Register slash commands (/invites) with Discord API
sudo -u www-data php /var/www/pelican/artisan plugged-host:discord-register-commands

# Re-check tracked invitees against Discord and uncount the ones who really left
sudo -u www-data php /var/www/pelican/artisan plugged-host:discord-invite-reconcile --dry-run

# Put back invites that were uncounted although the member never left
sudo -u www-data php /var/www/pelican/artisan plugged-host:discord-invite-reconcile --restore --dry-run

# Inspect / control the gateway daemon (see "Gateway daemon supervision" below)
sudo -u www-data php /var/www/pelican/artisan plugged-host:discord-bot-supervise --status
sudo -u www-data php /var/www/pelican/artisan plugged-host:discord-bot-supervise --restart

# Reinstall the one host-level hook supervision needs (idempotent; --dry-run to look first)
sudo -u www-data php /var/www/pelican/artisan plugged-host:discord-bot-install

# Run the gateway daemon in the foreground (debugging; Ctrl-C to stop)
sudo -u www-data php /var/www/pelican/artisan plugged-host:discord-bot
```

Any of these can be typed as `php artisan …` from a root shell — the daemon and
supervisor commands re-exec themselves as `www-data` rather than leave root-owned
files in `storage/`.

## Gateway daemon supervision

The gateway daemon is the only part of plugged.host that has to hold a socket open
for weeks. It used to be a systemd unit, which meant it lived outside `/var/www`:
rebuilding the host lost the bot even though the plugin, the `discord_*` tables and
the bot token were all still there.

It is now supervised by the plugin. `plugged-host:discord-bot-supervise` is
scheduled every minute and starts the daemon whenever nothing is holding its lock,
so the only thing outside `/var/www` that has to exist is the crontab line
Laravel's scheduler needs anyway:

```
* * * * * /usr/bin/php /var/www/pelican/artisan schedule:run >> /dev/null 2>&1
```

**The plugin installs that line itself**, so there is nothing to remember after a
reinstall:

```bash
# idempotent — adds the line only if no schedule:run for this panel is present,
# then starts the daemon
sudo -u www-data php /var/www/pelican/artisan plugged-host:discord-bot-install

# same thing, with the host-level checks (PHP path, cron installed, user exists)
bash /var/www/pelican/plugins/plugged-host-discord/deploy/install-supervision.sh
```

And it repairs itself without being asked. Every supervisor tick stamps
`last_tick_at`; every panel request ends by reading that one integer, and if
nothing has ticked for three minutes the crontab line is written back
(`DiscordSupervisionRepairProvider`). One page load of the panel after a host
rebuild is enough to bring the bot back. That path is deliberately narrow: it runs
after the response rather than in it, does nothing while the scheduler ticks, holds
an atomic cache lock so concurrent requests cannot double-append, appends at most
one line, and refuses outright unless the process really is `www-data` — a root
`artisan` run must never end up with `schedule:run` in root's crontab, because it
would then fill `storage/` with root-owned files that FPM cannot write. Set
`DISCORD_BOT_AUTO_INSTALL_TRIGGER=false` to turn it off.

How it decides what to do:

- **Liveness is an `flock`**, not a PID file. The daemon holds an exclusive lock on
  `storage/app/plugged-host/discord/bot.lock` for its whole life and the kernel
  releases it however the process dies, SIGKILL included — so there is no stale PID
  to reconcile, and a second daemon can never hold a second gateway connection
  (which would double-count every join and leave).
- **A wedged daemon is replaced.** The daemon refreshes a heartbeat in its read
  loop; silence past `supervisor.heartbeat_timeout` (120s) means it is stuck
  somewhere the reconnect logic cannot see, and the supervisor stops and respawns
  it. Separately, the daemon itself drops a socket that has gone quiet for
  `gateway_idle_timeout` (90s) — Discord ACKs a heartbeat every ~41s, so a longer
  gap is a dead connection that never reported EOF.
- **Repeated failures back off** — 60s, 120s, 240s … capped at
  `supervisor.max_backoff` (15m), reset the moment a connection reaches READY. A
  revoked bot token passes every configuration check and only fails at IDENTIFY
  (gateway close 4004); without the backoff an unattended panel would launch and
  lose 1,440 daemons a day.
- **`--stop` is sticky.** It writes a pause flag the scheduler honours, so the
  daemon stays down until `--start`. Use it around a panel upgrade in place of
  `systemctl stop`.
- **The log is bounded.** `storage/logs/discord-bot.log` is rotated to `.1` past
  `supervisor.log_max_bytes` (8 MB), which journald used to take care of.

`--status` reports the scheduler as well as the daemon, because a lost crontab looks
exactly like a healthy panel right up to the next reboot — which is how the bot went
missing unnoticed before. The same verdict is shown as a callout on **Admin Area →
Plugins → plugged.host Discord**.

Settings live under `supervisor` in `config/plugged-host-discord.php`; every one has
an env override (`DISCORD_BOT_SUPERVISOR_ENABLED`, `DISCORD_BOT_AUTO_INSTALL_TRIGGER`,
`DISCORD_BOT_HEARTBEAT_TIMEOUT`, `DISCORD_BOT_RESTART_MAX_BACKOFF`,
`DISCORD_BOT_LOG_MAX_BYTES`, …).

