# syntax=docker/dockerfile:1
#
# The plugged.host FiveM image (ghcr.io/plugged-host/fivem). FXServer brings its own musl
# userland in the server's alpine/ folder, so the image only needs what fivem-runtime uses to keep
# that folder on the right build: curl, jq, tar and xz. Build context: plugged-host-fivem/
# (docker build -f image/Dockerfile .).
#
# The same image is the egg's install container (Wings runs that as root with the volume at
# /mnt/server) and the server container (Wings runs that as the node's container user with the
# volume at /home/container), so no USER is set here.

FROM debian:trixie-slim AS base

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        tini bash ca-certificates curl jq tar xz-utils iproute2 procps tzdata; \
    rm -rf /var/lib/apt/lists/*; \
    useradd -m -d /home/container -s /bin/bash container

COPY image/fivem-runtime /usr/local/bin/fivem-runtime
RUN chmod 0755 /usr/local/bin/fivem-runtime

# fivem-runtime against fixtures; the final stage copies its marker, so a failing test fails the build.
FROM base AS test
COPY image/tests/run.sh /tmp/run.sh
RUN bash /tmp/run.sh /usr/local/bin/fivem-runtime && touch /tmp/tests-passed

FROM base
COPY --from=test /tmp/tests-passed /usr/local/share/plugged-host-fivem-tests-passed

ENV USER=container HOME=/home/container
WORKDIR /home/container

ENTRYPOINT ["/usr/bin/tini", "-g", "--"]
CMD ["/usr/local/bin/fivem-runtime", "start"]
