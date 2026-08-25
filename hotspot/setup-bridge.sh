#!/usr/bin/env bash
set -euo pipefail

STATE=/etc/tarasec/hotspot.conf
BRIDGE="${TARASEC_HOTSPOT_BRIDGE:-br-tarasec}"
state_value(){ [[ -r "$STATE" ]] && sed -n "s/^$1=//p" "$STATE" | tail -1; }
die(){ echo "[TaraSec bridge ERROR] $*" >&2; exit 1; }
log(){ echo "[TaraSec bridge] $*"; }

[[ ${EUID:-$(id -u)} -eq 0 ]] || exec sudo -E bash "$0" "$@"

WLAN="${TARASEC_HOTSPOT_IF:-$(state_value HOTSPOT_IF)}"
GW="${TARASEC_HOTSPOT_IP:-$(state_value HOTSPOT_IP)}"
[[ -n "$WLAN" && -n "$GW" ]] || die "Missing hotspot state; run hotspot/install.sh first"
ip link show "$WLAN" >/dev/null 2>&1 || die "Hotspot interface '$WLAN' does not exist"

systemctl stop opennds 2>/dev/null || true
systemctl stop hostapd 2>/dev/null || true
systemctl stop tarasec-hotspot-dnsmasq 2>/dev/null || true

# Create/reuse a dedicated L2 bridge. Never add the WAN interface to this bridge.
ip link show "$BRIDGE" >/dev/null 2>&1 || ip link add name "$BRIDGE" type bridge
ip link set "$BRIDGE" up
ip addr flush dev "$WLAN" || true
ip link set "$WLAN" nomaster 2>/dev/null || true
ip link set "$WLAN" master "$BRIDGE"
ip link set "$WLAN" up
ip addr flush dev "$BRIDGE" || true
ip addr add "$GW/24" dev "$BRIDGE"

# Let hostapd manage the AP as a bridge member on future restarts.
if [[ -f /etc/hostapd/hostapd.conf ]]; then
    sed -i '/^bridge=/d' /etc/hostapd/hostapd.conf
    printf '\nbridge=%s\n' "$BRIDGE" >> /etc/hostapd/hostapd.conf
fi

# DHCP belongs to the bridge, not to the physical radio.
if [[ -f /etc/tarasec/dnsmasq-hotspot.conf ]]; then
    sed -i -E "s/^interface=.*/interface=$BRIDGE/" /etc/tarasec/dnsmasq-hotspot.conf
    sed -i -E "s/^listen-address=.*/listen-address=$GW/" /etc/tarasec/dnsmasq-hotspot.conf
fi

# Persist bridge creation before hostapd/dnsmasq at boot.
cat >/etc/systemd/system/tarasec-hotspot-interface.service <<EOF
[Unit]
Description=TaraSec hotspot bridge/interface
After=network-online.target
Wants=network-online.target
Before=hostapd.service tarasec-hotspot-dnsmasq.service opennds.service
[Service]
Type=oneshot
ExecStart=/bin/sh -c '/sbin/ip link show $BRIDGE >/dev/null 2>&1 || /sbin/ip link add name $BRIDGE type bridge'
ExecStart=/sbin/ip link set dev $BRIDGE up
ExecStart=/bin/sh -c '/sbin/ip addr flush dev $WLAN || true'
ExecStart=/bin/sh -c '/sbin/ip link set dev $WLAN nomaster 2>/dev/null || true'
ExecStart=/sbin/ip link set dev $WLAN master $BRIDGE
ExecStart=/sbin/ip link set dev $WLAN up
ExecStart=/bin/sh -c '/sbin/ip addr flush dev $BRIDGE || true'
ExecStart=/sbin/ip addr add $GW/24 dev $BRIDGE
RemainAfterExit=yes
[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable tarasec-hotspot-interface >/dev/null

# Save bridge state without disturbing existing installer state.
if grep -q '^HOTSPOT_BRIDGE=' "$STATE" 2>/dev/null; then
    sed -i "s/^HOTSPOT_BRIDGE=.*/HOTSPOT_BRIDGE=$BRIDGE/" "$STATE"
else
    echo "HOTSPOT_BRIDGE=$BRIDGE" >> "$STATE"
fi

systemctl restart tarasec-hotspot-dnsmasq
systemctl restart hostapd

log "Bridge: $BRIDGE"
log "Radio:  $WLAN"
log "Gateway: $GW/24"
log "DHCP and captive portal should bind to $BRIDGE"
