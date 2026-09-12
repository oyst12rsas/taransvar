#!/bin/bash
set -euo pipefail

# Demo 4 hotspot-side selective WireGuard routing.
#
# A TaraSec TCP tag is currently carried in tcp.urg_ptr. tarakernel runs in
# PREROUTING immediately after conntrack, before the iptables mangle hook.
# This script therefore detects a non-zero urg_ptr only for an explicitly
# authorized partner destination, converts that decision to an skb/conntrack
# mark, and policy-routes the complete flow through a plain WireGuard tunnel.
#
# Normal/untagged traffic is untouched. If the marked routing table has no
# route, a second fwmark rule blackholes the flow so it cannot silently fall
# back to the normal Safaricom/public route.

CONFIG=${DEMO4_CONFIG:-/etc/tarasec/demo4-wireguard-hotspot.conf}
CHAIN=TARASEC-DEMO4-MARK

if [[ ${EUID} -ne 0 ]]; then
  echo "Run as root." >&2
  exit 1
fi

if [[ ! -r "$CONFIG" ]]; then
  echo "Missing config: $CONFIG" >&2
  echo "Copy misc/demo4-wireguard-hotspot.conf.example to $CONFIG and edit it." >&2
  exit 1
fi

# shellcheck disable=SC1090
source "$CONFIG"

: "${WG_INTERFACE:=wg-demo4}"
: "${WG_ADDRESS:?WG_ADDRESS is required, e.g. 10.47.40.2/30}"
: "${WG_PRIVATE_KEY_FILE:?WG_PRIVATE_KEY_FILE is required}"
: "${WG_PEER_PUBLIC_KEY:?WG_PEER_PUBLIC_KEY is required}"
: "${WG_ENDPOINT:?WG_ENDPOINT is required, e.g. 203.0.113.10:51820}"
: "${PARTNER_DESTINATION:?PARTNER_DESTINATION is required, e.g. 85.190.98.245/32}"
: "${DEMO4_MARK:=0x44}"
: "${DEMO4_MARK_MASK:=0xff}"
: "${DEMO4_TABLE:=404}"
: "${DEMO4_RULE_PRIORITY:=10404}"
: "${DEMO4_BLACKHOLE_PRIORITY:=10405}"
: "${WG_KEEPALIVE:=25}"

mark_rule_exists() {
  ip rule show | grep -Eq "(^|[[:space:]])${DEMO4_RULE_PRIORITY}:.*fwmark ${DEMO4_MARK}(/${DEMO4_MARK_MASK}|[[:space:]]).*lookup ${DEMO4_TABLE}"
}

blackhole_rule_exists() {
  ip rule show | grep -Eq "(^|[[:space:]])${DEMO4_BLACKHOLE_PRIORITY}:.*fwmark ${DEMO4_MARK}(/${DEMO4_MARK_MASK}|[[:space:]]).*blackhole"
}

ensure_chain() {
  iptables -t mangle -N "$CHAIN" 2>/dev/null || true
  iptables -t mangle -F "$CHAIN"

  # Restore a routing decision already saved for this connection.
  iptables -t mangle -A "$CHAIN" \
    -j CONNMARK --restore-mark --nfmask "$DEMO4_MARK_MASK" --ctmask "$DEMO4_MARK_MASK"
  iptables -t mangle -A "$CHAIN" \
    -m mark --mark "${DEMO4_MARK}/${DEMO4_MARK_MASK}" -j RETURN

  # tcp.urg_ptr is bytes 18-19 of the TCP header. u32 first derives the
  # variable IPv4 header length, then reads TCP bytes 16-19; the low 16 bits
  # are urg_ptr. Only an authorized partner destination can acquire the mark.
  iptables -t mangle -A "$CHAIN" \
    -d "$PARTNER_DESTINATION" -p tcp \
    -m u32 --u32 '0>>22&0x3C@16&0xFFFF!=0' \
    -j MARK --set-xmark "${DEMO4_MARK}/${DEMO4_MARK_MASK}"

  # Persist the route selection for ACK/data packets whose urg_ptr is zero.
  iptables -t mangle -A "$CHAIN" \
    -m mark --mark "${DEMO4_MARK}/${DEMO4_MARK_MASK}" \
    -j CONNMARK --save-mark --nfmask "$DEMO4_MARK_MASK" --ctmask "$DEMO4_MARK_MASK"

  iptables -t mangle -C PREROUTING -j "$CHAIN" 2>/dev/null || \
    iptables -t mangle -A PREROUTING -j "$CHAIN"
}

setup() {
  command -v wg >/dev/null || { echo "wireguard-tools (wg) is required" >&2; exit 1; }
  command -v iptables >/dev/null || { echo "iptables is required" >&2; exit 1; }
  [[ -r "$WG_PRIVATE_KEY_FILE" ]] || { echo "Cannot read $WG_PRIVATE_KEY_FILE" >&2; exit 1; }

  if ! ip link show "$WG_INTERFACE" >/dev/null 2>&1; then
    ip link add dev "$WG_INTERFACE" type wireguard
  fi

  ip address flush dev "$WG_INTERFACE"
  ip address add "$WG_ADDRESS" dev "$WG_INTERFACE"

  # AllowedIPs=0/0 only defines what this peer may carry inside WireGuard.
  # It does NOT install a main-table default route because we use wg directly,
  # not wg-quick. The fwmark policy below decides which flows enter the tunnel.
  wg set "$WG_INTERFACE" \
    private-key "$WG_PRIVATE_KEY_FILE" \
    peer "$WG_PEER_PUBLIC_KEY" \
    endpoint "$WG_ENDPOINT" \
    allowed-ips 0.0.0.0/0 \
    persistent-keepalive "$WG_KEEPALIVE"
  ip link set up dev "$WG_INTERFACE"

  ip route replace table "$DEMO4_TABLE" default dev "$WG_INTERFACE"

  mark_rule_exists || ip rule add priority "$DEMO4_RULE_PRIORITY" \
    fwmark "${DEMO4_MARK}/${DEMO4_MARK_MASK}" lookup "$DEMO4_TABLE"

  # Critical fail-closed rule: if table 404 cannot route a marked packet,
  # do not continue into 'main' and leak it untagged through the normal uplink.
  blackhole_rule_exists || ip rule add priority "$DEMO4_BLACKHOLE_PRIORITY" \
    blackhole fwmark "${DEMO4_MARK}/${DEMO4_MARK_MASK}"

  ensure_chain

  # Hide the hotspot/client source behind the WireGuard interface address.
  # This lets the egress peer use a tight AllowedIPs entry for this hotspot.
  iptables -t nat -C POSTROUTING -m mark --mark "${DEMO4_MARK}/${DEMO4_MARK_MASK}" \
    -o "$WG_INTERFACE" -j MASQUERADE 2>/dev/null || \
  iptables -t nat -A POSTROUTING -m mark --mark "${DEMO4_MARK}/${DEMO4_MARK_MASK}" \
    -o "$WG_INTERFACE" -j MASQUERADE

  echo "Demo 4 hotspot policy enabled."
  status
}

remove() {
  iptables -t mangle -D PREROUTING -j "$CHAIN" 2>/dev/null || true
  iptables -t mangle -F "$CHAIN" 2>/dev/null || true
  iptables -t mangle -X "$CHAIN" 2>/dev/null || true

  while iptables -t nat -C POSTROUTING -m mark --mark "${DEMO4_MARK}/${DEMO4_MARK_MASK}" \
      -o "$WG_INTERFACE" -j MASQUERADE 2>/dev/null; do
    iptables -t nat -D POSTROUTING -m mark --mark "${DEMO4_MARK}/${DEMO4_MARK_MASK}" \
      -o "$WG_INTERFACE" -j MASQUERADE
  done

  ip rule del priority "$DEMO4_RULE_PRIORITY" 2>/dev/null || true
  ip rule del priority "$DEMO4_BLACKHOLE_PRIORITY" 2>/dev/null || true
  ip route flush table "$DEMO4_TABLE" 2>/dev/null || true
  ip link del dev "$WG_INTERFACE" 2>/dev/null || true

  echo "Demo 4 hotspot policy removed. Normal routing is unchanged."
}

status() {
  echo "=== DEMO 4 HOTSPOT ==="
  echo "Partner destination : $PARTNER_DESTINATION"
  echo "WireGuard interface : $WG_INTERFACE"
  echo "Routing mark        : $DEMO4_MARK/$DEMO4_MARK_MASK"
  echo "Routing table       : $DEMO4_TABLE"
  echo
  ip -brief address show dev "$WG_INTERFACE" 2>/dev/null || true
  wg show "$WG_INTERFACE" 2>/dev/null || true
  echo
  echo "=== POLICY RULES ==="
  ip rule show | grep -E "${DEMO4_RULE_PRIORITY}:|${DEMO4_BLACKHOLE_PRIORITY}:" || true
  echo
  echo "=== TABLE $DEMO4_TABLE ==="
  ip route show table "$DEMO4_TABLE" || true
  echo
  echo "=== TAG/MARK COUNTERS ==="
  iptables -t mangle -nvL "$CHAIN" 2>/dev/null || true
  echo
  echo "=== WIREGUARD NAT COUNTER ==="
  iptables -t nat -nvL POSTROUTING 2>/dev/null | grep -F "$WG_INTERFACE" || true
}

case "${1:-status}" in
  setup) setup ;;
  remove|down|cleanup) remove ;;
  status) status ;;
  *) echo "Usage: $0 {setup|status|remove}" >&2; exit 2 ;;
esac
