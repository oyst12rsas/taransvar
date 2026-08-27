#!/bin/bash
set -euo pipefail

# Install the NetBird agent as TaraSec's management plane.
# Hotspot client traffic must continue to use the normal WAN; wt0 is not
# configured here as a default route or as the hotspot/client interface.
#
# Optional enrollment environment:
#   NB_SETUP_KEY=...            reusable/one-off setup key
#   NB_MANAGEMENT_URL=https://...  self-hosted NetBird management URL
#
# Example:
#   sudo NB_SETUP_KEY='...' NB_MANAGEMENT_URL='https://netbird.example.org' \
#     bash misc/install_netbird_management.sh

if [ "$(id -u)" -ne 0 ]; then
    echo "Run as root: sudo bash $0" >&2
    exit 1
fi

if ! command -v curl >/dev/null 2>&1; then
    apt-get update
    apt-get install -y curl ca-certificates
fi

if ! command -v netbird >/dev/null 2>&1; then
    echo "Installing NetBird agent..."
    curl -fsSL https://pkgs.netbird.io/install.sh | sh
else
    echo "NetBird is already installed: $(netbird version 2>/dev/null || true)"
fi

# Ensure the daemon is available where the package exposes a systemd unit.
if systemctl list-unit-files 2>/dev/null | grep -q '^netbird\.service'; then
    systemctl enable --now netbird.service
fi

if [ -n "${NB_SETUP_KEY:-}" ]; then
    args=(up --setup-key "$NB_SETUP_KEY")
    if [ -n "${NB_MANAGEMENT_URL:-}" ]; then
        args+=(--management-url "$NB_MANAGEMENT_URL")
    fi
    echo "Enrolling this TaraSec unit in NetBird management..."
    netbird "${args[@]}"
else
    echo
    echo "NetBird agent installed but not enrolled."
    echo "Enroll with a setup key, for example:"
    if [ -n "${NB_MANAGEMENT_URL:-}" ]; then
        echo "  sudo netbird up --setup-key '<key>' --management-url '${NB_MANAGEMENT_URL}'"
    else
        echo "  sudo netbird up --setup-key '<key>'"
    fi
fi

echo
echo "=== NetBird status ==="
netbird status 2>/dev/null || true

echo
echo "Management-plane rule: wt0 is reserved for TaraSec/NetBird management."
echo "Do not select wt0 as WAN or hotspot client interface."
