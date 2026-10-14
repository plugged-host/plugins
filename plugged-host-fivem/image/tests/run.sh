#!/bin/bash
#
# fivem-runtime against fixtures: curl is replaced by a stub that serves Cfx's two JSON sources
# and small fake artifacts, so every path (channels, pins, editions, offline, broken downloads,
# the Enhanced startup rewrite) runs offline in the image build.
#
#   bash image/tests/run.sh [path/to/fivem-runtime]

set -u

RUNTIME=$(readlink -f "${1:-$(dirname "$0")/../fivem-runtime}")
T=$(mktemp -d)
trap 'chmod -R u+rwX "$T" 2>/dev/null; rm -rf "$T"' EXIT

FAILED=0
pass() { printf 'ok   %s\n' "$1"; }
fail() { printf 'FAIL %s\n' "$1"; FAILED=1; }
check() { if eval "$2"; then pass "$1"; else fail "$1"; fi; }

# --- fixtures ----------------------------------------------------------------------------------

mkdir -p "$T/fixtures" "$T/bin"

# A fake artifact: the loader is a script, so the startup line can really be run.
artifact() {
    local name=$1 edition=$2 build=$3 dir="$T/build-$1"
    mkdir -p "$dir/alpine/opt/cfx-server" "$dir/alpine/lib" "$dir/alpine/proc"
    if [ "$edition" = enhanced ]; then
        loader="$dir/alpine/lib/ld-musl-x86_64.so.1"
        echo "enhanced $build" > "$dir/alpine/opt/cfx-server/cfx-server"
        echo '#!/bin/sh' > "$dir/run.sh"
        echo "attribution" > "$dir/license-attribution.txt"
    else
        loader="$dir/alpine/opt/cfx-server/ld-musl-x86_64.so.1"
        [ "$name" = broken ] || echo "legacy $build" > "$dir/alpine/opt/cfx-server/FXServer"
        echo '#!/bin/sh' > "$dir/run.sh"
    fi
    printf '#!/bin/sh\necho "LAUNCH $0 $*"\necho "PORT=$TXHOST_FXS_PORT"\n' > "$loader"
    chmod +x "$loader"
    chmod 555 "$dir/alpine/proc"
    ( cd "$dir" && tar -cJf "$T/fixtures/$name.tar.xz" ./* )
}

artifact legacy-35245 legacy 35245
artifact legacy-37287 legacy 37287
artifact legacy-32698 legacy 32698
artifact enhanced-168 enhanced 168
artifact broken legacy 99999

L=https://runtime.fivem.net/artifacts/fivem/build_proot_linux/master
cat > "$T/fixtures/changelog.json" <<EOF
{"recommended":"35245","recommended_download":"$L/35245-aaaa/fx.tar.xz",
 "latest":"37287","latest_download":"$L/37287-bbbb/fx.tar.xz"}
EOF

next_data() {
    printf '<html><script id="__NEXT_DATA__" type="application/json">'
    printf '{"props":{"pageProps":{"enhanced":{"linux":[{"subtitle":"build 168","downloadURL":"https://downloads.cfx-services.net/prod/uuid-168/cfx-server_linux_x64.tar.xz"}]},'
    printf '"legacy":{"recommended":{"linux":[{"subtitle":"build 35245","downloadURL":"%s/35245-aaaa/fx.tar.xz"}]},' "$L"
    printf '"latest":{"linux":[{"subtitle":"build 37287","downloadURL":"%s/37287-bbbb/fx.tar.xz"},{"subtitle":"build 32698","downloadURL":"%s/32698-cccc/fx.tar.xz"},{"subtitle":"build 99999","downloadURL":"%s/99999-dddd/fx.tar.xz"},{"subtitle":"build 66666","downloadURL":"https://evil.example/fx.tar.xz"}]}}}}}' "$L" "$L" "$L"
    printf '</script><script>other</script></html>'
}
next_data > "$T/fixtures/downloads.html"

# The curl stub: OFFLINE=1 fails everything, every artifact download is counted.
cat > "$T/bin/curl" <<EOF
#!/bin/bash
out=""; url=""
while [ \$# -gt 0 ]; do
    case "\$1" in
        -o) out=\$2; shift ;;
        --retry|--connect-timeout|--max-time) shift ;;
        http*) url=\$1 ;;
    esac
    shift
done
[ "\${OFFLINE:-0}" = 1 ] && exit 6
case "\$url" in
    *changelog*) src=$T/fixtures/changelog.json ;;
    *docs.fivem.net*) src=$T/fixtures/downloads.html ;;
    *35245-aaaa*) src=$T/fixtures/legacy-35245.tar.xz ;;
    *37287-bbbb*) src=$T/fixtures/legacy-37287.tar.xz ;;
    *32698-cccc*) src=$T/fixtures/legacy-32698.tar.xz ;;
    *99999-dddd*) src=$T/fixtures/broken.tar.xz ;;
    *uuid-168*) src=$T/fixtures/enhanced-168.tar.xz ;;
    *) exit 22 ;;
esac
case "\$url" in *.tar.xz) echo x >> $T/downloads ;; esac
if [ -n "\$out" ]; then cp "\$src" "\$out"; else cat "\$src"; fi
EOF
chmod +x "$T/bin/curl"
export PATH="$T/bin:$PATH"

# --- helpers -----------------------------------------------------------------------------------

S="$T/server"
mkdir -p "$S"

run() { ( cd "$S" && env "$@" bash "$RUNTIME" install "$S" ) > "$T/out" 2>&1; }
marker() { jq -r ".$1" "$S/alpine/.plugged-host.json" 2>/dev/null; }
downloads() { [ -f "$T/downloads" ] && wc -l < "$T/downloads" | tr -d ' ' || echo 0; }
reset_downloads() { rm -f "$T/downloads"; }

# --- cases -------------------------------------------------------------------------------------

run FIVEM_VERSION=recommended
check "fresh install takes the recommended build" '[ "$(marker build)" = 35245 ] && [ -f "$S/alpine/opt/cfx-server/FXServer" ]'
check "the install creates txData and logs" '[ -d "$S/txData" ] && [ -d "$S/logs" ]'
check "run.sh from the artifact is not left behind" '[ ! -e "$S/run.sh" ]'

reset_downloads
run FIVEM_VERSION=recommended
check "an up-to-date channel downloads nothing" '[ "$(downloads)" = 0 ]'

run FIVEM_VERSION=latest
check "following latest installs the latest build" '[ "$(marker build)" = 37287 ] && grep -q 37287 "$S/alpine/opt/cfx-server/FXServer"'

run FIVEM_VERSION=32698
check "a pinned build installs that build" '[ "$(marker build)" = 32698 ]'

reset_downloads
run FIVEM_VERSION=32698 OFFLINE=1
check "a pinned build that is installed starts with no network" '[ $? -eq 0 ] && [ "$(downloads)" = 0 ] && [ "$(marker build)" = 32698 ]'

run FIVEM_VERSION=latest OFFLINE=1
check "an unreachable Cfx keeps the installed build" '[ "$(marker build)" = 32698 ] && grep -q "starting the installed build" "$T/out"'

run FIVEM_VERSION=99999
check "a broken download keeps the current files" '[ "$(marker build)" = 32698 ] && [ -f "$S/alpine/opt/cfx-server/FXServer" ] && [ ! -e "$S/.plugged-host-download" ]'

run FIVEM_VERSION=66666
check "a build hosted outside Cfx is never downloaded" '[ "$(marker build)" = 32698 ]'

run FIVEM_VERSION=garbage
check "an unknown version means recommended" '[ "$(marker build)" = 35245 ]'

run FIVEM_EDITION=enhanced FIVEM_VERSION=32698
check "enhanced replaces the legacy files" '[ "$(marker edition)" = enhanced ] && [ "$(marker build)" = 168 ] && [ -f "$S/alpine/opt/cfx-server/cfx-server" ] && [ ! -e "$S/alpine/opt/cfx-server/FXServer" ]'

STARTUP='TXHOST_FXS_PORT={{SERVER_PORT}} $(pwd)/alpine/opt/cfx-server/ld-musl-x86_64.so.1 --library-path "$(pwd)/alpine/usr/lib/v8/:$(pwd)/alpine/lib/:$(pwd)/alpine/usr/lib/" -- $(pwd)/alpine/opt/cfx-server/FXServer +set citizen_dir $(pwd)/alpine/opt/cfx-server/citizen/ +set txAdminManagerMode true'
( cd "$S" && env FIVEM_ROOT="$S" FIVEM_EDITION=enhanced SERVER_PORT=30120 STARTUP="$STARTUP" bash "$RUNTIME" start ) > "$T/out" 2>&1
check "enhanced starts through its own loader and binary" "grep -q \"LAUNCH $S/alpine/lib/ld-musl-x86_64.so.1 --library-path $S/alpine/lib/:$S/alpine/usr/lib/ -- $S/alpine/opt/cfx-server/cfx-server +set citizen_dir\" \"$T/out\""
check "the startup variables are substituted" "grep -qx 'PORT=30120' \"$T/out\""

run FIVEM_EDITION=enhanced OFFLINE=1
check "enhanced offline keeps enhanced" '[ "$(marker edition)" = enhanced ]'

run FIVEM_EDITION=legacy FIVEM_VERSION=recommended OFFLINE=1
check "switching back while offline starts enhanced and says so" '[ $? -eq 0 ] && [ "$(marker edition)" = enhanced ] && grep -q "switch did not complete" "$T/out"'

run FIVEM_EDITION=legacy FIVEM_VERSION=recommended
check "switching back installs legacy again" '[ "$(marker edition)" = legacy ] && [ "$(marker build)" = 35245 ] && [ ! -e "$S/alpine/opt/cfx-server/cfx-server" ]'

( cd "$S" && env FIVEM_ROOT="$S" SERVER_PORT=30120 STARTUP="$STARTUP" bash "$RUNTIME" start ) > "$T/out" 2>&1
check "legacy starts with the startup line unchanged" "grep -q \"LAUNCH $S/alpine/opt/cfx-server/ld-musl-x86_64.so.1 --library-path $S/alpine/usr/lib/v8/:$S/alpine/lib/:$S/alpine/usr/lib/ -- $S/alpine/opt/cfx-server/FXServer\" \"$T/out\""

rm -rf "$S"; mkdir -p "$S"
run FIVEM_VERSION=12345
check "a pinned build Cfx no longer offers falls back to recommended" '[ "$(marker build)" = 35245 ] && grep -q "no longer offered" "$T/out"'

rm -rf "$S"; mkdir -p "$S"
run OFFLINE=1
check "nothing installed and no network fails" '[ ! -d "$S/alpine" ] && grep -q "Could not find" "$T/out"'
( cd "$S" && env FIVEM_ROOT="$S" OFFLINE=1 STARTUP="true" bash "$RUNTIME" start ) > "$T/out" 2>&1
check "start refuses to run nothing" '[ $? -ne 0 ] || grep -q "no FiveM server to start" "$T/out"'

mkdir -p "$S/alpine/opt/cfx-server"; echo old > "$S/alpine/opt/cfx-server/FXServer"
run FIVEM_VERSION=recommended
check "files from the old egg (no record) are replaced once" '[ "$(marker build)" = 35245 ] && grep -q 35245 "$S/alpine/opt/cfx-server/FXServer"'

exit $FAILED
