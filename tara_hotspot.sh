#!/usr/bin/env bash
set -euo pipefail

# Compatibility entry point. The maintained installer lives in hotspot/install.sh.
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
exec bash "$SCRIPT_DIR/hotspot/install.sh" "$@"
