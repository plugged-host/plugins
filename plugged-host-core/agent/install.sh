#!/usr/bin/env bash
#
# plugged.host abuse agent installer. Run on each Wings node as root:
#
#   curl -fsSL https://panel.plugged.host/plugged-host/abuse/agent/install.sh | sudo bash
#
# Re-running it updates the agent. It needs python3 (installed with apt if missing) and the node's
# Wings config, whose token signs the reports: nothing else to configure. The panel fills in its
# own URL below when it serves this file.
#
set -euo pipefail

PANEL_URL="${PH_PANEL_URL:-__PANEL_URL__}"
DEST=/opt/plugged-host-abuse
UNIT=/etc/systemd/system/plugged-host-abuse-agent.service

if [[ $EUID -ne 0 ]]; then
  echo "Run this as root." >&2
  exit 1
fi

CONFIG=""
for candidate in /etc/pelican/config.yml /etc/pterodactyl/config.yml; do
  if [[ -f "$candidate" ]]; then CONFIG="$candidate"; break; fi
done
if [[ -z "$CONFIG" ]]; then
  echo "No Wings config (/etc/pelican/config.yml): is this a Wings node?" >&2
  exit 1
fi

# Run from a copy the panel did not serve (so its URL above was never filled in): use the panel
# Wings itself reports to, the `remote:` line of its config.
if [[ "$PANEL_URL" != http* ]]; then
  PANEL_URL="$(sed -nE "s/^remote:[[:space:]]*['\"]?([^'\"[:space:]#]+).*/\1/p" "$CONFIG" | head -1)"
fi
PANEL_URL="${PANEL_URL%/}"
if [[ "$PANEL_URL" != https://* ]]; then
  echo "Could not work out the panel URL: set PH_PANEL_URL=https://panel.example.com and run again." >&2
  exit 1
fi
echo "Panel: $PANEL_URL"

if ! command -v python3 >/dev/null 2>&1; then
  echo "Installing python3..."
  apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq python3-minimal
fi
PYTHON="$(command -v python3)"
"$PYTHON" -c 'import sys; sys.exit(0 if sys.version_info >= (3, 6) else 1)' || { echo "python3 3.6 or newer is required." >&2; exit 1; }

TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT
curl -fsSL "$PANEL_URL/plugged-host/abuse/agent/abuse-agent.py" -o "$TMP"
"$PYTHON" -c 'import ast, sys; ast.parse(open(sys.argv[1]).read())' "$TMP"
mkdir -p "$DEST"
install -m 0700 -o root -g root "$TMP" "$DEST/abuse-agent.py"

cat > "$UNIT" <<EOF
[Unit]
Description=plugged.host abuse agent
After=network-online.target docker.service
Wants=network-online.target

[Service]
Type=simple
ExecStart=$PYTHON $DEST/abuse-agent.py
Environment=PH_WINGS_CONFIG=$CONFIG
Environment=PH_PANEL_URL=$PANEL_URL
Restart=always
RestartSec=30
Nice=10
IOSchedulingClass=idle
CPUQuota=25%
MemoryMax=256M
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=read-only
PrivateTmp=yes
PrivateDevices=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictSUIDSGID=yes
LockPersonality=yes
CapabilityBoundingSet=CAP_SYS_PTRACE CAP_DAC_READ_SEARCH

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable plugged-host-abuse-agent >/dev/null 2>&1
systemctl restart plugged-host-abuse-agent
sleep 8
systemctl --no-pager --lines=4 status plugged-host-abuse-agent || true
echo
echo "plugged.host abuse agent installed. Logs: journalctl -u plugged-host-abuse-agent -f"
