#!/bin/bash
# plugged.host FiveM install (written into the egg by `php artisan plugged-host:fivem-setup`).
#
# Runs in ghcr.io/plugged-host/fivem, the image the server itself runs in, so the artifact the
# install lays down and the one every start keeps up to date come from the same code:
# fivem-runtime reads FIVEM_VERSION (recommended, latest or a build number) and FIVEM_EDITION
# (legacy or enhanced) and installs that build into alpine/.
#
# Server files: /mnt/server

exec fivem-runtime install /mnt/server
