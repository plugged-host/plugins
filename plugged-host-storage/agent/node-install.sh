#!/usr/bin/env bash
#
# plugged.host storage: let the storage box's agent pull this node's server files (rsync over
# SSH with the ph-mirror key). Run ON EVERY WINGS NODE as root; the panel serves it with its
# mirror key filled in:
#
#   curl -fsSL https://<panel>/plugged-host/storage/node/install.sh | sudo bash
#
# Safe to run again: the key is only added once.
#
set -euo pipefail

KEY='__MIRROR_PUBLIC_KEY__'

if [[ $EUID -ne 0 ]]; then
  echo "Must run as root." >&2
  exit 1
fi

if [[ "$KEY" != ssh-* ]]; then
  echo "The panel has no mirror key yet: set it in Admin > Plugins > plugged.host Storage." >&2
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

if grep -qF "$KEY" /root/.ssh/authorized_keys; then
  echo "The storage mirror key is already authorized."
else
  echo "$KEY" >> /root/.ssh/authorized_keys
  echo "Authorized the storage mirror key."
fi

port="$(sshd -T 2>/dev/null | awk '/^port /{print $2; exit}' || true)"
echo "sshd listens on port ${port:-22} here. If that is not 22, give the storage box this node's port"
echo "(PH_SSH_PORTS when running its install.sh, or its /root/.ssh/config)."
