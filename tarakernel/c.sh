#!/usr/bin/env bash
set -euo pipefail

SRC_DIR="$(cd "$(dirname "$0")" && pwd)"
KERNEL="$(uname -r)"
BUILD_ROOT="${TARASEC_BUILD_DIR:-$HOME/taransvar-build}"
BUILD_DIR="$BUILD_ROOT/tarakernel/$KERNEL"
INSTALL_DIR="/lib/modules/$KERNEL/extra"

mkdir -p "$BUILD_DIR"
rm -rf "$BUILD_DIR/src"
mkdir -p "$BUILD_DIR/src"

# Kernel external-module builds create many generated files beside the source.
# Build from a disposable copy so the Git working tree stays source-only.
cp "$SRC_DIR"/*.c "$SRC_DIR"/*.h "$SRC_DIR/Makefile" "$BUILD_DIR/src/" 2>/dev/null || true

make -C "/lib/modules/$KERNEL/build" M="$BUILD_DIR/src" modules

KO="$BUILD_DIR/src/tarakernel.ko"
[[ -f "$KO" ]] || { echo "Build failed: $KO not produced" >&2; exit 1; }

echo "Built: $KO"

if [[ "${1:-}" == "--install" ]]; then
    sudo mkdir -p "$INSTALL_DIR"
    sudo modprobe -r tarakernel 2>/dev/null || true
    sudo install -m 0644 "$KO" "$INSTALL_DIR/tarakernel.ko"
    sudo depmod -a "$KERNEL"
    sudo modprobe tarakernel
    sudo lsmod | grep '^tarakernel' || true
    echo "Installed: $INSTALL_DIR/tarakernel.ko"
else
    echo "To install/reload it: $0 --install"
fi
