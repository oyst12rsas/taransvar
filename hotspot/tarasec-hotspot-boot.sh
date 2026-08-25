#!/usr/bin/env bash
set -euo pipefail

STATE=/etc/tarasec/hotspot.conf
log(){ echo "[TaraSec boot] $*"; }
value(){ [[ -r "$STATE" ]] && sed -n "s/^$1=//p" "$STATE" | tail -1; }

H="${TARASEC_HOTSPOT_IF:-$(value HOTSPOT_IF)}"
GW="${TARASEC_HOTSPOT_IP:-$(value HOTSPOT_IP)}"
[[ -n "$H" ]] || { log "No hotspot interface in $STATE"; exit 1; }
ip link show "$H" >/dev/null 2>&1 || { log "Interface $H does not exist"; exit 1; }

# NetworkManager can restore a saved Wi-Fi-disabled state after systemd-rfkill
# has already run. On a dedicated TaraSec hotspot interface we must override
# that state and prevent NetworkManager from taking ownership of the AP radio.
if command -v systemctl >/dev/null 2>&1 && systemctl -q is-enabled NetworkManager.service 2>/dev/null; then
    for _ in 1 2 3 4 5 6 7 8 9 10; do
        systemctl is-active --quiet NetworkManager.service && break
        sleep 1
    done
fi
if command -v nmcli >/dev/null 2>&1 && systemctl is-active --quiet NetworkManager.service 2>/dev/null; then
    nmcli radio wifi on 2>/dev/null || true
    nmcli device set "$H" managed no 2>/dev/null || true
fi

# Some Wi-Fi drivers can still come back soft-blocked. Do this after
# NetworkManager has restored its state so TaraSec wins the final radio state.
if command -v rfkill >/dev/null 2>&1; then
    rfkill unblock wifi 2>/dev/null || true
    if rfkill list 2>/dev/null | grep -A4 -F 'Wireless LAN' | grep -q 'Hard blocked: yes'; then
        log "Wi-Fi is hardware blocked"
        exit 1
    fi
fi

# Give NetworkManager/driver state a short chance to settle. If NM races us one
# more time, reinforce both its radio state and rfkill before each retry.
for _ in 1 2 3 4 5 6 7 8 9 10; do
    if ip link set dev "$H" up 2>/dev/null; then
        break
    fi
    command -v nmcli >/dev/null 2>&1 && nmcli radio wifi on 2>/dev/null || true
    command -v rfkill >/dev/null 2>&1 && rfkill unblock wifi 2>/dev/null || true
    sleep 1
done
ip link set dev "$H" up

# Restore the saved gateway address if a partial/failed previous boot left the
# interface without it. The normal interface unit will subsequently normalise
# the address as well.
if [[ -n "$GW" ]] && ! ip -4 addr show dev "$H" | grep -q "inet $GW/24"; then
    ip addr flush dev "$H" 2>/dev/null || true
    ip addr add "$GW/24" dev "$H"
fi

# Hosts running TaraSec itself can have INPUT policy DROP. openNDS accepts DHCP,
# DNS and portal traffic in its own hooked chains, but a later DROP base chain
# can still discard the packet. Install idempotent allowances in the effective
# iptables INPUT path before DHCP/openNDS start.
if command -v iptables >/dev/null 2>&1 && iptables -S INPUT >/dev/null 2>&1; then
    allow(){
        local proto="$1" port="$2"
        iptables -C INPUT -i "$H" -p "$proto" --dport "$port" -j ACCEPT 2>/dev/null || \
            iptables -I INPUT 1 -i "$H" -p "$proto" --dport "$port" -j ACCEPT
    }
    allow udp 67
    allow udp 53
    allow tcp 53
    allow tcp 2050
fi

log "$H ready${GW:+ at $GW/24}; NetworkManager released interface; captive-portal input allowances installed"
