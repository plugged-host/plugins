#!/usr/bin/env bash
#
# plugged.host storage agent installer (WP9). Run this ON THE STORAGE BOX as root.
#
#   sudo PH_PANEL_URL=https://panel.example.com PH_AGENT_SECRET=... bash install.sh
#
# It installs the agent code to /opt/plugged-host-storage (the OS disk, never the HDD, so a full
# or faulted /mnt/storage cannot stop the agent self-updating or reporting), writes an absolute
# ExecStart (Node is not assumed to be on PATH), and enables a systemd service.
#
set -euo pipefail

VERSION="1.3.2"
RELEASE_DIR="${PH_RELEASE_DIR:-/opt/plugged-host-storage}"
STORAGE_ROOT="${PH_STORAGE_ROOT:-/mnt/storage}"
ENV_FILE="/etc/plugged-host-storage.env"
UNIT="/etc/systemd/system/plugged-host-storage-agent.service"
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ $EUID -ne 0 ]]; then
  echo "Must run as root." >&2
  exit 1
fi

# --- locate or install Node -------------------------------------------------
find_node() {
  for c in "$(command -v node 2>/dev/null || true)" \
           /usr/bin/node /usr/local/bin/node \
           /root/.nvm/versions/node/*/bin/node; do
    if [[ -n "$c" && -x "$c" ]]; then
      local major
      major="$("$c" -e 'process.stdout.write(String(process.versions.node.split(".")[0]))' 2>/dev/null || echo 0)"
      if [[ "$major" -ge 20 ]]; then echo "$c"; return 0; fi
    fi
  done
  return 1
}

NODE_BIN="$(find_node || true)"
if [[ -z "${NODE_BIN:-}" ]]; then
  echo "No Node.js >= 20 found. Installing via NodeSource (Node 22 LTS)..."
  curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
  apt-get install -y nodejs
  NODE_BIN="$(find_node)"
fi
echo "Using Node: $NODE_BIN ($("$NODE_BIN" --version))"

# --- place the release ------------------------------------------------------
DEST="$RELEASE_DIR/releases/$VERSION"
mkdir -p "$DEST"
cp -f "$SRC_DIR/agent.mjs" "$SRC_DIR/package.json" "$DEST/"
ln -sfn "$DEST" "$RELEASE_DIR/current"
echo "Installed agent $VERSION to $DEST"

# --- storage layout ---------------------------------------------------------
mkdir -p "$STORAGE_ROOT/mirror" "$STORAGE_ROOT/snapshots" "$STORAGE_ROOT/archives" "$STORAGE_ROOT/s3"
if ! mountpoint -q "$STORAGE_ROOT"; then
  echo "WARNING: $STORAGE_ROOT is not a separate mount. mirror/ and snapshots/ MUST share one" >&2
  echo "         filesystem or --link-dest snapshots silently become full copies." >&2
fi

# --- environment file -------------------------------------------------------
if [[ ! -f "$ENV_FILE" ]]; then
  cat > "$ENV_FILE" <<EOF
# plugged.host storage agent configuration
PH_PANEL_URL=${PH_PANEL_URL:-https://panel.example.com}
PH_AGENT_SECRET=${PH_AGENT_SECRET:-$(openssl rand -hex 32)}
PH_STORAGE_ROOT=$STORAGE_ROOT
PH_RELEASE_DIR=$RELEASE_DIR
PH_LISTEN_PORT=${PH_LISTEN_PORT:-8687}
PH_MIN_FREE_PERCENT=${PH_MIN_FREE_PERCENT:-10}
PH_SNAPSHOTS=${PH_SNAPSHOTS:-3}
PH_MIRROR_INTERVAL_MIN=${PH_MIRROR_INTERVAL_MIN:-30}
PH_UPDATE_INTERVAL_MIN=${PH_UPDATE_INTERVAL_MIN:-15}
PH_VERSION=$VERSION
EOF
  chmod 600 "$ENV_FILE"
  echo "Wrote $ENV_FILE — review PH_PANEL_URL, and set the same PH_AGENT_SECRET as STORAGE_AGENT_SECRET on the panel."
else
  echo "$ENV_FILE already exists; leaving it untouched."
  # keep the recorded version in step with what is installed
  sed -i "s/^PH_VERSION=.*/PH_VERSION=$VERSION/" "$ENV_FILE" || true
fi

# --- systemd unit (absolute ExecStart; current symlink so self-update swaps) --
cat > "$UNIT" <<EOF
[Unit]
Description=plugged.host storage agent
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
EnvironmentFile=$ENV_FILE
ExecStart=$NODE_BIN $RELEASE_DIR/current/agent.mjs
Restart=always
RestartSec=5
# The agent shells out to rsync/tar/du/df/cp; keep it unconfined enough to read the HDD.
WorkingDirectory=$RELEASE_DIR/current
StandardOutput=append:/var/log/plugged-host-storage-agent.log
StandardError=append:/var/log/plugged-host-storage-agent.log

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable plugged-host-storage-agent.service
echo
echo "Done. Edit $ENV_FILE (PH_PANEL_URL, PH_AGENT_SECRET), then:"
echo "  systemctl restart plugged-host-storage-agent && journalctl -u plugged-host-storage-agent -f"
