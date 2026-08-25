#!/usr/bin/env bash
set -euo pipefail

# Generic Debian/Ubuntu/Raspberry Pi OS path.
# openNDS 10.1.0 provides nftables-era generic Linux support while still
# allowing a raw wireless gateway interface. openNDS 11's installed helper
# currently rejects a raw wireless gateway and requires a bridge, which is not
# supported by every Raspberry Pi Wi-Fi driver.
VERSION="${OPENNDS_VERSION:-10.1.0}"
TAG="v${VERSION}"
STATE=/etc/tarasec/hotspot.conf
WORK=/usr/local/src/tarasec-opennds-${VERSION}
BACKUP=/root/tarasec-opennds-backup-$(date +%Y%m%d-%H%M%S)
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
STALE_BRIDGE="${TARASEC_HOTSPOT_BRIDGE:-br-tarasec}"

log(){ echo "[TaraSec openNDS] $*"; }
die(){ echo "[TaraSec openNDS ERROR] $*" >&2; exit 1; }
state_value(){ [[ -r "$STATE" ]] && sed -n "s/^$1=//p" "$STATE" | tail -1; }

[[ ${EUID:-$(id -u)} -eq 0 ]] || exec sudo -E bash "$0" "$@"
command -v apt-get >/dev/null || die "Debian/Ubuntu/Raspberry Pi OS required"

HOTSPOT_IF="${TARASEC_HOTSPOT_IF:-$(state_value HOTSPOT_IF)}"
GW="${TARASEC_HOTSPOT_IP:-$(state_value HOTSPOT_IP)}"
[[ -n "$HOTSPOT_IF" && -n "$GW" ]] || die "Hotspot state incomplete; run hotspot/install.sh first"
ip link show "$HOTSPOT_IF" >/dev/null 2>&1 || die "Hotspot interface '$HOTSPOT_IF' does not exist"

mkdir -p "$BACKUP" /usr/local/src
[[ -d /etc/opennds ]] && cp -a /etc/opennds "$BACKUP/" || true
[[ -f /etc/config/opennds ]] && { mkdir -p "$BACKUP/etc-config"; cp -a /etc/config/opennds "$BACKUP/etc-config/"; } || true

systemctl disable --now opennds 2>/dev/null || true
systemctl stop hostapd 2>/dev/null || true
systemctl stop tarasec-hotspot-dnsmasq 2>/dev/null || true

# Repair a partial br-tarasec conversion if a previous openNDS 11 attempt
# stopped after creating a bridge but before completing the configuration.
ip link set "$HOTSPOT_IF" nomaster 2>/dev/null || true
if ip link show "$STALE_BRIDGE" >/dev/null 2>&1; then
    if ! bridge link 2>/dev/null | grep -q "master $STALE_BRIDGE"; then
        ip link set "$STALE_BRIDGE" down 2>/dev/null || true
        ip link delete "$STALE_BRIDGE" type bridge 2>/dev/null || true
        log "Removed unused bridge left by an earlier openNDS 11 attempt"
    fi
fi

# Restore the original routed hotspot architecture.
ip addr flush dev "$HOTSPOT_IF" 2>/dev/null || true
ip addr add "$GW/24" dev "$HOTSPOT_IF"
ip link set "$HOTSPOT_IF" up
if [[ -f /etc/hostapd/hostapd.conf ]]; then
    sed -i '/^bridge=/d' /etc/hostapd/hostapd.conf
fi
if [[ -f /etc/tarasec/dnsmasq-hotspot.conf ]]; then
    sed -i -E "s/^interface=.*/interface=$HOTSPOT_IF/" /etc/tarasec/dnsmasq-hotspot.conf
    sed -i -E "s/^listen-address=.*/listen-address=$GW/" /etc/tarasec/dnsmasq-hotspot.conf
fi
# Remove stale bridge state if an earlier helper managed to save it.
if [[ -f "$STATE" ]]; then
    sed -i '/^HOTSPOT_BRIDGE=/d' "$STATE"
fi

export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y build-essential git libmicrohttpd-dev nftables ca-certificates curl
apt-get remove -y opennds opennds-daemon opennds-daemon-common 2>/dev/null || true

rm -rf "$WORK"
git clone --depth 1 --branch "$TAG" https://github.com/openNDS/openNDS.git "$WORK"
make -C "$WORK" -j"$(nproc)"
make -C "$WORK" install

CFG=/etc/config/opennds
[[ -f "$CFG" ]] || die "openNDS installation did not create $CFG"
cp "$WORK/linux_openwrt/opennds/files/etc/config/opennds" "$CFG"

# v10.1 generic Linux supports binding directly to the routed AP interface.
if grep -qE "^[[:space:]]*#?[[:space:]]*option[[:space:]]+gatewayinterface" "$CFG"; then
    sed -i -E "0,/^[[:space:]]*#?[[:space:]]*option[[:space:]]+gatewayinterface.*/s||\toption gatewayinterface '$HOTSPOT_IF'|" "$CFG"
else
    sed -i "/^[[:space:]]*config[[:space:]]\+opennds/a\	option gatewayinterface '$HOTSPOT_IF'" "$CFG"
fi
if grep -qE "^[[:space:]]*#?[[:space:]]*option[[:space:]]+gatewayname" "$CFG"; then
    sed -i -E "0,/^[[:space:]]*#?[[:space:]]*option[[:space:]]+gatewayname.*/s||\toption gatewayname 'TaraSec'|" "$CFG"
fi

# Reapply TaraSec firewall/DHCP exception rules on the physical AP interface.
if [[ -x "$SCRIPT_DIR/tarasec-hotspot-firewall-v2.sh" ]]; then
    "$SCRIPT_DIR/tarasec-hotspot-firewall-v2.sh" "$HOTSPOT_IF"
fi

systemctl daemon-reload
systemctl enable tarasec-hotspot-dnsmasq hostapd opennds >/dev/null
systemctl restart tarasec-hotspot-dnsmasq
systemctl restart hostapd
systemctl restart opennds
sleep 8

if ! systemctl is-active --quiet opennds; then
    journalctl -u opennds -n 80 --no-pager >&2 || true
    die "openNDS failed to start"
fi

INSTALLED="$(opennds -v 2>/dev/null | head -1 || true)"
log "Installed: ${INSTALLED:-openNDS $VERSION}"
log "Gateway interface: $HOTSPOT_IF"
log "Gateway address: $GW/24"
log "Config: $CFG"
log "Backup: $BACKUP"
log "Connect a client and open an HTTP page to trigger captive portal detection."
