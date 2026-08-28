#!/bin/bash

# TaraSec firewall configuration.
# Machine/network defaults live in /etc/tarasecfw.conf.
# Owner-controlled SSH policy lives in /etc/tarasec.conf. The existing
# Gatekeeper ALLOW_SSH open/closed state remains operational and is not
# overridden by the owner policy file.

set -e

CONF="${1:-/etc/tarasecfw.conf}"
OWNER_CONF="${TARASEC_CONF:-/etc/tarasec.conf}"

if [ ! -f "$CONF" ]; then
    echo "Missing config: $CONF"
    exit 1
fi

# shellcheck disable=SC1090
source "$CONF"
FIREWALL_ALLOW_SSH="${ALLOW_SSH:-1}"

# Owner policy may define SSH_PORT, source restrictions, honeypot and recovery
# policy, but actual ordinary open/closed state still comes from Gatekeeper.
if [ -r "$OWNER_CONF" ]; then
    # shellcheck disable=SC1090
    source "$OWNER_CONF"
fi
ALLOW_SSH="$FIREWALL_ALLOW_SSH"

SSH_PORT="${SSH_PORT:-22}"
SSH_HONEYPOT="${SSH_HONEYPOT:-off}"
SSH_HONEYPOT_PORT="${SSH_HONEYPOT_PORT:-22}"
SSH_ALLOWED_SOURCES="${SSH_ALLOWED_SOURCES:-}"
SSH_RECOVERY_PROTECT="${SSH_RECOVERY_PROTECT:-on}"
SSH_RECOVERY_SOURCES="${SSH_RECOVERY_SOURCES:-}"
ALLOW_WEB="${ALLOW_WEB:-1}"
TCP_PORTS="${TCP_PORTS:-}"
UDP_PORTS="${UDP_PORTS:-5552,514}"
MAX_LOGS_PER_MIN="${MAX_LOGS_PER_MIN:-10}"
MAX_BURSTS="${MAX_BURSTS:-20}"

if [ -z "$NODE_NAME" ]; then NODE="$(hostname)"; else NODE="$NODE_NAME"; fi

is_on() {
    case "${1,,}" in 1|yes|true|on) return 0 ;; *) return 1 ;; esac
}

valid_port() {
    [[ "$1" =~ ^[0-9]+$ ]] && [ "$1" -ge 1 ] && [ "$1" -le 65535 ]
}

add_source_rules() {
    local sources="$1"
    local port="$2"
    local src
    [ -z "$sources" ] && return 0
    IFS=',' read -ra LIST <<< "$sources"
    for src in "${LIST[@]}"; do
        src="${src//[[:space:]]/}"
        [ -z "$src" ] && continue
        iptables -A INPUT -p tcp -s "$src" --dport "$port" -j ACCEPT
    done
}

if ! valid_port "$SSH_PORT"; then echo "Invalid SSH_PORT=$SSH_PORT" >&2; exit 1; fi
if ! valid_port "$SSH_HONEYPOT_PORT"; then echo "Invalid SSH_HONEYPOT_PORT=$SSH_HONEYPOT_PORT" >&2; exit 1; fi
if is_on "$SSH_HONEYPOT" && [ "$SSH_PORT" = "$SSH_HONEYPOT_PORT" ]; then
    echo "SSH_PORT and SSH_HONEYPOT_PORT must differ when honeypot is enabled." >&2
    exit 1
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
    iptables -t nat -C POSTROUTING -o "$WAN_INTERFACE" -j MASQUERADE 2>/dev/null ||
        iptables -t nat -A POSTROUTING -o "$WAN_INTERFACE" -j MASQUERADE
    iptables -A FORWARD -i "$LAN_INTERFACE" -o "$WAN_INTERFACE" -j ACCEPT
    iptables -A FORWARD -i "$WAN_INTERFACE" -o "$LAN_INTERFACE" \
        -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
fi

iptables -A INPUT -i lo -j ACCEPT

# Recovery sources are evaluated first. On VPSs this prevents Gatekeeper or a
# remote close command from cutting the only management path. The owner can
# explicitly disable the protection with SSH_RECOVERY_PROTECT=off.
if is_on "$SSH_RECOVERY_PROTECT" && [ -n "$SSH_RECOVERY_SOURCES" ]; then
    add_source_rules "$SSH_RECOVERY_SOURCES" "$SSH_PORT"
fi

# Ordinary SSH policy. Source restrictions apply when open. When closed, only
# the protected recovery sources above can still reach the real SSH port.
if [ "$ALLOW_SSH" = "1" ]; then
    if [ -n "$SSH_ALLOWED_SOURCES" ]; then
        add_source_rules "$SSH_ALLOWED_SOURCES" "$SSH_PORT"
        iptables -A INPUT -p tcp --dport "$SSH_PORT" \
            -m limit --limit "${MAX_LOGS_PER_MIN}/min" --limit-burst "$MAX_BURSTS" \
            -j LOG --log-prefix "TARASEC_SSH_DENIED_${NODE}: " --log-level 4
        iptables -A INPUT -p tcp --dport "$SSH_PORT" -j REJECT --reject-with tcp-reset
    else
        iptables -A INPUT -p tcp --dport "$SSH_PORT" -j ACCEPT
    fi
else
    iptables -A INPUT -p tcp --dport "$SSH_PORT" \
        -m limit --limit "${MAX_LOGS_PER_MIN}/min" --limit-burst "$MAX_BURSTS" \
        -j LOG --log-prefix "TARASEC_SSH_DISABLED_${NODE}: " --log-level 4
    iptables -A INPUT -p tcp --dport "$SSH_PORT" -j REJECT --reject-with tcp-reset
fi

if is_on "$SSH_HONEYPOT"; then
    iptables -A INPUT -p tcp --dport "$SSH_HONEYPOT_PORT" -j ACCEPT
fi

iptables -A INPUT -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT

if [ "$ALLOW_WEB" = "1" ]; then
    iptables -A INPUT -p tcp --dport 80 -j ACCEPT
    iptables -A INPUT -p tcp --dport 443 -j ACCEPT
fi

IFS=',' read -ra PORTS <<< "$TCP_PORTS"
for PORT in "${PORTS[@]}"; do [ -n "$PORT" ] && iptables -A INPUT -p tcp --dport "$PORT" -j ACCEPT; done
IFS=',' read -ra PORTS <<< "$UDP_PORTS"
for PORT in "${PORTS[@]}"; do [ -n "$PORT" ] && iptables -A INPUT -p udp --dport "$PORT" -j ACCEPT; done

if [ "${ALLOW_PING:-1}" = "1" ]; then
    iptables -A INPUT -p icmp --icmp-type echo-request -j ACCEPT
    ip6tables -A INPUT -p ipv6-icmp --icmpv6-type echo-request -j ACCEPT
fi

iptables -A INPUT \
    -m limit --limit "${MAX_LOGS_PER_MIN}/min" \
    --limit-burst "$MAX_BURSTS" \
    -j LOG --log-prefix "TARASEC_${NODE}: " --log-level 5
iptables -A INPUT -j DROP

if ! dpkg-query -W -f='${Status}' netfilter-persistent 2>/dev/null | grep -q "install ok installed"; then
    apt-get update
    apt-get install -y netfilter-persistent
fi
netfilter-persistent save
