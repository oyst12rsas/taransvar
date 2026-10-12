#!/bin/bash

# TaraSec firewall configuration
#Uncomment and save local configuration whith:
# sudo nano /etc/tarasecfw.conf

# Central database server
#DBSERVER="100.68.181.35"
#IS_GATEWAY=0
#LAN_INTERFACE="wg0"
#WAN_INTERFACE="wt0"

# Restrict hotspot clients entering the NetBird/simulated-WAN network.
# Ordinary hotspot Internet forwarding remains allowed.
#NETBIRD_CIDR="100.68.0.0/16"
# Allow this node to accept explicitly marked, short-lived demo sessions.
# This does not make ordinary evidence or the node itself non-production.
#DEMO_NODE=0

# Shared by firewall enforcement, taralink ephemeral tagging destinations,
# and the app's demo configuration endpoint.
#DEMO_NODES="100.68.22.33"
#DEMO_NODE_NAMES="Tomato"
#HOTSPOT_ALLOWED_NETBIRD_TCP_PORTS="80,443"

# When deploying to production, consider moving SSH from the default port (22).
#SSH_PORT="22"
#SSH_ALLOWED_SOURCES="100.68.10.7"
#SSH_ALLOWED_IPV6_SOURCES="" # explicit IPv6 hosts/CIDRs; empty denies new IPv6 SSH
#IPV6_TRANSPORT_UDP_PORTS="" # extra userspace VPN UDP ports; kernel WireGuard ports are discovered
#SSH_HONEYPOT="on"
#SSH_HONEYPOT_PORTS="22"
#SSH_HONEYPOT_AUTH_MODE="accept-all" # accept-all, reject-all, password
#SSH_HONEYPOT_PASSWORD_HASH=""       # SHA-256; only for password mode
#SSH_HONEYPOT_DEMO_PORT="22"         # optional DB-authorized demo service
#SSH_HONEYPOT_DEMO_DB_URL="https://db.example/script/appDemoSshSession.php"
#SSH_HONEYPOT_DEMO_NODE_TOKEN=""     # random 32+ character secret; store its SHA-256 in demoSshNodeB

#For now, web is required for inter-server communication. You can disable in index.php
#ALLOW_WEB=1

# Additional allowed TCP ports
#TCP_PORTS=""

# UDP ports for elaborated threat data exchange and threat logging
#UDP_PORTS="5551,5552,514"

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
UDP_PORTS="${UDP_PORTS:-5551,5552,514}"
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
