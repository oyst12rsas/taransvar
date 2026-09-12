#!/bin/bash
set -euo pipefail

# Demo 4 fixed-public-IP egress router.
# Accepts only WireGuard traffic from the configured hotspot peer and only to
# the authorized partner destination. New forwarded TCP flows must still carry
# a non-zero TaraSec urg_ptr tag. Established return traffic is allowed.

CONFIG=${DEMO4_CONFIG:-/etc/tarasec/demo4-wireguard-egress.conf}
CHAIN=TARASEC-DEMO4-FWD

if [[ ${EUID} -ne 0 ]]; then
  echo "Run as root." >&2
  exit 1
fi

if [[ ! -r "$CONFIG" ]]; then
  echo "Missing config: $CONFIG" >&2
  echo "Copy misc/demo4-wireguard-egress.conf.example to $CONFIG and edit it." >&2
  exit 1
fi

# shellcheck disable=SC1090
source "$CONFIG"

: "${WG_INTERFACE:=wg-demo4}"
: "${WG_ADDRESS:?WG_ADDRESS is required, e.g. 10.47.40.1/30}"
: "${WG_PRIVATE_KEY_FILE:?WG_PRIVATE_KEY_FILE is required}"
: "${WG_PEER_PUBLIC_KEY:?WG_PEER_PUBLIC_KEY is required}"
: "${WG_PEER_ALLOWED_IP:?WG_PEER_ALLOWED_IP is required, e.g. 10.47.40.2/32}"
: "${WG_LISTEN_PORT:=51820}"
: "${UPLINK_INTERFACE:?UPLINK_INTERFACE is required, e.g. enp1s0}"
: "${PARTNER_DESTINATION:?PARTNER_DESTINATION is required, e.g. 85.190.98.245/32}"
: "${EGRESS_PUBLIC_IP:=}"

setup_forward_chain() {
  iptables -N "$CHAIN" 2>/dev/null || true
  iptables -F "$CHAIN"

  # Return path for flows that were admitted below.
  iptables -A "$CHAIN" -i "$UPLINK_INTERFACE" -o "$WG_INTERFACE" \
    -s "$PARTNER_DESTINATION" -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT

  # Once a tagged SYN has created conntrack state, allow the rest of that flow.
  iptables -A "$CHAIN" -i "$WG_INTERFACE" -o "$UPLINK_INTERFACE" \
    -d "$PARTNER_DESTINATION" -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT

  # Defense in depth: a NEW tunneled TCP flow must both target the authorized
  # participant and contain a non-zero TaraSec urg_ptr tag.
  iptables -A "$CHAIN" -i "$WG_INTERFACE" -o "$UPLINK_INTERFACE" \
    -d "$PARTNER_DESTINATION" -p tcp -m conntrack --ctstate NEW \
    -m u32 --u32 '0>>22&0x3C@16&0xFFFF!=0' -j ACCEPT

  # Never turn the VPS into a generic Internet exit.
  iptables -A "$CHAIN" -i "$WG_INTERFACE" -j DROP

  iptables -C FORWARD -j "$CHAIN" 2>/dev/null || iptables -I FORWARD 1 -j "$CHAIN"
}

setup() {
  command -v wg >/dev/null || { echo "wireguard-tools (wg) is required" >&2; exit 1; }
  [[ -r "$WG_PRIVATE_KEY_FILE" ]] || { echo "Cannot read $WG_PRIVATE_KEY_FILE" >&2; exit 1; }

  if ! ip link show "$WG_INTERFACE" >/dev/null 2>&1; then
    ip link add dev "$WG_INTERFACE" type wireguard
  fi
  ip address flush dev "$WG_INTERFACE"
  ip address add "$WG_ADDRESS" dev "$WG_INTERFACE"
  wg set "$WG_INTERFACE" \
    listen-port "$WG_LISTEN_PORT" \
    private-key "$WG_PRIVATE_KEY_FILE" \
    peer "$WG_PEER_PUBLIC_KEY" \
    allowed-ips "$WG_PEER_ALLOWED_IP"
  ip link set up dev "$WG_INTERFACE"

  sysctl -w net.ipv4.ip_forward=1 >/dev/null
  setup_forward_chain

  if [[ -n "$EGRESS_PUBLIC_IP" ]]; then
    iptables -t nat -C POSTROUTING -o "$UPLINK_INTERFACE" -d "$PARTNER_DESTINATION" \
      -s "$WG_PEER_ALLOWED_IP" -j SNAT --to-source "$EGRESS_PUBLIC_IP" 2>/dev/null || \
    iptables -t nat -A POSTROUTING -o "$UPLINK_INTERFACE" -d "$PARTNER_DESTINATION" \
      -s "$WG_PEER_ALLOWED_IP" -j SNAT --to-source "$EGRESS_PUBLIC_IP"
  else
    iptables -t nat -C POSTROUTING -o "$UPLINK_INTERFACE" -d "$PARTNER_DESTINATION" \
      -s "$WG_PEER_ALLOWED_IP" -j MASQUERADE 2>/dev/null || \
    iptables -t nat -A POSTROUTING -o "$UPLINK_INTERFACE" -d "$PARTNER_DESTINATION" \
      -s "$WG_PEER_ALLOWED_IP" -j MASQUERADE
  fi

  echo "Demo 4 fixed-IP egress enabled."
  status
}

remove() {
  iptables -D FORWARD -j "$CHAIN" 2>/dev/null || true
  iptables -F "$CHAIN" 2>/dev/null || true
  iptables -X "$CHAIN" 2>/dev/null || true

  if [[ -n "$EGRESS_PUBLIC_IP" ]]; then
    while iptables -t nat -C POSTROUTING -o "$UPLINK_INTERFACE" -d "$PARTNER_DESTINATION" \
      -s "$WG_PEER_ALLOWED_IP" -j SNAT --to-source "$EGRESS_PUBLIC_IP" 2>/dev/null; do
      iptables -t nat -D POSTROUTING -o "$UPLINK_INTERFACE" -d "$PARTNER_DESTINATION" \
        -s "$WG_PEER_ALLOWED_IP" -j SNAT --to-source "$EGRESS_PUBLIC_IP"
    done
  else
    while iptables -t nat -C POSTROUTING -o "$UPLINK_INTERFACE" -d "$PARTNER_DESTINATION" \
      -s "$WG_PEER_ALLOWED_IP" -j MASQUERADE 2>/dev/null; do
      iptables -t nat -D POSTROUTING -o "$UPLINK_INTERFACE" -d "$PARTNER_DESTINATION" \
        -s "$WG_PEER_ALLOWED_IP" -j MASQUERADE
    done
  fi

  ip link del dev "$WG_INTERFACE" 2>/dev/null || true
  echo "Demo 4 egress removed."
}

status() {
  echo "=== DEMO 4 FIXED-IP EGRESS ==="
  echo "Authorized target   : $PARTNER_DESTINATION"
  echo "WireGuard interface : $WG_INTERFACE"
  echo "Public uplink       : $UPLINK_INTERFACE"
  [[ -n "$EGRESS_PUBLIC_IP" ]] && echo "Expected public IP  : $EGRESS_PUBLIC_IP"
  echo
  ip -brief address show dev "$WG_INTERFACE" 2>/dev/null || true
  wg show "$WG_INTERFACE" 2>/dev/null || true
  echo
  echo "=== FORWARD COUNTERS ==="
  iptables -nvL "$CHAIN" 2>/dev/null || true
  echo
  echo "=== EGRESS NAT COUNTER ==="
  iptables -t nat -nvL POSTROUTING 2>/dev/null | grep -E "${WG_PEER_ALLOWED_IP%%/*}|${PARTNER_DESTINATION%%/*}" || true
}

case "${1:-status}" in
  setup) setup ;;
  remove|down|cleanup) remove ;;
  status) status ;;
  *) echo "Usage: $0 {setup|status|remove}" >&2; exit 2 ;;
esac
