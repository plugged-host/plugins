#!/usr/bin/env bash
#
# plugged.host storage agent: install it on the storage box or the backup server, or upgrade it.
#
# Copy the command from the panel (Admin > Plugins > plugged.host Storage > Setup command, or
# `php artisan plugged-host:storage-setup`, with --backup for the backup server) and run it on
# that box:
#
#   curl -fsSL https://<panel>/plugged-host/storage/agent/install.sh?code=<code> | sudo bash
#
# It asks only what it cannot know (where the storage disk is, the nodes' SSH port, the address
# this agent answers on) and gets everything else from the panel with the one-time code: the
# agent secret, the node list and the agent itself. It installs Node.js 26 if needed, rsync,
# pigz, the agent and its systemd service, creates the key the agent pulls server files with,
# and sends that key and the address back to the panel. Run it again to upgrade: what you
# answered last time is the default.
#
# Unattended: every question has a flag, and --yes takes the defaults.
#   --panel URL           the panel (filled in when the panel serves this script)
#   --code CODE           the setup code (filled in by the panel's command)
#   --storage-root DIR    where mirrors and snapshots go (default /mnt/storage)
#   --ssh-port N          SSH port of the nodes (default 22)
#   --ssh-port-for H=P    a node on another SSH port (repeatable)
#   --agent-url URL       the address Wings and the panel reach this agent at
#   --nginx | --no-nginx  put nginx + a Let's Encrypt certificate in front of it
#   --email ADDR          e-mail for Let's Encrypt (optional)
#   --allow-unmounted     carry on when the storage root is not its own mount
#   --yes                 take every default
#
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

PANEL_URL="__PANEL_URL__"
SETUP_CODE="__SETUP_CODE__"
case "$PANEL_URL" in __*__) PANEL_URL="" ;; esac
case "$SETUP_CODE" in __*__) SETUP_CODE="" ;; esac

NODE_MAJOR="${PH_NODE_MAJOR:-26}"
ENV_FILE="/etc/plugged-host-storage.env"
UNIT="/etc/systemd/system/plugged-host-storage-agent.service"
NGINX_SITE="/etc/nginx/sites-available/plugged-host-storage.conf"
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-.}")" 2>/dev/null && pwd || pwd)"

STORAGE_ROOT="" SSH_PORT="" AGENT_URL="" EMAIL="" NGINX="" ASSUME_YES="" ALLOW_UNMOUNTED=""
SSH_OVERRIDES=()

main() {
# --- output and questions ---------------------------------------------------------------------
if [[ -t 1 ]]; then B=$'\e[1m' D=$'\e[2m' G=$'\e[32m' Y=$'\e[33m' R=$'\e[31m' N=$'\e[0m'; else B="" D="" G="" Y="" R="" N=""; fi
step() { printf '\n%s==> %s%s\n' "$B" "$*" "$N"; }
info() { printf '    %s\n' "$*"; }
ok()   { printf '    %s%s%s\n' "$G" "$*" "$N"; }
warn() { printf '    %s%s%s\n' "$Y" "$*" "$N" >&2; }
die()  { printf '\n%sError: %s%s\n' "$R" "$*" "$N" >&2; exit 1; }

# Questions come from the terminal even when the script itself arrives on stdin (curl | bash).
TTY=""
if [[ -z "${PH_NONINTERACTIVE:-}" ]] && { exec 3</dev/tty; } 2>/dev/null; then TTY=1; fi

# ask VAR "Question" "default": a flag or --yes answers it; otherwise the terminal does.
ask() {
  local var="$1" question="$2" default="${3:-}" answer=""
  if [[ -n "${!var:-}" ]]; then return; fi
  if [[ -n "$ASSUME_YES" || -z "$TTY" ]]; then
    [[ -n "$default" ]] || die "$question: no default (pass it as a flag, see the top of this script)."
    printf -v "$var" '%s' "$default"; info "$question: $default"; return
  fi
  read -r -u 3 -p "    $question${default:+ [$default]}: " answer || true
  printf -v "$var" '%s' "${answer:-$default}"
}

# confirm "Question" y|n: true for yes.
confirm() {
  local question="$1" default="$2" answer=""
  if [[ -n "$ASSUME_YES" || -z "$TTY" ]]; then [[ "$default" == y ]]; return; fi
  read -r -u 3 -p "    $question [$([[ $default == y ]] && echo Y/n || echo y/N)]: " answer || true
  answer="${answer:-$default}"
  [[ "$answer" =~ ^[Yy] ]]
}

env_get() { [[ -f "$ENV_FILE" ]] && sed -n "s/^$1=//p" "$ENV_FILE" | tail -1 || true; }

env_set() {
  local tmp
  tmp="$(mktemp)"
  awk -v k="$1" -v v="$2" 'BEGIN { done = 0 } $0 ~ "^" k "=" { print k "=" v; done = 1; next } { print } END { if (!done) print k "=" v }' "$ENV_FILE" > "$tmp"
  cat "$tmp" > "$ENV_FILE" && rm -f "$tmp"
}

# --- flags --------------------------------------------------------------------------------------
while [[ $# -gt 0 ]]; do
  case "$1" in
    --panel) PANEL_URL="${2:?}"; shift 2 ;;
    --code) SETUP_CODE="${2:?}"; shift 2 ;;
    --storage-root) STORAGE_ROOT="${2:?}"; shift 2 ;;
    --ssh-port) SSH_PORT="${2:?}"; shift 2 ;;
    --ssh-port-for) SSH_OVERRIDES+=("${2:?}"); shift 2 ;;
    --agent-url) AGENT_URL="${2:?}"; shift 2 ;;
    --nginx) NGINX=yes; shift ;;
    --no-nginx) NGINX=no; shift ;;
    --email) EMAIL="${2:?}"; shift 2 ;;
    --allow-unmounted) ALLOW_UNMOUNTED=1; shift ;;
    --yes|-y) ASSUME_YES=1; shift ;;
    -h|--help) sed -n '2,30p' "$0" 2>/dev/null || true; exit 0 ;;
    *) die "Unknown option $1 (see the top of this script)." ;;
  esac
done

[[ $EUID -eq 0 ]] || die "Run this as root (sudo bash)."
RELEASE_DIR="$(env_get PH_RELEASE_DIR || true)"; RELEASE_DIR="${RELEASE_DIR:-/opt/plugged-host-storage}"
command -v apt-get >/dev/null || die "This installer needs a Debian or Ubuntu box (apt-get)."

printf '%splugged.host storage agent setup%s\n' "$B" "$N"
[[ -f "$ENV_FILE" ]] && info "${D}An agent is installed here already: this upgrades it, keeping your answers as defaults.${N}"

WORK="$(mktemp -d)"
chmod 700 "$WORK"
trap 'rm -rf "$WORK"' EXIT

# --- 1. tools ------------------------------------------------------------------------------------
step "Tools"
need=()
for pair in curl:curl rsync:rsync pigz:pigz ssh:openssh-client ssh-keygen:openssh-client; do
  command -v "${pair%%:*}" >/dev/null 2>&1 || need+=("${pair##*:}")
done
if (( ${#need[@]} )); then
  info "Installing ${need[*]}"
  apt-get update -qq </dev/null && apt-get install -y -qq "${need[@]}" </dev/null >/dev/null
fi
ok "curl, rsync, pigz, ssh"

node_major() { "$1" -e 'process.stdout.write(process.versions.node.split(".")[0])' 2>/dev/null || echo 0; }
find_node() {
  local c
  for c in "$(command -v node 2>/dev/null || true)" /usr/bin/node /usr/local/bin/node /root/.nvm/versions/node/*/bin/node; do
    [[ -n "$c" && -x "$c" ]] && (( $(node_major "$c") >= NODE_MAJOR )) && { echo "$c"; return 0; }
  done
  return 1
}
NODE_BIN="$(find_node || true)"
if [[ -z "$NODE_BIN" ]]; then
  old="$(command -v node 2>/dev/null || true)"
  info "Installing Node.js $NODE_MAJOR${old:+ (found $("$old" --version 2>/dev/null))}"
  curl -fsSL "https://deb.nodesource.com/setup_${NODE_MAJOR}.x" -o "$WORK/nodesource.sh" && bash "$WORK/nodesource.sh" </dev/null >/dev/null
  apt-get install -y -qq nodejs </dev/null >/dev/null
  NODE_BIN="$(find_node)" || die "Node.js $NODE_MAJOR did not install."
fi
ok "Node.js $("$NODE_BIN" --version) ($NODE_BIN)"

# Small helpers on Node, so nothing else (jq, python) is needed.
json_get() { "$NODE_BIN" -e 'const o=JSON.parse(require("fs").readFileSync(process.argv[1],"utf8"));let v=o;for(const k of process.argv[2].split("."))v=v==null?v:v[k];process.stdout.write(v==null?"":typeof v=="object"?JSON.stringify(v):String(v))' "$1" "$2"; }
json_obj() { "$NODE_BIN" -e 'const o={};for(let i=1;i<process.argv.length;i+=2)o[process.argv[i]]=process.argv[i+1];process.stdout.write(JSON.stringify(o))' "$@"; }

# --- 2. the panel ----------------------------------------------------------------------------------
step "Panel"
[[ -n "$PANEL_URL" ]] || PANEL_URL="$(env_get PH_PANEL_URL)"
ask PANEL_URL "Panel address" ""
PANEL_URL="${PANEL_URL%/}"
if [[ -z "$SETUP_CODE" ]]; then
  info "Get a setup code: Admin > Plugins > plugged.host Storage (the command contains it),"
  info "or on the panel: php artisan plugged-host:storage-setup"
fi
ask SETUP_CODE "Setup code" ""
[[ "$SETUP_CODE" =~ ^[A-Za-z0-9]{40}$ ]] || die "That is not a setup code (40 letters and digits)."

status="$(curl -sS -o "$WORK/setup.json" -w '%{http_code}' --max-time 30 -X POST \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  --data "$(json_obj code "$SETUP_CODE")" "$PANEL_URL/plugged-host/storage/agent/setup" || true)"
if [[ "$status" != 200 ]]; then
  msg="$(json_get "$WORK/setup.json" error 2>/dev/null || true)"
  die "The panel refused the setup code (HTTP ${status:-no answer}). ${msg:-Is $PANEL_URL right?}"
fi
SECRET="$(json_get "$WORK/setup.json" agent_secret)"
[[ ${#SECRET} -ge 32 ]] || die "The panel sent no agent secret."
BOX="$(json_get "$WORK/setup.json" box)"; BOX="${BOX:-storage}"
[[ "$BOX" =~ ^[a-z]+$ ]] || die "The panel named no usable box."
BOX_LABEL="$(json_get "$WORK/setup.json" box_label)"; BOX_LABEL="${BOX_LABEL:-storage box}"
NODES="$("$NODE_BIN" -e 'const o=JSON.parse(require("fs").readFileSync(process.argv[1],"utf8"));for(const n of o.nodes||[])if(n.fqdn)console.log(n.fqdn+"\t"+String(n.name).replace(/[\t\n]/g," "))' "$WORK/setup.json")"
ok "Connected to $PANEL_URL ($(printf '%s\n' "$NODES" | grep -c . || true) nodes): setting up the $BOX_LABEL"

# --- 3. storage disk -------------------------------------------------------------------------------
step "Storage"
default_root="$(env_get PH_STORAGE_ROOT || true)"
ask STORAGE_ROOT "Where mirrors and snapshots go" "${default_root:-/mnt/storage}"
STORAGE_ROOT="${STORAGE_ROOT%/}"
mkdir -p "$STORAGE_ROOT"
if mountpoint -q "$STORAGE_ROOT"; then
  ok "$STORAGE_ROOT is its own mount ($(df -h --output=avail "$STORAGE_ROOT" | tail -1 | tr -d ' ') free)"
else
  warn "$STORAGE_ROOT is not a separate mount: the copies would fill the system disk."
  if [[ -z "$ALLOW_UNMOUNTED" ]] && ! confirm "Use it anyway?" n; then
    die "Mount the storage disk at $STORAGE_ROOT (with nofail in /etc/fstab) and run this again."
  fi
fi

# --- 4. SSH to the nodes ---------------------------------------------------------------------------
step "Nodes"
info "The agent copies each server from its node over SSH, with its own key."
[[ -n "$NODES" ]] && printf '%s\n' "$NODES" | while IFS=$'\t' read -r fqdn name; do info "  $name  ${D}$fqdn${N}"; done
default_port="$(env_get PH_SSH_DEFAULT_PORT || true)"
ask SSH_PORT "SSH port of the nodes" "${default_port:-22}"
[[ "$SSH_PORT" =~ ^[0-9]+$ ]] || die "SSH port must be a number."
if (( ${#SSH_OVERRIDES[@]} == 0 )); then
  OVERRIDES="$(env_get PH_SSH_PORT_OVERRIDES || true)"
  if [[ -z "$ASSUME_YES" && -n "$TTY" ]]; then
    read -r -u 3 -p "    Nodes on another port, as host=port separated by spaces${OVERRIDES:+ [$OVERRIDES]} (Enter for ${OVERRIDES:+the same/}none): " answer || true
    OVERRIDES="${answer:-$OVERRIDES}"
  fi
  read -r -a SSH_OVERRIDES <<< "$OVERRIDES"
fi
for o in "${SSH_OVERRIDES[@]}"; do [[ "$o" =~ ^[A-Za-z0-9.*_-]+=[0-9]+$ ]] || die "\"$o\" is not host=port."; done

RSYNC_KEY="$(env_get PH_RSYNC_SSH_KEY || true)"; RSYNC_KEY="${RSYNC_KEY:-/root/.ssh/ph-mirror}"
RSYNC_USER="$(env_get PH_RSYNC_USER || true)"; RSYNC_USER="${RSYNC_USER:-root}"
mkdir -p "$(dirname "$RSYNC_KEY")" && chmod 700 "$(dirname "$RSYNC_KEY")"
if [[ ! -f "$RSYNC_KEY" ]]; then
  # Named after the box, so a node's authorized_keys tells the storage box's key from the backup server's.
  key_comment=plugged-host-storage-agent
  [[ "$BOX" == storage ]] || key_comment="plugged-host-$BOX-agent"
  ssh-keygen -q -t ed25519 -f "$RSYNC_KEY" -N "" -C "$key_comment"
  ok "Created the mirror key $RSYNC_KEY"
else
  ok "Mirror key $RSYNC_KEY"
fi

port_for() {
  local o
  for o in "${SSH_OVERRIDES[@]}"; do
    # shellcheck disable=SC2053
    [[ "$1" == ${o%%=*} ]] && { echo "${o##*=}"; return; }
  done
  echo "$SSH_PORT"
}
SSH_CONFIG="$(dirname "$RSYNC_KEY")/config"
touch "$SSH_CONFIG" && chmod 600 "$SSH_CONFIG"
# Only the block this script owns is replaced.
sed -i '/^# >>> plugged-host-storage$/,/^# <<< plugged-host-storage$/d' "$SSH_CONFIG"
{
  echo "# >>> plugged-host-storage"
  printf '%s\n' "$NODES" | while IFS=$'\t' read -r fqdn name; do
    [[ -n "$fqdn" ]] || continue
    printf 'Host %s\n  Port %s\n  User %s\n  IdentityFile %s\n' "$fqdn" "$(port_for "$fqdn")" "$RSYNC_USER" "$RSYNC_KEY"
  done
  echo "# <<< plugged-host-storage"
} >> "$SSH_CONFIG"
ok "SSH settings for every node in $SSH_CONFIG"

# --- 5. the agent's address -------------------------------------------------------------------------
step "Address"
info "Wings downloads failover archives from the agent, and the panel checks nodes through it."
default_url="$(json_get "$WORK/setup.json" agent_url)"
[[ -n "$default_url" ]] || default_url="https://$(hostname -f 2>/dev/null || hostname)"
ask AGENT_URL "Address of this agent" "$default_url"
AGENT_URL="${AGENT_URL%/}"
[[ "$AGENT_URL" =~ ^https?://[^/]+$ ]] || die "Give the address as https://host (or http://host:port)."
LISTEN_PORT="$(env_get PH_LISTEN_PORT || true)"; LISTEN_PORT="${LISTEN_PORT:-8687}"
host="${AGENT_URL#*://}"; host="${host%%:*}"

if [[ "$AGENT_URL" == https://* && -z "$NGINX" ]]; then
  existing="$(grep -rlsE "server_name[^;]*[[:space:]]$host([[:space:]]|;)" /etc/nginx/sites-enabled /etc/nginx/conf.d 2>/dev/null | head -1 || true)"
  if [[ -n "$existing" ]]; then
    info "nginx already serves $host ($existing): leaving it as it is."
    NGINX=no
  elif [[ -z "$TTY" || -n "$ASSUME_YES" ]]; then
    NGINX=no
    info "Unattended: no nginx unless --nginx. Make $AGENT_URL reach http://127.0.0.1:$LISTEN_PORT."
  elif confirm "Put nginx with a free Let's Encrypt certificate in front of it (port 80 and 443 must reach this box)?" y; then
    NGINX=yes
  else
    NGINX=no
    warn "Then make $AGENT_URL reach http://127.0.0.1:$LISTEN_PORT yourself."
  fi
fi
if [[ "$NGINX" == yes && -z "$EMAIL" && -z "$ASSUME_YES" && -n "$TTY" ]]; then
  read -r -u 3 -p "    E-mail for Let's Encrypt expiry notices (Enter for none): " EMAIL || true
fi
[[ "$AGENT_URL" == http://* ]] && warn "Plain http: archives and signed links would cross the internet unencrypted."

# --- 6. install ---------------------------------------------------------------------------------------
step "Installing the agent"
curl -fsS --max-time 30 -D "$WORK/manifest.headers" -o "$WORK/manifest.json" "$PANEL_URL/plugged-host/storage/agent/manifest.json" \
  || die "Could not fetch the agent manifest from the panel."
# Signed once per box (X-PH-Signature: the storage box, X-PH-Signature-Backup: the backup server).
expected="$(PH_SECRET="$SECRET" "$NODE_BIN" -e 'const c=require("crypto"),f=require("fs");process.stdout.write(c.createHmac("sha256",process.env.PH_SECRET).update(f.readFileSync(process.argv[1])).digest("hex"))' "$WORK/manifest.json")"
sed -n 's/^[Xx]-[Pp][Hh]-[Ss]ignature\(-[A-Za-z]*\)\?: *//p' "$WORK/manifest.headers" | tr -d '\r' | grep -xF "$expected" >/dev/null \
  || die "The agent manifest's signature does not match the agent secret."
VERSION="$(json_get "$WORK/manifest.json" version)"
[[ "$VERSION" =~ ^[0-9A-Za-z._-]+$ ]] || die "The manifest names no usable version."
curl -fsS --max-time 120 -o "$WORK/bundle.tar.gz" "$PANEL_URL/plugged-host/storage/agent/bundle.tar.gz" || die "Could not download the agent."
[[ "$(sha256sum "$WORK/bundle.tar.gz" | awk '{print $1}')" == "$(json_get "$WORK/manifest.json" sha256)" ]] || die "The agent download does not match its manifest."
DEST="$RELEASE_DIR/releases/$VERSION"
mkdir -p "$DEST"
tar -xzf "$WORK/bundle.tar.gz" -C "$DEST"
[[ -f "$DEST/agent.mjs" ]] || die "The agent bundle has no agent.mjs."
ln -sfn "$DEST" "$RELEASE_DIR/current"
ok "Agent $VERSION in $DEST"

mkdir -p "$STORAGE_ROOT/mirror" "$STORAGE_ROOT/snapshots" "$STORAGE_ROOT/archives" "$STORAGE_ROOT/s3"

touch "$ENV_FILE" && chmod 600 "$ENV_FILE"
grep -q '^# plugged.host storage agent' "$ENV_FILE" || sed -i '1i # plugged.host storage agent configuration (written by install.sh; your own lines are kept)' "$ENV_FILE"
env_set PH_PANEL_URL "$PANEL_URL"
env_set PH_AGENT_SECRET "$SECRET"
env_set PH_STORAGE_ROOT "$STORAGE_ROOT"
env_set PH_RELEASE_DIR "$RELEASE_DIR"
env_set PH_LISTEN_PORT "$LISTEN_PORT"
env_set PH_MIN_FREE_PERCENT "$(json_get "$WORK/setup.json" min_free_percent)"
env_set PH_SNAPSHOTS "$(json_get "$WORK/setup.json" mirror_snapshots)"
env_set PH_MIRROR_INTERVAL_MIN "$(json_get "$WORK/setup.json" mirror_interval_min)"
[[ -n "$(env_get PH_UPDATE_INTERVAL_MIN)" ]] || env_set PH_UPDATE_INTERVAL_MIN 15
env_set PH_RSYNC_USER "$RSYNC_USER"
env_set PH_RSYNC_SSH_KEY "$RSYNC_KEY"
env_set PH_VERSION "$VERSION"
env_set PH_SSH_DEFAULT_PORT "$SSH_PORT"
# Mirror passes at once: half the cores, 1 to 4 (one at a time on a single-core box).
if [[ -z "$(env_get PH_MIRROR_JOBS)" ]]; then
  jobs=$(( $(nproc 2>/dev/null || echo 1) / 2 )); (( jobs < 1 )) && jobs=1; (( jobs > 4 )) && jobs=4
  env_set PH_MIRROR_JOBS "$jobs"
fi
env_set PH_SSH_PORT_OVERRIDES "${SSH_OVERRIDES[*]:-}"
ok "Settings in $ENV_FILE"

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
# The agent shells out to rsync/tar/du/df/cp; keep it unconfined enough to read the disk.
WorkingDirectory=$RELEASE_DIR/current
StandardOutput=append:/var/log/plugged-host-storage-agent.log
StandardError=append:/var/log/plugged-host-storage-agent.log

[Install]
WantedBy=multi-user.target
EOF
# Never start before the storage disk is mounted: the agent would copy onto the system disk.
if mountpoint -q "$STORAGE_ROOT"; then
  mkdir -p "$UNIT.d"
  printf '[Unit]\nRequiresMountsFor=%s\n' "$STORAGE_ROOT" > "$UNIT.d/10-storage-mount.conf"
fi
systemctl daemon-reload
systemctl enable -q plugged-host-storage-agent.service
systemctl restart plugged-host-storage-agent.service
for _ in $(seq 1 20); do
  curl -fsS --max-time 2 "http://127.0.0.1:$LISTEN_PORT/health" >/dev/null 2>&1 && break
  sleep 1
done
curl -fsS --max-time 2 "http://127.0.0.1:$LISTEN_PORT/health" >/dev/null 2>&1 \
  && ok "Running (systemctl status plugged-host-storage-agent)" \
  || die "The agent did not come up: journalctl -u plugged-host-storage-agent, /var/log/plugged-host-storage-agent.log"

# --- 7. nginx + HTTPS ----------------------------------------------------------------------------------
if [[ "$NGINX" == yes ]]; then
  step "HTTPS for $host"
  apt-get install -y -qq nginx certbot python3-certbot-nginx </dev/null >/dev/null
  cat > "$NGINX_SITE" <<'EOF'
# plugged.host storage agent (written by its install.sh)
server {
    listen 80;
    listen [::]:80;
    server_name __HOST__;

    # Failover archives stream for minutes and can be many GB.
    client_max_body_size 0;
    location / {
        proxy_pass http://127.0.0.1:__PORT__;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_buffering off;
        proxy_request_buffering off;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }
}
EOF
  sed -i "s/__HOST__/$host/; s/__PORT__/$LISTEN_PORT/" "$NGINX_SITE"
  ln -sfn "$NGINX_SITE" /etc/nginx/sites-enabled/plugged-host-storage.conf
  nginx -t -q && systemctl reload nginx
  if [[ -n "$EMAIL" ]]; then contact=(-m "$EMAIL"); else contact=(--register-unsafely-without-email); fi
  certbot --nginx -d "$host" --non-interactive --agree-tos "${contact[@]}" --redirect -q </dev/null \
    || die "Let's Encrypt failed for $host: does its DNS point here (not proxied), and do ports 80/443 reach this box?"
  ok "$AGENT_URL (certificate renews itself)"
fi

# --- 8. tell the panel ---------------------------------------------------------------------------------
step "Telling the panel"
status="$(curl -sS -o "$WORK/complete.json" -w '%{http_code}' --max-time 30 -X POST \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  --data "$(json_obj code "$SETUP_CODE" mirror_public_key "$(cat "$RSYNC_KEY.pub")" agent_url "$AGENT_URL")" \
  "$PANEL_URL/plugged-host/storage/agent/setup/complete" || true)"
if [[ "$status" == 200 && "$(json_get "$WORK/complete.json" ok)" == true ]]; then
  ok "The panel has the mirror key and $AGENT_URL"
else
  warn "The panel did not take everything (HTTP ${status:-no answer}: $(json_get "$WORK/complete.json" rejected 2>/dev/null || true))."
  warn "Set them in Admin > Plugins > plugged.host Storage: agent URL $AGENT_URL, and the mirror key:"
  warn "$(cat "$RSYNC_KEY.pub")"
fi

# --- 9. next ---------------------------------------------------------------------------------------------
step "Last step: let the agent onto the nodes"
info "On every node, as root (again on nodes that already ran it: it adds this box's key):"
info "  ${B}curl -fsSL $PANEL_URL/plugged-host/storage/node/install.sh | sudo bash${N}"

if [[ -n "$TTY" && -z "$ASSUME_YES" && -n "$NODES" ]] && confirm "Test SSH to every node now (do the step above first)?" n; then
  printf '%s\n' "$NODES" | while IFS=$'\t' read -r fqdn name; do
    if ssh -F "$SSH_CONFIG" -o BatchMode=yes -o ConnectTimeout=8 -o StrictHostKeyChecking=accept-new "$fqdn" true 2>/dev/null; then
      ok "$name ($fqdn): ok"
    else
      warn "$name ($fqdn): no SSH with the mirror key on port $(port_for "$fqdn")"
    fi
  done
fi

printf '\n%sDone.%s Check it on the panel: php artisan plugged-host:doctor plugged-host-storage\n' "$G" "$N"
}

# Everything above is read before anything runs: with `curl | bash` a command that reads stdin
# could otherwise swallow the rest of the script.
main "$@"
