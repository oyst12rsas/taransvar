#!/usr/bin/env bash
# TaraSec hotspot firewall helper.
# Optional arguments: HOTSPOT_IF WAN_IF HOTSPOT_CIDR
# With no arguments, values are read from /etc/tarasec/hotspot.conf.
set -u
STATE=/etc/tarasec/hotspot.conf
state_value() { [[ -r "$STATE" ]] && sed -n "s/^$1=//p" "$STATE" | tail -1; }
H="${1:-$(state_value HOTSPOT_IF)}"
W="${2:-$(state_value WAN_IF)}"
C="${3:-$(state_value HOTSPOT_CIDR)}"
[[ -n "$H" && -n "$W" && -n "$C" ]] || { echo '[TaraSec firewall] missing hotspot state/arguments' >&2; exit 1; }
IPT="$(command -v iptables || true)"
IPTSAVE="$(command -v iptables-save || true)"
NFT="$(command -v nft || true)"

# Some older TaraSec/router installations use iptables-nft chains such as
# INPUT_LEGACY with policy DROP. nftables evaluates later base chains even when
# an earlier TaraSec base chain accepted the packet, so DHCP still gets dropped.
# Add the narrow DHCP exception to every iptables filter base chain whose policy
# is DROP. User-defined chains have policy '-' and are not touched.
allow_dhcp_in_legacy_drop_chains() {
    [[ -n "$IPT" && -n "$IPTSAVE" ]] || return 0
    local chain
    while read -r chain; do
        [[ -n "$chain" ]] || continue
        if "$IPT" -C "$chain" -i "$H" -p udp --dport 67 -j ACCEPT 2>/dev/null; then
            continue
        fi
        if "$IPT" -I "$chain" 1 -i "$H" -p udp --dport 67 -j ACCEPT 2>/dev/null; then
            echo "[TaraSec firewall] allowed DHCP in existing DROP chain $chain" >&2
        else
            echo "[TaraSec firewall] warning: could not add DHCP exception to DROP chain $chain" >&2
        fi
    done < <("$IPTSAVE" -t filter 2>/dev/null | awk '$1 ~ /^:/ && $2 == "DROP" {gsub(/^:/,"",$1); print $1}')
}

iptables_usable() {
    [[ -n "$IPT" ]] || return 1
    "$IPT" -S INPUT >/dev/null 2>&1 || return 1
    "$IPT" -S FORWARD >/dev/null 2>&1 || return 1
    "$IPT" -t nat -S POSTROUTING >/dev/null 2>&1 || return 1
}

setup_iptables() {
    run() { "$IPT" "$@" || { echo "[TaraSec firewall] failed: iptables $*" >&2; return 1; }; }

    "$IPT" -D INPUT -j TARASEC-HOTSPOT-IN 2>/dev/null || true
    "$IPT" -F TARASEC-HOTSPOT-IN 2>/dev/null || true
    "$IPT" -X TARASEC-HOTSPOT-IN 2>/dev/null || true
    run -N TARASEC-HOTSPOT-IN || return 1
    run -I INPUT 1 -j TARASEC-HOTSPOT-IN || return 1
    run -A TARASEC-HOTSPOT-IN -i "$H" -p udp --dport 67 -j ACCEPT || return 1
    run -A TARASEC-HOTSPOT-IN -j RETURN || return 1

    "$IPT" -D FORWARD -j TARASEC-HOTSPOT-FWD 2>/dev/null || true
    "$IPT" -F TARASEC-HOTSPOT-FWD 2>/dev/null || true
    "$IPT" -X TARASEC-HOTSPOT-FWD 2>/dev/null || true
    run -N TARASEC-HOTSPOT-FWD || return 1
    run -I FORWARD 1 -j TARASEC-HOTSPOT-FWD || return 1
    run -A TARASEC-HOTSPOT-FWD -i "$H" -o "$W" -s "$C" -j ACCEPT || return 1
    run -A TARASEC-HOTSPOT-FWD -i "$W" -o "$H" -d "$C" -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT || return 1
    run -A TARASEC-HOTSPOT-FWD -j RETURN || return 1

    "$IPT" -t nat -D POSTROUTING -j TARASEC-HOTSPOT-NAT 2>/dev/null || true
    "$IPT" -t nat -F TARASEC-HOTSPOT-NAT 2>/dev/null || true
    "$IPT" -t nat -X TARASEC-HOTSPOT-NAT 2>/dev/null || true
    run -t nat -N TARASEC-HOTSPOT-NAT || return 1
    run -t nat -I POSTROUTING 1 -j TARASEC-HOTSPOT-NAT || return 1
    run -t nat -A TARASEC-HOTSPOT-NAT -s "$C" -o "$W" -j MASQUERADE || return 1
    run -t nat -A TARASEC-HOTSPOT-NAT -j RETURN || return 1
    echo "[TaraSec firewall] active via $($IPT --version 2>/dev/null | head -1)" >&2
}

setup_nft() {
    [[ -n "$NFT" ]] || { echo '[TaraSec firewall] neither usable iptables nor nft found' >&2; return 1; }
    "$NFT" delete table inet tarasec_hotspot 2>/dev/null || true
    "$NFT" delete table ip tarasec_hotspot_nat 2>/dev/null || true
    "$NFT" -f - <<NFT_RULES
add table inet tarasec_hotspot
add chain inet tarasec_hotspot input { type filter hook input priority -50; policy accept; }
add rule inet tarasec_hotspot input iifname "$H" udp dport 67 accept
add chain inet tarasec_hotspot forward { type filter hook forward priority -50; policy accept; }
add rule inet tarasec_hotspot forward iifname "$H" oifname "$W" ip saddr $C accept
add rule inet tarasec_hotspot forward iifname "$W" oifname "$H" ip daddr $C ct state established,related accept
add table ip tarasec_hotspot_nat
add chain ip tarasec_hotspot_nat postrouting { type nat hook postrouting priority srcnat; policy accept; }
add rule ip tarasec_hotspot_nat postrouting ip saddr $C oifname "$W" masquerade
NFT_RULES
    allow_dhcp_in_legacy_drop_chains
    echo '[TaraSec firewall] active via native nftables (DHCP input explicitly allowed)' >&2
}

if iptables_usable; then
    setup_iptables || { echo '[TaraSec firewall] iptables setup failed; trying native nftables' >&2; setup_nft; }
else
    echo '[TaraSec firewall] iptables compatibility layer unusable; using native nftables' >&2
    setup_nft
fi
