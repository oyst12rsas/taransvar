#!/usr/bin/env bash
set -euo pipefail

VERSION="${OPENNDS_VERSION:-11.0.0}"
TAG="v${VERSION}"
STATE=/etc/tarasec/hotspot.conf
WORK=/usr/local/src/tarasec-opennds-${VERSION}
BACKUP=/root/tarasec-opennds-backup-$(date +%Y%m%d-%H%M%S)
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BRIDGE="${TARASEC_HOTSPOT_BRIDGE:-br-tarasec}"

log(){ echo "[TaraSec openNDS] $*"; }
die(){ echo "[TaraSec openNDS ERROR] $*" >&2; exit 1; }
state_value(){ [[ -r "$STATE" ]] && sed -n "s/^$1=//p" "$STATE" | tail -1; }

[[ ${EUID:-$(id -u)} -eq 0 ]] || exec sudo -E bash "$0" "$@"
command -v apt-get >/dev/null || die "Debian/Ubuntu/Raspberry Pi OS required"

HOTSPOT_IF="${TARASEC_HOTSPOT_IF:-$(state_value HOTSPOT_IF)}"
[[ -n "$HOTSPOT_IF" ]] || die "Hotspot interface unknown; run hotspot/install.sh first or set TARASEC_HOTSPOT_IF"
ip link show "$HOTSPOT_IF" >/dev/null 2>&1 || die "Hotspot interface '$HOTSPOT_IF' does not exist"

mkdir -p "$BACKUP" /usr/local/src
[[ -d /etc/opennds ]] && cp -a /etc/opennds "$BACKUP/" || true
[[ -f /etc/config/opennds ]] && { mkdir -p "$BACKUP/etc-config"; cp -a /etc/config/opennds "$BACKUP/etc-config/"; } || true

systemctl disable --now opennds 2>/dev/null || true

export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y build-essential git libmicrohttpd-dev nftables ca-certificates curl
apt-get remove -y opennds opennds-daemon opennds-daemon-common 2>/dev/null || true

rm -rf "$WORK"
git clone --depth 1 --branch "$TAG" https://github.com/openNDS/openNDS.git "$WORK"
make -C "$WORK" -j"$(nproc)"
make -C "$WORK" install

# openNDS 11 forbids using a raw wireless interface as GatewayInterface.
# Convert the TaraSec hotspot to a dedicated bridge and bind DHCP/firewall/openNDS there.
[[ -x "$SCRIPT_DIR/setup-bridge.sh" ]] || chmod +x "$SCRIPT_DIR/setup-bridge.sh"
TARASEC_HOTSPOT_BRIDGE="$BRIDGE" "$SCRIPT_DIR/setup-bridge.sh"

CFG=/etc/config/opennds
[[ -f "$CFG" ]] || die "openNDS installation did not create $CFG"

# Restore a clean upstream v11 config and set only the TaraSec bridge interface.
cp "$WORK/linux_openwrt/opennds/files/etc/config/opennds" "$CFG"
if grep -qE "^[[:space:]]*option[[:space:]]+gatewayinterface" "$CFG"; then
    sed -i -E "s|^[[:space:]]*option[[:space:]]+gatewayinterface.*|\toption gatewayinterface '$BRIDGE'|" "$CFG"
else
    sed -i "/^[[:space:]]*config[[:space:]]\+opennds[[:space:]]\+'setup'/a\	option gatewayinterface '$BRIDGE'" "$CFG"
fi

# TaraSec uses a dedicated dnsmasq instance. Leave upstream automatic lease-file
# discovery enabled for now; the main installer will later set an explicit lease
# file once hotspot registration/client accounting is integrated.

# Reapply firewall rules with the bridge as the hotspot-facing interface.
if [[ -x "$SCRIPT_DIR/tarasec-hotspot-firewall-v2.sh" ]]; then
    "$SCRIPT_DIR/tarasec-hotspot-firewall-v2.sh"
fi

systemctl daemon-reload
systemctl enable opennds
systemctl restart opennds
sleep 8

if ! systemctl is-active --quiet opennds; then
    journalctl -u opennds -n 80 --no-pager >&2 || true
    die "openNDS failed to start"
fi

INSTALLED="$(opennds -v 2>/dev/null | head -1 || true)"
log "Installed: ${INSTALLED:-openNDS $VERSION}"
log "Radio interface: $HOTSPOT_IF"
log "Gateway bridge: $BRIDGE"
log "Config: $CFG"
log "Backup: $BACKUP"
log "Connect a client and open an HTTP page to trigger captive portal detection."
