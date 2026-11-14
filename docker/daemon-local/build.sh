#!/usr/bin/env bash
# Build a Docker-only Linux release (for this Mac's CPU) from the daemon repository.
set -euo pipefail

# The test node runs at the Mac's own architecture.
case "$(uname -m)" in arm64|aarch64) ARCH=arm64 ;; *) ARCH=amd64 ;; esac

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SOURCE="${SERVEROS_DAEMON_SOURCE:-/private/tmp/serveros-daemon}"
VERSION=v0.0.0-local

if [ ! -d "$SOURCE/.git" ]; then
  git clone https://github.com/serveroshq/daemon.git "$SOURCE"
fi

# The local panel's install command pins its CA (Herd's), which needs
# `serverosd enrol --panel-ca`. A clone from before that has to be updated.
if ! grep -q 'panel_ca' "$SOURCE/crates/serverosd/src/main.rs"; then
  echo "$SOURCE is older than serverosd enrol --panel-ca. Update it: git -C $SOURCE checkout . && git -C $SOURCE pull" >&2
  exit 1
fi

docker run --rm --platform "linux/$ARCH" \
  -e SERVEROS_VERSION="$VERSION" \
  -e SERVEROS_CHANNEL=dev \
  -e SERVEROS_PANEL_URL=https://serveros.test \
  -v "$SOURCE:/src" \
  -v serveros-cargo-registry:/usr/local/cargo/registry \
  -w /src rust:1-bookworm \
  cargo build --release -p serverosd -p serveros-gateway

RELEASE="$ROOT/public/releases/daemon/$VERSION"
mkdir -p "$RELEASE" "$ROOT/public/releases/daemon/stable" "$ROOT/storage/app/private/gateway"
cp "$SOURCE/target/release/serverosd" "$RELEASE/serverosd-$VERSION-linux-$ARCH"
cp "$SOURCE/target/release/serveros-gateway" "$ROOT/storage/app/private/gateway/serveros-gateway"
(
  cd "$RELEASE"
  shasum -a 256 "serverosd-$VERSION-linux-$ARCH" > SHA256SUMS
)
printf '%s\n' "$VERSION" > "$ROOT/public/releases/daemon/stable/VERSION"
echo "Local daemon release is served at https://serveros.test/releases/daemon/$VERSION/"
