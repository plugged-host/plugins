#!/usr/bin/env bash
#
# plugged.host storage agent installer (WP9). Run this ON THE STORAGE BOX as root.
#
#   sudo PH_PANEL_URL=https://panel.example.com PH_AGENT_SECRET=... bash install.sh
#
# It installs the agent code to /opt/plugged-host-storage (the OS disk, never the HDD, so a full
# or faulted /mnt/storage cannot stop the agent self-updating or reporting), writes an absolute
# ExecStart (Node is not assumed to be on PATH), and enables a systemd service. It also sets up
# what the agent needs on the box: rsync and pigz, the ph-mirror SSH key it pulls server files
# from the nodes with, a systemd dependency on the storage mount, and (optional) the SSH port of
# each node:
#
#   PH_SSH_PORTS="node1.example.com=22 *.example.com=2222"
#
# writes those as Host blocks in /root/.ssh/config (in a marked block it owns; first match wins,
# so list specific hosts before patterns). Safe to run again: an existing env file and key are
# kept, missing settings are added.
#
# Afterwards: paste the printed public key into the panel (Admin > Plugins > plugged.host
# Storage > Mirror SSH public key), then run on every Wings node
#   curl -fsSL https://<panel>/plugged-host/storage/node/install.sh | sudo bash
#
set -euo pipefail

VERSION="1.3.4"
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

# --- tools the agent shells out to ------------------------------------------
missing=()
for tool in rsync pigz ssh ssh-keygen; do
  command -v "$tool" >/dev/null 2>&1 || missing+=("$tool")
done
if (( ${#missing[@]} )); then
  echo "Installing: rsync pigz openssh-client"
  apt-get update -qq && apt-get install -y rsync pigz openssh-client
fi

# --- the key the agent pulls server files from the nodes with ----------------
RSYNC_KEY="${PH_RSYNC_SSH_KEY:-/root/.ssh/ph-mirror}"
RSYNC_USER="${PH_RSYNC_USER:-root}"
mkdir -p "$(dirname "$RSYNC_KEY")" && chmod 700 "$(dirname "$RSYNC_KEY")"
if [[ ! -f "$RSYNC_KEY" ]]; then
  ssh-keygen -q -t ed25519 -f "$RSYNC_KEY" -N "" -C plugged-host-storage-agent
  echo "Generated the mirror key $RSYNC_KEY"
fi

# --- optional: the SSH port of each node ------------------------------------
if [[ -n "${PH_SSH_PORTS:-}" ]]; then
  SSH_CONFIG="$(dirname "$RSYNC_KEY")/config"
  touch "$SSH_CONFIG" && chmod 600 "$SSH_CONFIG"
  # Replace only the block this script owns.
  sed -i '/^# >>> plugged-host-storage$/,/^# <<< plugged-host-storage$/d' "$SSH_CONFIG"
  {
    echo "# >>> plugged-host-storage"
    for entry in $PH_SSH_PORTS; do
      echo "Host ${entry%%=*}"
      echo "  Port ${entry##*=}"
      echo "  User $RSYNC_USER"
      echo "  IdentityFile $RSYNC_KEY"
    done
    echo "# <<< plugged-host-storage"
  } >> "$SSH_CONFIG"
  echo "Wrote the node SSH ports to $SSH_CONFIG"
fi

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
PH_RSYNC_USER=$RSYNC_USER
PH_RSYNC_SSH_KEY=$RSYNC_KEY
PH_VERSION=$VERSION
EOF
  chmod 600 "$ENV_FILE"
  echo "Wrote $ENV_FILE — review PH_PANEL_URL, and set the same PH_AGENT_SECRET as STORAGE_AGENT_SECRET on the panel."
else
  echo "$ENV_FILE already exists; leaving it untouched."
  # keep the recorded version in step with what is installed
  sed -i "s/^PH_VERSION=.*/PH_VERSION=$VERSION/" "$ENV_FILE" || true
  # settings added after the file was first written
  grep -q '^PH_RSYNC_USER=' "$ENV_FILE" || echo "PH_RSYNC_USER=$RSYNC_USER" >> "$ENV_FILE"
  grep -q '^PH_RSYNC_SSH_KEY=' "$ENV_FILE" || echo "PH_RSYNC_SSH_KEY=$RSYNC_KEY" >> "$ENV_FILE"
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

# Never start before the storage disk is mounted: the agent would mirror onto the OS disk.
if mountpoint -q "$STORAGE_ROOT"; then
  mkdir -p "$UNIT.d"
  printf '[Unit]\nRequiresMountsFor=%s\n' "$STORAGE_ROOT" > "$UNIT.d/10-storage-mount.conf"
fi

systemctl daemon-reload
systemctl enable plugged-host-storage-agent.service
echo
echo "Done. Next:"
echo "  1. Edit $ENV_FILE (PH_PANEL_URL, PH_AGENT_SECRET). Set the same secret, and this box's agent"
echo "     URL (https://<this box>, proxied to port ${PH_LISTEN_PORT:-8687}), in the panel's storage settings."
echo "  2. Paste this mirror public key into the panel's storage settings (Mirror SSH public key):"
echo "       $(cat "$RSYNC_KEY.pub")"
echo "  3. On every Wings node: curl -fsSL https://<panel>/plugged-host/storage/node/install.sh | sudo bash"
echo "  4. systemctl restart plugged-host-storage-agent && journalctl -u plugged-host-storage-agent -f"
