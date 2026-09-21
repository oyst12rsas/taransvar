#!/bin/bash

# TaraSec firewall configuration.
# All firewall and SSH policy is kept in /etc/tarasecfw.conf.

set -e

CONF="${1:-/etc/tarasecfw.conf}"

if [ ! -f "$CONF" ]; then
    echo "Missing config: $CONF"
    exit 1
fi

# shellcheck disable=SC1090
source "$CONF"

SSH_PORT="${SSH_PORT:-22}"
SSH_HONEYPOT="${SSH_HONEYPOT:-off}"
SSH_HONEYPOT_PORT="${SSH_HONEYPOT_PORT:-22}"
SSH_HONEYPOT_PORTS="${SSH_HONEYPOT_PORTS:-$SSH_HONEYPOT_PORT}"
SSH_HONEYPOT_DEMO_PORT="${SSH_HONEYPOT_DEMO_PORT:-0}"
SSH_ALLOWED_SOURCES="${SSH_ALLOWED_SOURCES:-}"
SSH_RECOVERY_PROTECT="${SSH_RECOVERY_PROTECT:-on}"
SSH_RECOVERY_SOURCES="${SSH_RECOVERY_SOURCES:-}"
ALLOW_WEB="${ALLOW_WEB:-1}"
TCP_PORTS="${TCP_PORTS:-}"
UDP_PORTS="${UDP_PORTS:-5551,5552,514}"
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

parse_honeypot_ports() {
    local spec="${SSH_HONEYPOT_PORTS// /,}" item first last port
    local -A seen_specs=()
    SSH_HONEYPOT_PORT_SPECS=()
    IFS=',' read -ra items <<< "$spec"
    for item in "${items[@]}"; do
        [ -z "$item" ] && continue
        if [[ "$item" =~ ^([0-9]+)-([0-9]+)$ ]]; then
            first=$((10#${BASH_REMATCH[1]})); last=$((10#${BASH_REMATCH[2]}))
            [ "$first" -le "$last" ] || { echo "Descending honeypot range: $item" >&2; exit 1; }
            valid_port "$first" && valid_port "$last" || { echo "Invalid honeypot range: $item" >&2; exit 1; }
            ! (( SSH_PORT >= first && SSH_PORT <= last )) || { echo "Honeypot range collides with SSH_PORT=$SSH_PORT: $item" >&2; exit 1; }
            item="$first-$last"
        elif [[ "$item" =~ ^[0-9]+$ ]]; then
            port=$((10#$item))
            valid_port "$port" || { echo "Invalid honeypot port: $item" >&2; exit 1; }
            [ "$port" != "$SSH_PORT" ] || { echo "Honeypot port collides with SSH_PORT=$SSH_PORT" >&2; exit 1; }
            item="$port"
        else
            echo "Invalid SSH_HONEYPOT_PORTS entry: $item" >&2; exit 1
        fi
        if [ -z "${seen_specs[$item]:-}" ]; then
            seen_specs[$item]=1
            SSH_HONEYPOT_PORT_SPECS+=("$item")
        fi
        [ "${#SSH_HONEYPOT_PORT_SPECS[@]}" -le 64 ] || { echo "At most 64 honeypot port entries are allowed" >&2; exit 1; }
    done
    [ "${#SSH_HONEYPOT_PORT_SPECS[@]}" -gt 0 ] || { echo "No honeypot ports configured" >&2; exit 1; }
}
parse_honeypot_ports

iptables -F
iptables -X
iptables -P INPUT DROP
iptables -P FORWARD ACCEPT
iptables -P OUTPUT ACCEPT

IS_GATEWAY="${IS_GATEWAY:-0}"
LAN_INTERFACE="${LAN_INTERFACE:-wg0}"
WAN_INTERFACE="${WAN_INTERFACE:-wt0}"
NETBIRD_CIDR="${NETBIRD_CIDR:-100.68.0.0/16}"
HOTSPOT_ALLOWED_NETBIRD_NODES="${HOTSPOT_ALLOWED_NETBIRD_NODES:-}"
HOTSPOT_ALLOWED_NETBIRD_TCP_PORTS="${HOTSPOT_ALLOWED_NETBIRD_TCP_PORTS:-80,443}"
DEMO_NODE="${DEMO_NODE:-0}"
DEMO_NODES="${DEMO_NODES:-$HOTSPOT_ALLOWED_NETBIRD_NODES}"

if [ "$IS_GATEWAY" = "1" ]; then
    # A FORWARD policy and forwarding rules are ineffective while the kernel
    # forwarding switch is disabled. Enable it immediately for this gateway.
    sysctl -w net.ipv4.ip_forward=1 >/dev/null
    iptables -t nat -C POSTROUTING -o "$WAN_INTERFACE" -j MASQUERADE 2>/dev/null ||
        iptables -t nat -A POSTROUTING -o "$WAN_INTERFACE" -j MASQUERADE
    iptables -A FORWARD -i "$LAN_INTERFACE" -o "$WAN_INTERFACE" -j ACCEPT
    iptables -A FORWARD -i "$WAN_INTERFACE" -o "$LAN_INTERFACE" \
        -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
fi

iptables -A INPUT -i lo -j ACCEPT

# If the TaraSec hotspot is installed, its local captive services must remain
# reachable even when the TaraSec host firewall has a default DROP policy.
# Restrict these openings to the configured hotspot interface only.
HOTSPOT_IF=""
HOTSPOT_IP=""
if [ -r /etc/tarasec/hotspot-dns.conf ]; then
    # shellcheck disable=SC1091
    source /etc/tarasec/hotspot-dns.conf
fi
if [ -n "${HOTSPOT_IF:-}" ] && ip link show "$HOTSPOT_IF" >/dev/null 2>&1; then
    iptables -A INPUT -i "$HOTSPOT_IF" -p udp --dport 53 -j ACCEPT
    iptables -A INPUT -i "$HOTSPOT_IF" -p tcp --dport 53 -j ACCEPT
    iptables -A INPUT -i "$HOTSPOT_IF" -p udp --dport 67 -j ACCEPT
    iptables -A INPUT -i "$HOTSPOT_IF" -p tcp --dport 2050 -j ACCEPT
    iptables -A INPUT -i "$HOTSPOT_IF" -p tcp --dport 8080 -j ACCEPT
fi

# Hotspot clients keep ordinary Internet forwarding, but may only enter the
# NetBird/simulated-WAN network through explicitly approved demo nodes/ports.
# WAN_INTERFACE is retained as the historical name for that overlay interface.
if [ -n "${HOTSPOT_IF:-}" ] &&
   ip link show "$HOTSPOT_IF" >/dev/null 2>&1 &&
   ip link show "$WAN_INTERFACE" >/dev/null 2>&1; then
    IFS=',' read -ra DEMO_NODE_LIST <<< "$DEMO_NODES"
    IFS=',' read -ra DEMO_TCP_PORTS <<< "$HOTSPOT_ALLOWED_NETBIRD_TCP_PORTS"

    # The existing DBSERVER setting is the app's central HTTP API/control
    # service.  Permit those web requests without advertising the server as a
    # demo receiver and without opening the database port itself.
    if [ -n "${DBSERVER:-}" ]; then
        for PORT in "${DEMO_TCP_PORTS[@]}"; do
            PORT="${PORT//[[:space:]]/}"
            [ -z "$PORT" ] && continue
            if ! valid_port "$PORT"; then
                echo "Invalid HOTSPOT_ALLOWED_NETBIRD_TCP_PORTS entry: $PORT" >&2
                exit 1
            fi
            iptables -A FORWARD -i "$HOTSPOT_IF" -o "$WAN_INTERFACE" \
                -d "$DBSERVER" -p tcp --dport "$PORT" -j ACCEPT
        done
    fi

    for NODE_IP in "${DEMO_NODE_LIST[@]}"; do
        NODE_IP="${NODE_IP//[[:space:]]/}"
        [ -z "$NODE_IP" ] && continue
        for PORT in "${DEMO_TCP_PORTS[@]}"; do
            PORT="${PORT//[[:space:]]/}"
            [ -z "$PORT" ] && continue
            if ! valid_port "$PORT"; then
                echo "Invalid HOTSPOT_ALLOWED_NETBIRD_TCP_PORTS entry: $PORT" >&2
                exit 1
            fi
            iptables -A FORWARD -i "$HOTSPOT_IF" -o "$WAN_INTERFACE" \
                -d "$NODE_IP" -p tcp --dport "$PORT" -j ACCEPT
        done
    done

    iptables -A FORWARD -i "$HOTSPOT_IF" -o "$WAN_INTERFACE" \
        -d "$NETBIRD_CIDR" \
        -m limit --limit "${MAX_LOGS_PER_MIN}/min" --limit-burst "$MAX_BURSTS" \
        -j LOG --log-prefix "TARASEC_HS_NB_DENIED: " --log-level 5
    iptables -A FORWARD -i "$HOTSPOT_IF" -o "$WAN_INTERFACE" \
        -d "$NETBIRD_CIDR" -j DROP
fi

if is_on "$SSH_RECOVERY_PROTECT" && [ -n "$SSH_RECOVERY_SOURCES" ]; then
    add_source_rules "$SSH_RECOVERY_SOURCES" "$SSH_PORT"
fi

if [ "${ALLOW_SSH:-1}" = "1" ]; then
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
    # Redirect only connections addressed to this machine. Forwarded/NAT
    # traffic is excluded by --dst-type LOCAL.
    iptables -t nat -N TARASEC_SSH_HONEYPOT 2>/dev/null || iptables -t nat -F TARASEC_SSH_HONEYPOT
    while iptables -t nat -D PREROUTING -p tcp -m addrtype --dst-type LOCAL -j TARASEC_SSH_HONEYPOT 2>/dev/null; do :; done
    iptables -t nat -A PREROUTING -p tcp -m addrtype --dst-type LOCAL -j TARASEC_SSH_HONEYPOT
    iptables -t nat -A TARASEC_SSH_HONEYPOT -p tcp --dport "$SSH_HONEYPOT_PORT" -j RETURN
    if [ "$SSH_HONEYPOT_DEMO_PORT" != "0" ] && [ "$SSH_HONEYPOT_DEMO_PORT" != "$SSH_HONEYPOT_PORT" ]; then
        iptables -t nat -A TARASEC_SSH_HONEYPOT -p tcp --dport "$SSH_HONEYPOT_DEMO_PORT" -j RETURN
    fi
    for PORT_SPEC in "${SSH_HONEYPOT_PORT_SPECS[@]}"; do
        [ "$PORT_SPEC" = "$SSH_HONEYPOT_PORT" ] && continue
        [ "$SSH_HONEYPOT_DEMO_PORT" != "0" ] && [ "$PORT_SPEC" = "$SSH_HONEYPOT_DEMO_PORT" ] && continue
        iptables -t nat -A TARASEC_SSH_HONEYPOT -p tcp --dport "${PORT_SPEC/-/:}" \
            -j REDIRECT --to-ports "$SSH_HONEYPOT_PORT"
    done
    iptables -A INPUT -p tcp --dport "$SSH_HONEYPOT_PORT" -j ACCEPT
    if [ "$SSH_HONEYPOT_DEMO_PORT" != "0" ] && [ "$SSH_HONEYPOT_DEMO_PORT" != "$SSH_HONEYPOT_PORT" ]; then
        iptables -A INPUT -p tcp --dport "$SSH_HONEYPOT_DEMO_PORT" -j ACCEPT
    fi
else
    while iptables -t nat -D PREROUTING -p tcp -m addrtype --dst-type LOCAL -j TARASEC_SSH_HONEYPOT 2>/dev/null; do :; done
    iptables -t nat -F TARASEC_SSH_HONEYPOT 2>/dev/null || true
    iptables -t nat -X TARASEC_SSH_HONEYPOT 2>/dev/null || true
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
