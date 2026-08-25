#!/usr/bin/env bash
set -euo pipefail

# TaraSec Hotspot Installer v2
#
# Goal: a normal user should be able to run one command and get a usable TaraSec
# hotspot without knowing Linux interface names, subnets, firewall rules or VPN
# details.  The installer only asks for input when automatic detection is unsafe.
#
# Optional environment overrides (normally not needed):
#   TARASEC_HOTSPOT_IF=wlan1
#   TARASEC_WAN_IF=eth0
#   TARASEC_SSID=TaraSec
#   TARASEC_WIFI_PASSWORD='optional-wpa2-password'   # blank => open AP
#   TARASEC_HOTSPOT_CIDR=192.168.50.0/24
#   TARASEC_COUNTRY=NO
#   TARASEC_CHANNEL=6
#   TARASEC_INSTALL_OPENNDS=auto|yes|no
#   TARASEC_NETBIRD_SETUP_KEY=...                   # only if NetBird is not joined
#   TARASEC_NETBIRD_MGMT_URL=https://netbird.taransvar.no
#   TARASEC_NONINTERACTIVE=1                        # fail instead of asking

VERSION="2.0"
SSID="${TARASEC_SSID:-TaraSec}"
WIFI_PASSWORD="${TARASEC_WIFI_PASSWORD:-}"
COUNTRY="${TARASEC_COUNTRY:-}"
CHANNEL="${TARASEC_CHANNEL:-6}"
HOTSPOT_IF="${TARASEC_HOTSPOT_IF:-}"
WAN_IF="${TARASEC_WAN_IF:-}"
HOTSPOT_CIDR="${TARASEC_HOTSPOT_CIDR:-}"
INSTALL_OPENNDS="${TARASEC_INSTALL_OPENNDS:-auto}"
NONINTERACTIVE="${TARASEC_NONINTERACTIVE:-0}"
NETBIRD_SETUP_KEY="${TARASEC_NETBIRD_SETUP_KEY:-}"
NETBIRD_MGMT_URL="${TARASEC_NETBIRD_MGMT_URL:-https://netbird.taransvar.no}"

HOTSPOT_IP=""
DHCP_START=""
DHCP_END=""
BACKUP_DIR="/root/tarasec-hotspot-backup-$(date +%Y%m%d-%H%M%S)"
STATE_DIR="/var/lib/tarasec-hotspot"
CONF_DIR="/etc/tarasec"

log()  { printf '[TaraSec] %s\n' "$*"; }
warn() { printf '[TaraSec WARNING] %s\n' "$*" >&2; }
die()  { printf '[TaraSec ERROR] %s\n' "$*" >&2; exit 1; }

run_as_root() {
    if [[ ${EUID:-$(id -u)} -ne 0 ]]; then
        command -v sudo >/dev/null 2>&1 || die "Run this installer as root (sudo is not installed)."
        exec sudo -E bash "$0" "$@"
    fi
}

have() { command -v "$1" >/dev/null 2>&1; }

ask_choice() {
    local prompt="$1"; shift
    local choices=("$@")
    [[ ${#choices[@]} -gt 0 ]] || return 1
    if [[ "$NONINTERACTIVE" == "1" ]]; then
        die "$prompt Automatic detection was ambiguous. Set the appropriate TARASEC_* environment override."
    fi
    printf '%s\n' "$prompt" >&2
    local i=1 c
    for c in "${choices[@]}"; do printf '  %d) %s\n' "$i" "$c" >&2; ((i++)); done
    local answer
    while true; do
        read -r -p "Select [1-${#choices[@]}]: " answer
        [[ "$answer" =~ ^[0-9]+$ ]] || continue
        (( answer >= 1 && answer <= ${#choices[@]} )) || continue
        printf '%s' "${choices[$((answer-1))]}"
        return 0
    done
}

install_base_packages() {
    export DEBIAN_FRONTEND=noninteractive
    have apt-get || die "This installer currently supports Debian/Ubuntu/Raspberry Pi OS (apt-get required)."
    log "Installing required packages (already installed packages are left alone)..."
    apt-get update -y
    apt-get install -y hostapd dnsmasq iw rfkill iproute2 iptables curl ca-certificates
    systemctl unmask hostapd 2>/dev/null || true
}

default_route_interface() {
    ip -4 route show default 2>/dev/null \
        | awk '$1=="default" {for(i=1;i<=NF;i++) if($i=="dev") {print $(i+1); exit}}'
}

wifi_interfaces() {
    iw dev 2>/dev/null | awk '$1=="Interface" {print $2}'
}

wifi_supports_ap() {
    local iface="$1" phy
    phy=$(iw dev "$iface" info 2>/dev/null | awk '/wiphy/ {print "phy"$2; exit}')
    [[ -n "$phy" ]] || return 1
    iw phy "$phy" info 2>/dev/null \
        | sed -n '/Supported interface modes:/,/^[[:space:]]*Band /p' \
        | grep -Eq '^[[:space:]]*\*[[:space:]]+AP$'
}

interface_has_default_route() {
    ip -4 route show default 2>/dev/null | grep -Eq "(^|[[:space:]])dev[[:space:]]+$1([[:space:]]|$)"
}

detect_interfaces() {
    log "Detecting Internet and hotspot interfaces..."

    if [[ -z "$WAN_IF" ]]; then
        WAN_IF=$(default_route_interface || true)
    fi
    [[ -n "$WAN_IF" ]] || die "No IPv4 default route was found. Connect this computer to the Internet and rerun."
    ip link show "$WAN_IF" >/dev/null 2>&1 || die "Detected WAN interface '$WAN_IF' does not exist."

    if [[ -z "$HOTSPOT_IF" ]]; then
        local candidates=() iface
        while read -r iface; do
            [[ -n "$iface" ]] || continue
            # Never steal the active Internet interface automatically.
            [[ "$iface" == "$WAN_IF" ]] && continue
            interface_has_default_route "$iface" && continue
            wifi_supports_ap "$iface" && candidates+=("$iface")
        done < <(wifi_interfaces)

        if [[ ${#candidates[@]} -eq 1 ]]; then
            HOTSPOT_IF="${candidates[0]}"
        elif [[ ${#candidates[@]} -gt 1 ]]; then
            HOTSPOT_IF=$(ask_choice "More than one unused AP-capable Wi-Fi interface was found." "${candidates[@]}")
        else
            # A machine with a single Wi-Fi radio may use Ethernet/USB for WAN. Check
            # all Wi-Fi radios once more, still refusing to steal the WAN interface.
            while read -r iface; do
                [[ -n "$iface" && "$iface" != "$WAN_IF" ]] || continue
                wifi_supports_ap "$iface" && candidates+=("$iface")
            done < <(wifi_interfaces)
            [[ ${#candidates[@]} -eq 1 ]] && HOTSPOT_IF="${candidates[0]}"
        fi
    fi

    [[ -n "$HOTSPOT_IF" ]] || die "No unused AP-capable Wi-Fi interface was found. Add a Wi-Fi adapter that supports AP mode, or set TARASEC_HOTSPOT_IF."
    [[ "$HOTSPOT_IF" != "$WAN_IF" ]] || die "Refusing to use '$HOTSPOT_IF' for both Internet access and the hotspot. Provide a separate WAN or Wi-Fi interface."
    wifi_supports_ap "$HOTSPOT_IF" || die "Wi-Fi interface '$HOTSPOT_IF' does not advertise AP mode support."

    log "Internet interface: $WAN_IF"
    log "Hotspot interface:  $HOTSPOT_IF"
}

unblock_wifi() {
    rfkill unblock wifi 2>/dev/null || true
    if rfkill list wifi 2>/dev/null | grep -q 'Hard blocked: yes'; then
        die "Wi-Fi is hardware-blocked. Enable the wireless radio/switch and rerun."
    fi
}

cidr_conflicts() {
    local cidr="$1" prefix="${cidr%0/24}"
    ip -4 route show table all 2>/dev/null | grep -qF "$cidr" && return 0
    ip -4 addr show 2>/dev/null | grep -qE "inet ${prefix//./\\.}[0-9]+/" && return 0
    return 1
}

choose_subnet() {
    if [[ -z "$HOTSPOT_CIDR" ]]; then
        local candidate
        for candidate in \
            192.168.50.0/24 192.168.60.0/24 192.168.70.0/24 192.168.80.0/24 192.168.90.0/24 \
            10.42.0.0/24 10.43.0.0/24 10.44.0.0/24 172.20.50.0/24 172.21.50.0/24; do
            if ! cidr_conflicts "$candidate"; then HOTSPOT_CIDR="$candidate"; break; fi
        done
    fi
    [[ "$HOTSPOT_CIDR" =~ ^([0-9]{1,3}\.){3}0/24$ ]] || die "Hotspot subnet must currently be an IPv4 /24 ending in .0 (got '$HOTSPOT_CIDR')."
    cidr_conflicts "$HOTSPOT_CIDR" && die "Requested hotspot subnet $HOTSPOT_CIDR conflicts with an existing route/address."

    local prefix="${HOTSPOT_CIDR%0/24}"
    HOTSPOT_IP="${prefix}1"
    DHCP_START="${prefix}50"
    DHCP_END="${prefix}200"
    log "Hotspot network: $HOTSPOT_CIDR (gateway $HOTSPOT_IP)"
}

detect_country() {
    [[ -n "$COUNTRY" ]] && return
    COUNTRY=$(iw reg get 2>/dev/null | awk '/^country [A-Z][A-Z]:/ {gsub(":", "", $2); print $2; exit}')
    [[ "$COUNTRY" =~ ^[A-Z]{2}$ ]] || COUNTRY=""
}

backup_existing() {
    mkdir -p "$BACKUP_DIR"
    for f in \
        /etc/hostapd/hostapd.conf \
        /etc/dnsmasq.d/tarasec-hotspot.conf \
        /etc/systemd/system/tarasec-hotspot-interface.service \
        /etc/systemd/system/tarasec-hotspot-firewall.service \
        /usr/local/sbin/tarasec-hotspot-firewall; do
        [[ -e "$f" ]] && cp -a "$f" "$BACKUP_DIR/" 2>/dev/null || true
    done
    mkdir -p "$STATE_DIR" "$CONF_DIR"
}

release_interface_from_managers() {
    # Do not disable NetworkManager globally. Only release the dedicated hotspot
    # interface if NetworkManager knows about it.
    if have nmcli; then
        nmcli device set "$HOTSPOT_IF" managed no 2>/dev/null || true
    fi
    systemctl stop "wpa_supplicant@${HOTSPOT_IF}.service" 2>/dev/null || true
    pkill -f "wpa_supplicant.*${HOTSPOT_IF}" 2>/dev/null || true
}

configure_forwarding() {
    cat >/etc/sysctl.d/90-tarasec-hotspot.conf <<'EOF'
net.ipv4.ip_forward=1
EOF
    sysctl --system >/dev/null
}

configure_interface_service() {
    cat >/etc/systemd/system/tarasec-hotspot-interface.service <<EOF
[Unit]
Description=TaraSec hotspot interface
After=network-online.target
Wants=network-online.target
Before=hostapd.service dnsmasq.service

[Service]
Type=oneshot
ExecStart=/sbin/ip link set dev $HOTSPOT_IF up
ExecStart=/sbin/ip addr flush dev $HOTSPOT_IF
ExecStart=/sbin/ip addr add $HOTSPOT_IP/24 dev $HOTSPOT_IF
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
EOF
}

configure_hostapd() {
    mkdir -p /etc/hostapd
    {
        echo "interface=$HOTSPOT_IF"
        echo "driver=nl80211"
        echo "ssid=$SSID"
        [[ -n "$COUNTRY" ]] && echo "country_code=$COUNTRY"
        echo "hw_mode=g"
        echo "channel=$CHANNEL"
        echo "ieee80211n=1"
        echo "wmm_enabled=1"
        echo "auth_algs=1"
        echo "ignore_broadcast_ssid=0"
        if [[ -n "$WIFI_PASSWORD" ]]; then
            [[ ${#WIFI_PASSWORD} -ge 8 && ${#WIFI_PASSWORD} -le 63 ]] || die "TARASEC_WIFI_PASSWORD must be 8-63 characters, or blank for an open captive-portal hotspot."
            echo "wpa=2"
            echo "wpa_passphrase=$WIFI_PASSWORD"
            echo "wpa_key_mgmt=WPA-PSK"
            echo "rsn_pairwise=CCMP"
        else
            echo "wpa=0"
        fi
    } >/etc/hostapd/hostapd.conf

    if [[ -f /etc/default/hostapd ]]; then
        if grep -q '^DAEMON_CONF=' /etc/default/hostapd; then
            sed -i 's|^DAEMON_CONF=.*|DAEMON_CONF="/etc/hostapd/hostapd.conf"|' /etc/default/hostapd
        else
            echo 'DAEMON_CONF="/etc/hostapd/hostapd.conf"' >>/etc/default/hostapd
        fi
    fi
}

configure_dnsmasq() {
    # Never replace the user's global dnsmasq.conf. TaraSec owns one drop-in only.
    mkdir -p /etc/dnsmasq.d
    cat >/etc/dnsmasq.d/tarasec-hotspot.conf <<EOF
interface=$HOTSPOT_IF
bind-dynamic
dhcp-range=$DHCP_START,$DHCP_END,255.255.255.0,12h
dhcp-option=3,$HOTSPOT_IP
dhcp-option=6,$HOTSPOT_IP
domain-needed
bogus-priv
EOF
}

configure_firewall() {
    cat >/usr/local/sbin/tarasec-hotspot-firewall <<EOF
#!/usr/bin/env bash
set -e
HOTSPOT_IF='$HOTSPOT_IF'
WAN_IF='$WAN_IF'
CIDR='$HOTSPOT_CIDR'

# Dedicated chains make reruns idempotent and avoid changing the host's global
# FORWARD policy or deleting rules belonging to Docker, NetBird, WireGuard, etc.
iptables -N TARASEC-HOTSPOT-FWD 2>/dev/null || true
iptables -F TARASEC-HOTSPOT-FWD
iptables -C FORWARD -j TARASEC-HOTSPOT-FWD 2>/dev/null || iptables -I FORWARD 1 -j TARASEC-HOTSPOT-FWD
iptables -A TARASEC-HOTSPOT-FWD -i "\$HOTSPOT_IF" -o "\$WAN_IF" -s "\$CIDR" -j ACCEPT
iptables -A TARASEC-HOTSPOT-FWD -i "\$WAN_IF" -o "\$HOTSPOT_IF" -d "\$CIDR" -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT

iptables -t nat -N TARASEC-HOTSPOT-NAT 2>/dev/null || true
iptables -t nat -F TARASEC-HOTSPOT-NAT
iptables -t nat -C POSTROUTING -j TARASEC-HOTSPOT-NAT 2>/dev/null || iptables -t nat -I POSTROUTING 1 -j TARASEC-HOTSPOT-NAT
iptables -t nat -A TARASEC-HOTSPOT-NAT -s "\$CIDR" -o "\$WAN_IF" -j MASQUERADE
EOF
    chmod 0755 /usr/local/sbin/tarasec-hotspot-firewall

    cat >/etc/systemd/system/tarasec-hotspot-firewall.service <<'EOF'
[Unit]
Description=TaraSec hotspot firewall/NAT
After=network-online.target tarasec-hotspot-interface.service
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/tarasec-hotspot-firewall
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
EOF
}

configure_dns_forwarding() {
    # dnsmasq will use the host's normal upstream resolvers. No hard-coded public
    # DNS server is required, which makes the hotspot work on more networks.
    true
}

configure_opennds_if_available() {
    local want="$INSTALL_OPENNDS"
    if [[ "$want" == "no" ]]; then return; fi

    if ! dpkg-query -W -f='${Status}' opennds 2>/dev/null | grep -q 'ok installed'; then
        if apt-cache show opennds >/dev/null 2>&1; then
            if [[ "$want" == "yes" || "$want" == "auto" ]]; then
                log "Installing openNDS captive-portal engine..."
                apt-get install -y opennds
            fi
        elif [[ "$want" == "yes" ]]; then
            die "openNDS was explicitly requested but is unavailable from configured apt repositories."
        else
            warn "openNDS is not available from this system's apt repositories. Basic hotspot networking will still be installed."
            return
        fi
    fi

    if dpkg-query -W -f='${Status}' opennds 2>/dev/null | grep -q 'ok installed'; then
        # openNDS package layouts vary. Use UCI when available, otherwise make a
        # small, easily identifiable edit only when the standard config exists.
        if have uci; then
            uci set opennds.@opennds[0].gatewayinterface="$HOTSPOT_IF" 2>/dev/null || true
            uci set opennds.@opennds[0].gatewayname='TaraSec' 2>/dev/null || true
            uci commit opennds 2>/dev/null || true
        elif [[ -f /etc/opennds/opennds.conf ]]; then
            sed -i -E "s|^[#[:space:]]*GatewayInterface[[:space:]].*|GatewayInterface $HOTSPOT_IF|" /etc/opennds/opennds.conf || true
            sed -i -E 's|^[#[:space:]]*GatewayName[[:space:]].*|GatewayName TaraSec|' /etc/opennds/opennds.conf || true
        fi
        systemctl enable opennds 2>/dev/null || true
    fi
}

netbird_is_joined() {
    have netbird || return 1
    netbird status 2>/dev/null | grep -Eqi 'Connected|Management: Connected|Signal: Connected'
}

configure_optional_netbird() {
    # NetBird is useful for TaraSec management, but a working local hotspot must
    # never fail merely because VPN enrollment has not happened yet.
    if netbird_is_joined; then
        log "Existing NetBird connection detected; leaving it unchanged."
        return
    fi
    if [[ -n "$NETBIRD_SETUP_KEY" ]]; then
        if ! have netbird; then
            log "Installing NetBird..."
            curl -fsSL https://pkgs.netbird.io/install.sh | sh
        fi
        log "Joining TaraSec NetBird control network..."
        netbird up --management-url "$NETBIRD_MGMT_URL" --setup-key "$NETBIRD_SETUP_KEY"
    else
        warn "NetBird is not joined. Hotspot networking will work; remote TaraSec management can be enrolled later."
    fi
}

write_state() {
    cat >"$CONF_DIR/hotspot.conf" <<EOF
# Generated by TaraSec hotspot installer v$VERSION
HOTSPOT_IF=$HOTSPOT_IF
WAN_IF=$WAN_IF
HOTSPOT_CIDR=$HOTSPOT_CIDR
HOTSPOT_IP=$HOTSPOT_IP
SSID=$SSID
CHANNEL=$CHANNEL
EOF
}

validate_configuration() {
    hostapd -t /etc/hostapd/hostapd.conf >/dev/null 2>&1 || die "hostapd rejected the generated configuration. Backup: $BACKUP_DIR"
    dnsmasq --test >/dev/null 2>&1 || die "dnsmasq rejected the generated configuration. Backup: $BACKUP_DIR"
}

start_services() {
    systemctl daemon-reload
    systemctl enable tarasec-hotspot-interface.service tarasec-hotspot-firewall.service hostapd dnsmasq
    systemctl restart tarasec-hotspot-interface.service
    systemctl restart dnsmasq
    systemctl restart hostapd
    systemctl restart tarasec-hotspot-firewall.service
    systemctl restart opennds 2>/dev/null || true
}

health_check() {
    local failed=0
    ip -4 addr show dev "$HOTSPOT_IF" | grep -q "inet $HOTSPOT_IP/24" || { warn "Hotspot IP is not active on $HOTSPOT_IF"; failed=1; }
    systemctl is-active --quiet hostapd || { warn "hostapd is not active"; failed=1; }
    systemctl is-active --quiet dnsmasq || { warn "dnsmasq is not active"; failed=1; }
    iptables -t nat -C TARASEC-HOTSPOT-NAT -s "$HOTSPOT_CIDR" -o "$WAN_IF" -j MASQUERADE 2>/dev/null || { warn "Hotspot NAT rule is missing"; failed=1; }
    if [[ "$failed" -ne 0 ]]; then
        warn "Installation completed with health-check warnings. Run: journalctl -u hostapd -u dnsmasq -n 100 --no-pager"
        return 1
    fi
    return 0
}

summary() {
    echo
    echo "============================================================"
    echo " TaraSec hotspot installer v$VERSION"
    echo "============================================================"
    echo " Internet interface : $WAN_IF"
    echo " Hotspot interface  : $HOTSPOT_IF"
    echo " SSID               : $SSID"
    echo " Wi-Fi security     : $([[ -n "$WIFI_PASSWORD" ]] && echo WPA2 || echo 'open (captive portal)')"
    echo " Hotspot network    : $HOTSPOT_CIDR"
    echo " Gateway            : $HOTSPOT_IP"
    echo " DHCP               : $DHCP_START - $DHCP_END"
    echo " NetBird            : $(netbird_is_joined && echo connected || echo 'not enrolled')"
    echo " Configuration      : $CONF_DIR/hotspot.conf"
    echo " Backup             : $BACKUP_DIR"
    echo
    echo "A phone should now see Wi-Fi network '$SSID'."
    echo "The installer can be rerun safely; it re-detects the machine and updates TaraSec-owned configuration only."
}

main() {
    run_as_root "$@"
    install_base_packages
    unblock_wifi
    detect_interfaces
    choose_subnet
    detect_country
    backup_existing
    release_interface_from_managers
    configure_forwarding
    configure_interface_service
    configure_hostapd
    configure_dnsmasq
    configure_dns_forwarding
    configure_firewall
    configure_opennds_if_available
    configure_optional_netbird
    write_state
    validate_configuration
    start_services
    health_check || true
    summary
}

main "$@"
