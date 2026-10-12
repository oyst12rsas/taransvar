#!/usr/bin/env bash
# Sourced by firewall.sh for gateways. Own only the IPv6 INPUT policy/chain;
# preserve NetBird, source/timed SSH gates, NAT and forwarding rules.
tarasec_ipv6_prepare() {
    local interface="${WAN_INTERFACE:-wt0}" port source entry
    local chain=TARASEC_IPV6_INPUT
    local -a tcp_ports=() udp_ports=() ssh_sources=() transport_ports=()
    [[ "$interface" =~ ^[a-zA-Z0-9_.:-]+$ ]] || { echo 'Invalid IPv6 overlay interface' >&2; return 1; }
    command -v ip6tables-restore >/dev/null || { echo 'ip6tables-restore is required' >&2; return 1; }
    command -v python3 >/dev/null || { echo 'python3 is required to validate IPv6 sources' >&2; return 1; }
    IFS=',' read -ra tcp_ports <<< "${TCP_PORTS:-}"
    IFS=',' read -ra udp_ports <<< "${UDP_PORTS:-}"
    IFS=',' read -ra ssh_sources <<< "${SSH_ALLOWED_IPV6_SOURCES:-}"
    IFS=',' read -ra transport_ports <<< "${IPV6_TRANSPORT_UDP_PORTS:-}"
    # Kernel WireGuard/NetBird listen ports can change on restart. Discover the
    # actual configured WireGuard sockets, never allow all UDP or all listeners.
    if command -v wg >/dev/null; then
        local wg_ports
        wg_ports=$(wg show all listen-port) || { echo 'Cannot inspect WireGuard listen ports' >&2; return 1; }
        while read -r entry port; do
            [[ -z "${entry:-}" || "${port:-0}" == 0 ]] && continue
            transport_ports+=("$port")
        done <<< "$wg_ports"
    fi
    for port in "${tcp_ports[@]}" "${udp_ports[@]}" "${transport_ports[@]}"; do
        port="${port//[[:space:]]/}"
        [[ -z "$port" ]] && continue
        valid_port "$port" || { echo "Invalid IPv6 service/transport port: $port" >&2; return 1; }
    done
    for source in "${ssh_sources[@]}"; do
        source="${source//[[:space:]]/}"
        [[ -z "$source" ]] && continue
        python3 - "$source" <<'PY' || return 1
import ipaddress, sys
try:
    if ipaddress.ip_network(sys.argv[1], strict=False).version != 6:
        raise ValueError('IPv6 source required')
except ValueError as error:
    print('Invalid SSH_ALLOWED_IPV6_SOURCES: '+str(error), file=sys.stderr)
    sys.exit(1)
PY
    done
    local current
    current=$(ip6tables -S INPUT) || return 1
    TARASEC_IPV6_RULE_FILE=$(mktemp)
    {
        echo '*filter'
        echo ':INPUT DROP [0:0]'
        echo ":$chain - [0:0]"
        echo "-F $chain"
        echo "-A $chain -i lo -j ACCEPT"
        echo "-A $chain -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT"
        # ICMPv6 errors and link configuration are required even when ping is
        # disabled. Keep unsolicited echo requests controlled by ALLOW_PING.
        for port in 1 2 3 4 129 130 131 132 133 134 135 136 143; do
            echo "-A $chain -p ipv6-icmp --icmpv6-type $port -j ACCEPT"
        done
        if [[ "${ALLOW_PING:-1}" == 1 ]]; then
            echo "-A $chain -p ipv6-icmp --icmpv6-type 128 -j ACCEPT"
        fi
        echo "-A $chain -s fe80::/10 -p udp --sport 547 --dport 546 -j ACCEPT"
        if [[ "${ALLOW_WEB:-1}" == 1 ]]; then
            echo "-A $chain -i $interface -p tcp --dport 80 -j ACCEPT"
            echo "-A $chain -i $interface -p tcp --dport 443 -j ACCEPT"
        fi
        for port in "${tcp_ports[@]}"; do
            port="${port//[[:space:]]/}"; [[ -z "$port" ]] && continue
            # The general service list must not bypass the separate SSH policy.
            [[ "$port" == "$SSH_PORT" ]] && continue
            echo "-A $chain -i $interface -p tcp --dport $port -j ACCEPT"
        done
        for port in "${udp_ports[@]}"; do
            port="${port//[[:space:]]/}"; [[ -z "$port" ]] && continue
            echo "-A $chain -i $interface -p udp --dport $port -j ACCEPT"
        done
        for port in "${transport_ports[@]}"; do
            port="${port//[[:space:]]/}"; [[ -z "$port" ]] && continue
            echo "-A $chain -p udp --dport $port -j ACCEPT"
        done
        # IPv4 sources never imply IPv6 SSH permission. Existing INPUT SSH
        # source/timed gates still run before this chain and may further deny.
        if [[ "${ALLOW_SSH:-1}" == 1 ]]; then
            for source in "${ssh_sources[@]}"; do
                source="${source//[[:space:]]/}"; [[ -z "$source" ]] && continue
                echo "-A $chain -s $source -p tcp --dport $SSH_PORT -j ACCEPT"
            done
        fi
        echo "-A $chain -m limit --limit ${MAX_LOGS_PER_MIN:-10}/min --limit-burst ${MAX_BURSTS:-20} -j LOG --log-prefix TARASEC_IPV6_DENIED:"
        echo "-A $chain -j DROP"
        # Remove only the known blanket overlay acceptance and our old hook.
        # Other existing INPUT rules (including SSH gates) retain their order.
        while IFS= read -r entry; do
            if [[ "$entry" == "-A INPUT -i $interface -j ACCEPT" || "$entry" == "-A INPUT -j $chain" ]]; then
                echo "${entry/-A INPUT/-D INPUT}"
            fi
        done <<< "$current"
        echo "-A INPUT -j $chain"
        echo COMMIT
    } > "$TARASEC_IPV6_RULE_FILE"
    ip6tables-restore --test --noflush < "$TARASEC_IPV6_RULE_FILE" || {
        rm -f "$TARASEC_IPV6_RULE_FILE"; return 1;
    }
}

tarasec_ipv6_apply() {
    # Filter-table transaction: no live chain flush or temporary open policy.
    ip6tables-restore --noflush < "$TARASEC_IPV6_RULE_FILE"
    echo 'IPv6 INPUT restricted; forwarding and existing SSH gates preserved.'
}
