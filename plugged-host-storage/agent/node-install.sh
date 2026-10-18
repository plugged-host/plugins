#!/usr/bin/env bash
#
# plugged.host storage: let the storage agents pull this node's server files (rsync over SSH
# with their ph-mirror keys): the storage box's and, when there is one, the backup server's. Run
# ON EVERY WINGS NODE as root; the panel serves it with the mirror keys filled in:
#
#   curl -fsSL https://<panel>/plugged-host/storage/node/install.sh | sudo bash
#
# Safe to run again: each key is only added once, and a line the panel changed (a key pinned to its
# box's addresses with from=, or a new address) replaces that key's old line. Run it again after
# setting up a backup server or changing a box's addresses.
#
set -euo pipefail

KEYS='__MIRROR_PUBLIC_KEYS__'

if [[ $EUID -ne 0 ]]; then
  echo "Must run as root." >&2
  exit 1
fi

if [[ "$KEYS" != *ssh-* ]]; then
  echo "The panel has no mirror key yet: set the storage box up first (php artisan plugged-host:storage-setup)." >&2
  exit 1
fi

# The pull runs rsync on both ends.
if ! command -v rsync >/dev/null 2>&1; then
  apt-get update -qq && apt-get install -y rsync
fi

mkdir -p /root/.ssh
chmod 700 /root/.ssh
touch /root/.ssh/authorized_keys
chmod 600 /root/.ssh/authorized_keys

# Each line is a key, optionally after from="<addresses>". A key already there under other options
# has its line replaced (the new file is written next to it, then moved over it in one step).
while IFS= read -r line; do
  body="$(awk '{ for (i = 1; i < NF; i++) if ($i ~ /^ssh-/) { print $(i + 1); exit } }' <<< "$line")"
  [[ -n "$body" ]] || continue
  if grep -qxF "$line" /root/.ssh/authorized_keys; then
    echo "Already authorized: ${line##* }"
  elif grep -qF "$body" /root/.ssh/authorized_keys; then
    tmp="$(mktemp /root/.ssh/.authorized_keys.XXXXXX)"
    { grep -vF "$body" /root/.ssh/authorized_keys || true; printf '%s\n' "$line"; } > "$tmp"
    chmod 600 "$tmp"
    mv -f "$tmp" /root/.ssh/authorized_keys
    echo "Updated: ${line##* }"
  else
    echo "$line" >> /root/.ssh/authorized_keys
    echo "Authorized: ${line##* }"
  fi
done <<< "$KEYS"

port="$(sshd -T 2>/dev/null | awk '/^port /{print $2; exit}' || true)"
echo "sshd listens on port ${port:-22} here. If that is not 22, give the storage agents this node's port"
echo "(when running their install.sh, or in their /root/.ssh/config)."
