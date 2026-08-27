#!/bin/bash
set -euo pipefail

# Install openNDS for TaraSec on Debian/Ubuntu/Raspberry Pi OS.
# Prefer a distro package when it provides a working ndsctl. Otherwise build
# the Cigar-validated openNDS 10.1.0 release from source. The upstream make
# install target installs the required /usr/lib/opennds helpers, including
# client_params.sh.

if [ "$(id -u)" -ne 0 ]; then
    echo "Run as root: sudo bash $0" >&2
    exit 1
fi

OPENNDS_VERSION="${OPENNDS_VERSION:-10.1.0}"
SRC_ROOT="${TARASEC_OPENNDS_SRC_ROOT:-/usr/local/src}"
SRC_DIR="$SRC_ROOT/tarasec-opennds-$OPENNDS_VERSION"
TARBALL="$SRC_ROOT/opennds-$OPENNDS_VERSION.tar.gz"
URL="https://codeload.github.com/opennds/opennds/tar.gz/refs/tags/v$OPENNDS_VERSION"

if command -v ndsctl >/dev/null 2>&1 && [ -x /usr/lib/opennds/client_params.sh ]; then
    echo "openNDS already installed with client_params.sh."
    ndsctl -v 2>/dev/null || true
    exit 0
fi

. /etc/os-release
if [ "${ID:-}" != "ubuntu" ] && [ "${ID:-}" != "debian" ] && [ "${ID:-}" != "raspbian" ] && [ "${ID_LIKE:-}" != *debian* ]; then
    echo "ERROR: this helper currently supports Debian-family systems." >&2
    exit 1
fi

# Try the native package first. Some Debian-family releases provide openNDS,
# while Raspberry Pi installations commonly need the source fallback.
apt-get update
if apt-cache show opennds >/dev/null 2>&1; then
    echo "Trying distribution openNDS package..."
    DEBIAN_FRONTEND=noninteractive apt-get install -y opennds || true
fi

if command -v ndsctl >/dev/null 2>&1 && [ -x /usr/lib/opennds/client_params.sh ]; then
    echo "Distribution openNDS package is usable."
    exit 0
fi

echo "Distribution package unavailable/incomplete; building openNDS $OPENNDS_VERSION from source."
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    build-essential ca-certificates curl pkg-config \
    libmicrohttpd-dev nftables iptables

mkdir -p "$SRC_ROOT"
rm -rf "$SRC_DIR"
curl -fL "$URL" -o "$TARBALL"
tar -xzf "$TARBALL" -C "$SRC_ROOT"
UPSTREAM_DIR="$SRC_ROOT/openNDS-$OPENNDS_VERSION"
if [ ! -d "$UPSTREAM_DIR" ]; then
    echo "ERROR: expected extracted directory $UPSTREAM_DIR not found." >&2
    exit 1
fi
mv "$UPSTREAM_DIR" "$SRC_DIR"

make -C "$SRC_DIR"
make -C "$SRC_DIR" install
ldconfig

if ! command -v ndsctl >/dev/null 2>&1; then
    echo "ERROR: source installation did not install ndsctl." >&2
    exit 1
fi
if [ ! -x /usr/lib/opennds/client_params.sh ]; then
    echo "ERROR: source installation did not install /usr/lib/opennds/client_params.sh." >&2
    exit 1
fi

systemctl daemon-reload || true
systemctl enable opennds 2>/dev/null || true

echo "openNDS $OPENNDS_VERSION installed successfully."
echo "client_params.sh: /usr/lib/opennds/client_params.sh"
