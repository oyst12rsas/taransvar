#!/usr/bin/env bash
set -euo pipefail

CONF=/etc/tarasec/hotspot.conf
[[ -r "$CONF" ]] || exit 0
HOTSPOT_IF=$(sed -n 's/^HOTSPOT_IF=//p' "$CONF" | tail -1)
[[ -n "$HOTSPOT_IF" ]] || exit 0

rfkill unblock wifi 2>/dev/null || true

# Release only the TaraSec radio. Never disable NetworkManager or
# wpa_supplicant globally because the WAN may depend on them.
if command -v nmcli >/dev/null 2>&1; then
    nmcli device set "$HOTSPOT_IF" managed no 2>/dev/null || true
fi
systemctl stop "wpa_supplicant@${HOTSPOT_IF}.service" 2>/dev/null || true
pkill -f "wpa_supplicant.*${HOTSPOT_IF}" 2>/dev/null || true

ip link set dev "$HOTSPOT_IF" up 2>/dev/null || true
