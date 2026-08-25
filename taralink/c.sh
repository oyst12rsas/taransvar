#!/usr/bin/env bash
set -euo pipefail

SRC_DIR="$(cd "$(dirname "$0")" && pwd)"
BUILD_ROOT="${TARASEC_BUILD_DIR:-$HOME/taransvar-build}"
BUILD_DIR="$BUILD_ROOT/taralink"
BIN="$BUILD_DIR/taralink"

mkdir -p "$BUILD_DIR"

gcc "$SRC_DIR/taralink.c" -o "$BIN" -L/usr/lib/mysql -lmysqlclient -lcurl

echo "Built: $BIN"

if [[ "${1:-}" == "--install" ]]; then
    sudo mkdir -p /usr/local/lib/tarasec /var/log/tarasec
    sudo install -m 0755 "$BIN" /usr/local/lib/tarasec/taralink
    echo "Installed: /usr/local/lib/tarasec/taralink"
    echo "Runtime logs should be written under /var/log/tarasec, not the Git checkout."
else
    echo "To install it: $0 --install"
fi
