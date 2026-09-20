#!/usr/bin/env bash
#
# install-supervision.sh — restore the single host-level hook the Discord gateway
# daemon needs, from inside the plugin that owns the daemon.
#
# The daemon used to be an /etc/systemd unit. That put the one long-lived process
# plugged.host owns outside /var/www, so rebuilding the host lost the bot while
# the plugin code, the discord_* tables and the bot token all survived untouched.
#
# Supervision now lives in the plugin: `plugged-host:discord-bot-supervise` runs
# every minute off Laravel's scheduler and starts the daemon whenever nothing is
# holding its lock. The scheduler itself still needs one crontab line — that line
# is the only thing this installs, and the only thing outside /var/www that has to
# exist for the bot to come back by itself.
#
# The work is done by `artisan plugged-host:discord-bot-install`, so the plugin can
# repair itself with no shell script present at all (it also retries on its own
# whenever a panel request notices the scheduler has stopped ticking). This wrapper
# only exists to be a single memorable line to paste, and to check the obvious
# host-level things before handing over.
#
# Re-running is safe: the line is only added when it is not already present.
#
# Usage:
#   bash /var/www/pelican/plugins/plugged-host-discord/deploy/install-supervision.sh
#   PANEL=/srv/pelican WEBUSER=nginx bash …/install-supervision.sh

set -euo pipefail

PANEL="${PANEL:-/var/www/pelican}"
WEBUSER="${WEBUSER:-www-data}"
PHP="${PHP:-/usr/bin/php}"

fail() { printf '\033[31m✖ %s\033[0m\n' "$1" >&2; exit 1; }

[ -f "${PANEL}/artisan" ] || fail "No artisan at ${PANEL}/artisan — set PANEL=/path/to/pelican."
[ -x "${PHP}" ]           || fail "No PHP interpreter at ${PHP} — set PHP=/path/to/php."
id "${WEBUSER}" >/dev/null 2>&1 || fail "No such user ${WEBUSER} — set WEBUSER=<user>."
command -v crontab >/dev/null 2>&1 || fail "crontab is not installed; install cron first."

# The crontab has to belong to the web user, never to root: a root schedule:run
# would leave root-owned files in storage/ that FPM cannot write. The artisan
# command re-execs itself as ${WEBUSER} when invoked as root, so both paths here
# end up writing the same crontab.
if [ "$(id -u)" -eq 0 ]; then
    "${PHP}" "${PANEL}/artisan" plugged-host:discord-bot-install
elif [ "$(id -un)" = "${WEBUSER}" ]; then
    "${PHP}" "${PANEL}/artisan" plugged-host:discord-bot-install
else
    fail "Run this as root or as ${WEBUSER} (currently $(id -un))."
fi

cat <<EOF

From here the plugin looks after itself:

  status   sudo -u ${WEBUSER} ${PHP} ${PANEL}/artisan plugged-host:discord-bot-supervise --status
  stop     sudo -u ${WEBUSER} ${PHP} ${PANEL}/artisan plugged-host:discord-bot-supervise --stop
  start    sudo -u ${WEBUSER} ${PHP} ${PANEL}/artisan plugged-host:discord-bot-supervise --start
  repair   sudo -u ${WEBUSER} ${PHP} ${PANEL}/artisan plugged-host:discord-bot-install
  log      ${PANEL}/storage/logs/discord-bot.log

--stop is sticky: the scheduler leaves the daemon down until --start. Use it around
a panel upgrade in place of the systemd unit this plugin no longer needs.
EOF
