#!/bin/bash
set -euo pipefail

# Demo 4 hotspot-side selective partner routing.
#
# A TaraSec TCP tag is currently carried in tcp.urg_ptr. tarakernel runs in
# PREROUTING immediately after conntrack, before the iptables mangle hook.
# This script therefore detects a non-zero urg_ptr only for an explicitly
# authorized partner destination, converts that decision to an skb/conntrack
# mark, and policy-routes the complete flow through either a plain WireGuard
# tunnel or an existing NetBird peer.
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

: "${DEMO4_TRANSPORT:=wireguard}"
: "${WG_INTERFACE:=wg-demo4}"
: "${NETBIRD_INTERFACE:=wt0}"
: "${PARTNER_DESTINATION:?PARTNER_DESTINATION is required, e.g. 85.190.98.245/32}"
: "${DEMO4_MARK:=0x44}"
: "${DEMO4_MARK_MASK:=0xff}"
: "${DEMO4_TABLE:=404}"
: "${DEMO4_RULE_PRIORITY:=10404}"
: "${DEMO4_BLACKHOLE_PRIORITY:=10405}"
: "${WG_KEEPALIVE:=25}"

case "$DEMO4_TRANSPORT" in
  wireguard)
    : "${WG_ADDRESS:?WG_ADDRESS is required for wireguard transport}"
    : "${WG_PRIVATE_KEY_FILE:?WG_PRIVATE_KEY_FILE is required for wireguard transport}"
    : "${WG_PEER_PUBLIC_KEY:?WG_PEER_PUBLIC_KEY is required for wireguard transport}"
    : "${WG_ENDPOINT:?WG_ENDPOINT is required for wireguard transport}"
    ROUTE_INTERFACE=$WG_INTERFACE
    ;;
  netbird)
    : "${NETBIRD_RELAY:?NETBIRD_RELAY is required for netbird transport, e.g. 100.68.53.242}"
    ROUTE_INTERFACE=$NETBIRD_INTERFACE
    ;;
  *) echo "Unsupported DEMO4_TRANSPORT: $DEMO4_TRANSPORT" >&2; exit 1 ;;
esac

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
  command -v iptables >/dev/null || { echo "iptables is required" >&2; exit 1; }

  if [[ "$DEMO4_TRANSPORT" == wireguard ]]; then
    command -v wg >/dev/null || { echo "wireguard-tools (wg) is required" >&2; exit 1; }
    [[ -r "$WG_PRIVATE_KEY_FILE" ]] || { echo "Cannot read $WG_PRIVATE_KEY_FILE" >&2; exit 1; }
    if ! ip link show "$WG_INTERFACE" >/dev/null 2>&1; then
      ip link add dev "$WG_INTERFACE" type wireguard
    fi
    ip address flush dev "$WG_INTERFACE"
    ip address add "$WG_ADDRESS" dev "$WG_INTERFACE"
    wg set "$WG_INTERFACE" \
      private-key "$WG_PRIVATE_KEY_FILE" \
      peer "$WG_PEER_PUBLIC_KEY" \
      endpoint "$WG_ENDPOINT" \
      allowed-ips 0.0.0.0/0 \
      persistent-keepalive "$WG_KEEPALIVE"
    ip link set up dev "$WG_INTERFACE"
    ip route replace table "$DEMO4_TABLE" default dev "$WG_INTERFACE"
  else
    ip link show "$NETBIRD_INTERFACE" >/dev/null 2>&1 || {
      echo "NetBird interface not found: $NETBIRD_INTERFACE" >&2; exit 1;
    }
    ip route get "$NETBIRD_RELAY" | grep -Eq "[[:space:]]dev ${NETBIRD_INTERFACE}([[:space:]]|$)" || {
      echo "NetBird relay $NETBIRD_RELAY is not routed through $NETBIRD_INTERFACE" >&2; exit 1;
    }
    # Destination-specific by design: never capture clean/default traffic.
    ip route replace table "$DEMO4_TABLE" "$PARTNER_DESTINATION" \
      via "$NETBIRD_RELAY" dev "$NETBIRD_INTERFACE" onlink
  fi

  mark_rule_exists || ip rule add priority "$DEMO4_RULE_PRIORITY" \
    fwmark "${DEMO4_MARK}/${DEMO4_MARK_MASK}" lookup "$DEMO4_TABLE"

  # Critical fail-closed rule: if table 404 cannot route a marked packet,
  # do not continue into 'main' and leak it untagged through the normal uplink.
  blackhole_rule_exists || ip rule add priority "$DEMO4_BLACKHOLE_PRIORITY" \
    blackhole fwmark "${DEMO4_MARK}/${DEMO4_MARK_MASK}"

  ensure_chain

  # Hide the hotspot/client source behind the selected overlay interface.
  iptables -t nat -C POSTROUTING -m mark --mark "${DEMO4_MARK}/${DEMO4_MARK_MASK}" \
    -o "$ROUTE_INTERFACE" -j MASQUERADE 2>/dev/null || \
  iptables -t nat -A POSTROUTING -m mark --mark "${DEMO4_MARK}/${DEMO4_MARK_MASK}" \
    -o "$ROUTE_INTERFACE" -j MASQUERADE

  echo "Demo 4 hotspot policy enabled."
  status
}

remove() {
  iptables -t mangle -D PREROUTING -j "$CHAIN" 2>/dev/null || true
  iptables -t mangle -F "$CHAIN" 2>/dev/null || true
  iptables -t mangle -X "$CHAIN" 2>/dev/null || true

  while iptables -t nat -C POSTROUTING -m mark --mark "${DEMO4_MARK}/${DEMO4_MARK_MASK}" \
      -o "$ROUTE_INTERFACE" -j MASQUERADE 2>/dev/null; do
    iptables -t nat -D POSTROUTING -m mark --mark "${DEMO4_MARK}/${DEMO4_MARK_MASK}" \
      -o "$ROUTE_INTERFACE" -j MASQUERADE
  done

  ip rule del priority "$DEMO4_RULE_PRIORITY" 2>/dev/null || true
  ip rule del priority "$DEMO4_BLACKHOLE_PRIORITY" 2>/dev/null || true
  ip route flush table "$DEMO4_TABLE" 2>/dev/null || true
  if [[ "$DEMO4_TRANSPORT" == wireguard ]]; then
    ip link del dev "$WG_INTERFACE" 2>/dev/null || true
  fi

  echo "Demo 4 hotspot policy removed. Normal routing is unchanged."
}

status() {
  echo "=== DEMO 4 HOTSPOT ==="
  echo "Transport           : $DEMO4_TRANSPORT"
  echo "Partner destination : $PARTNER_DESTINATION"
  echo "Overlay interface   : $ROUTE_INTERFACE"
  if [[ "$DEMO4_TRANSPORT" == netbird ]]; then
    echo "NetBird relay       : $NETBIRD_RELAY"
  fi
  echo "Routing mark        : $DEMO4_MARK/$DEMO4_MARK_MASK"
  echo "Routing table       : $DEMO4_TABLE"
  echo
  ip -brief address show dev "$ROUTE_INTERFACE" 2>/dev/null || true
  if [[ "$DEMO4_TRANSPORT" == wireguard ]]; then
    wg show "$WG_INTERFACE" 2>/dev/null || true
  fi
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
  echo "=== OVERLAY NAT COUNTER ==="
  iptables -t nat -nvL POSTROUTING 2>/dev/null | grep -F "$ROUTE_INTERFACE" || true
}

case "${1:-status}" in
  setup) setup ;;
  remove|down|cleanup) remove ;;
  status) status ;;
  *) echo "Usage: $0 {setup|status|remove}" >&2; exit 2 ;;
esac
