#!/bin/bash

# TaraSec firewall configuration
#Uncomment and save local configuration whith:
# sudo nano /etc/tarasecfw.conf

# Central database server
#DBSERVER="100.68.181.35"
#IS_GATEWAY=0
#LAN_INTERFACE="wg0"
#WAN_INTERFACE="wt0"

# When deploying to production, consider moving SSH from the default port (22).
#SSH_PORT="22"

#For now, web is required for inter-server communication. You can disable in index.php
#ALLOW_WEB=1

# Additional allowed TCP ports
#TCP_PORTS=""

# UDP ports for elaborated threat data exchange and threat logging
#UDP_PORTS="5552,514"

# Allow ICMP echo requests (ping)
#ALLOW_PING=0

# Logging
#MAX_LOGS_PER_MIN=10
#MAX_BURSTS=20

# TaraSec node name (defaults to system hostname if empty)
#NODE_NAME="node_x"

set -e

CONF="${1:-/etc/tarasecfw.conf}"

if [ ! -f "$CONF" ]; then
    echo "Missing config: $CONF"
    exit 1
fi

source "$CONF"

SSH_PORT="${SSH_PORT:-22}"
ALLOW_WEB="${ALLOW_WEB:-1}"
TCP_PORTS="${TCP_PORTS:-}"
UDP_PORTS="${UDP_PORTS:-5552,514}"
MAX_LOGS_PER_MIN="${MAX_LOGS_PER_MIN:-10}"
MAX_BURSTS="${MAX_BURSTS:-20}"

if [ -z "$NODE_NAME" ]; then
    NODE="$(hostname)"
else
    NODE="$NODE_NAME"
fi

iptables -F
iptables -X

iptables -P INPUT DROP
iptables -P FORWARD ACCEPT
iptables -P OUTPUT ACCEPT

IS_GATEWAY="${IS_GATEWAY:-0}"
LAN_INTERFACE="${LAN_INTERFACE:-wg0}"
WAN_INTERFACE="${WAN_INTERFACE:-wt0}"

if [ "$IS_GATEWAY" = "1" ]; then
	#Flushing only filter, not NAT, so check if exists (with -C) before adding (-A) rule
    iptables -t nat -C POSTROUTING -o "$WAN_INTERFACE" -j MASQUERADE \
        2>/dev/null ||
    iptables -t nat -A POSTROUTING -o "$WAN_INTERFACE" -j MASQUERADE

    iptables -A FORWARD \
        -i "$LAN_INTERFACE" \
        -o "$WAN_INTERFACE" \
        -j ACCEPT

    iptables -A FORWARD \
        -i "$WAN_INTERFACE" \
        -o "$LAN_INTERFACE" \
        -m conntrack --ctstate ESTABLISHED,RELATED \
        -j ACCEPT
fi

# -------------------------------------------------
# MACHINE-SPECIFIC RULES
# - including local gateway/forwarding/NAT rules here
# -------------------------------------------------

iptables -A INPUT -i lo -j ACCEPT

iptables -A INPUT -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT

iptables -A INPUT -p tcp --dport "$SSH_PORT" -j ACCEPT

if [ "$ALLOW_WEB" = "1" ]; then
    iptables -A INPUT -p tcp --dport 80 -j ACCEPT
    iptables -A INPUT -p tcp --dport 443 -j ACCEPT
fi

IFS=',' read -ra PORTS <<< "$TCP_PORTS"
for PORT in "${PORTS[@]}"; do
    [ -n "$PORT" ] && iptables -A INPUT -p tcp --dport "$PORT" -j ACCEPT
done

IFS=',' read -ra PORTS <<< "$UDP_PORTS"
for PORT in "${PORTS[@]}"; do
    [ -n "$PORT" ] && iptables -A INPUT -p udp --dport "$PORT" -j ACCEPT
done

if [ "${ALLOW_PING:-1}" = "1" ]; then
    iptables -A INPUT  -p icmp  --icmp-type echo-request -j ACCEPT
    ip6tables -A INPUT -p ipv6-icmp --icmpv6-type echo-request -j ACCEPT
fi

iptables -A INPUT \
    -m limit --limit "${MAX_LOGS_PER_MIN}/min" \
    --limit-burst "$MAX_BURSTS" \
    -j LOG \
    --log-prefix "TARASEC_${NODE}: " \
    --log-level 4




iptables -A INPUT -j DROP

#Make the iptables rules persistent
if ! dpkg-query -W -f='${Status}' netfilter-persistent 2>/dev/null | grep -q "install ok installed"; then
    apt-get update
    apt-get install -y netfilter-persistent
fi
netfilter-persistent save