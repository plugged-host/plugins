# syntax=docker/dockerfile:1
#
# The plugged.host devbox: a Debian box Wings runs next to a game server, with the game server's
# files mounted at /server and the devbox's own volume as /home/container (VS Code's server and
# extensions, logins, caches). Build context: plugged-host-devbox/ (docker build -f image/Dockerfile .).
#
# Wings runs it read-only as the node's container user (its own /etc/passwd), so nothing here is
# chowned and everything writable lives in $HOME or /tmp. FULL=0 skips Node, the JDK and
# compilers, for quick local spikes.

FROM golang:1.26-trixie AS ph
WORKDIR /src
COPY ph/go.mod ph/go.sum ./
RUN go mod download
COPY ph/ ./
ARG PH_VERSION=dev
RUN go vet ./... && go test ./... \
 && CGO_ENABLED=0 go build -trimpath -ldflags "-s -w -X main.version=${PH_VERSION}" -o /out/ph .

FROM debian:trixie-slim
ARG FULL=1
ARG NODE_VERSION=26.9.0

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        tini bash bash-completion ca-certificates curl wget tar gzip xz-utils unzip zip \
        git openssh-client procps psmisc less nano vim-tiny jq file tree htop \
        python3 python3-venv python3-pip; \
    if [ "$FULL" = 1 ]; then \
        apt-get install -y --no-install-recommends build-essential pkg-config openjdk-21-jdk-headless lua5.4; \
    fi; \
    rm -rf /var/lib/apt/lists/*

# Node from nodejs.org (Debian's is older), checked against the release's SHASUMS256.
RUN set -eux; \
    if [ "$FULL" = 1 ]; then \
        cd /tmp; \
        file="node-v${NODE_VERSION}-linux-x64.tar.xz"; \
        curl -fsSLO "https://nodejs.org/dist/v${NODE_VERSION}/${file}"; \
        curl -fsSL "https://nodejs.org/dist/v${NODE_VERSION}/SHASUMS256.txt" | grep " ${file}\$" | sha256sum -c -; \
        tar -xJf "$file" -C /usr/local --strip-components=1 --no-same-owner \
            --exclude='*/CHANGELOG.md' --exclude='*/README.md' --exclude='*/LICENSE'; \
        rm "$file"; \
        node --version; npm --version; \
    fi

COPY --from=ph /out/ph /usr/local/bin/ph
COPY image/profile.sh /etc/profile.d/devbox.sh
RUN mkdir -p /server /home/container && chmod 0755 /server /home/container

ENV HOME=/home/container \
    LANG=C.UTF-8 \
    TMPDIR=/home/container/.cache/tmp \
    NPM_CONFIG_PREFIX=/home/container/.local \
    PIP_USER=1 \
    PATH=/home/container/.local/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

WORKDIR /home/container
ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/ph", "serve"]
