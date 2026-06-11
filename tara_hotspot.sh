#!/usr/bin/env bash
set -euo pipefail

# =========================
# Tara Hotspot Installer v0.1
# =========================

SSID="Tara_Hotspot"
WIFI_PASSWORD="TaraHotspot1234"

WG_IF="wg0"
WG_PORT="51820"
WG_ENDPOINT="YOUR_VPS_IP_OR_DOMAIN:443"
WG_ALLOWED_IPS="10.47.0.0/16,10.100.0.0/16"
WG_ADDRESS="10.47.X.X/32"   # CHANGE AFTER ØYSTEIN ASSIGNS IP

TARA_REPO="https://github.com/YOUR_ORG/YOUR_TARA_REPO.git"
TARA_DIR="/opt/tara"

HOTSPOT_IF=""
WAN_IF=""
HOTSPOT_CIDR=""
HOTSPOT_IP=""
DHCP_START=""
DHCP_END=""

BACKUP_DIR="/root/tara-hotspot-backup-$(date +%Y%m%d-%H%M%S)"

require_root() {
    if [[ "$EUID" -ne 0 ]]; then
        echo "Run as root:"
        echo "  sudo bash $0"
        exit 1
    fi
}

detect_interfaces() {
    echo "[+] Detecting interfaces..."

    HOTSPOT_IF=$(iw dev 2>/dev/null | awk '$1=="Interface"{print $2; exit}')

    if [[ -z "$HOTSPOT_IF" ]]; then
        echo "[ERROR] No Wi-Fi interface found."
        echo "Check with: iw dev"
        exit 1
    fi

    WAN_IF=$(ip route | awk '/default/ {print $5; exit}')

    if [[ -z "$WAN_IF" ]]; then
        echo "[ERROR] No default internet interface found."
        echo "Check with: ip route"
        exit 1
    fi

    echo "    Hotspot Wi-Fi interface: $HOTSPOT_IF"
    echo "    Internet interface:      $WAN_IF"
}

subnet_in_use() {
    local subnet="$1"
    ip route | grep -q "$subnet" && return 0
    ip addr | grep -q "${subnet%0/24}" && return 0
    return 1
}

choose_hotspot_subnet() {
    echo "[+] Choosing hotspot subnet..."

    local candidates=(
        "192.168.50.0/24"
        "192.168.60.0/24"
        "192.168.70.0/24"
        "192.168.80.0/24"
        "192.168.90.0/24"
    )

    for cidr in "${candidates[@]}"; do
        if ! ip route | grep -q "$cidr"; then
            HOTSPOT_CIDR="$cidr"
            break
        fi
    done

    if [[ -z "$HOTSPOT_CIDR" ]]; then
        echo "[ERROR] No free hotspot subnet found."
        exit 1
    fi

    local base="${HOTSPOT_CIDR%0/24}"
    HOTSPOT_IP="${base}1"
    DHCP_START="${base}50"
    DHCP_END="${base}200"

    echo "    Selected subnet: $HOTSPOT_CIDR"
    echo "    Gateway IP:      $HOTSPOT_IP"
    echo "    DHCP range:      $DHCP_START - $DHCP_END"
}

install_packages() {
    echo "[+] Installing packages..."

    apt update
    apt install -y \
        hostapd \
        dnsmasq \
        wireguard \
        iptables \
        iptables-persistent \
        git \
        curl \
        perl \
        build-essential

    systemctl unmask hostapd || true
}

backup_configs() {
    echo "[+] Backing up configs to $BACKUP_DIR"
    mkdir -p "$BACKUP_DIR"

    cp -a /etc/hostapd "$BACKUP_DIR/" 2>/dev/null || true
    cp -a /etc/dnsmasq.conf "$BACKUP_DIR/" 2>/dev/null || true
    cp -a /etc/wireguard "$BACKUP_DIR/" 2>/dev/null || true
    cp -a /etc/sysctl.conf "$BACKUP_DIR/" 2>/dev/null || true
}

configure_ip_forwarding() {
    echo "[+] Enabling IPv4 forwarding..."

    sysctl -w net.ipv4.ip_forward=1

    if ! grep -q '^net.ipv4.ip_forward=1' /etc/sysctl.conf; then
        echo 'net.ipv4.ip_forward=1' >> /etc/sysctl.conf
    fi
}

configure_hotspot_ip() {
    echo "[+] Configuring hotspot IP..."

    cat > /etc/systemd/system/tara-hotspot-ip.service <<EOF
[Unit]
Description=Configure Tara Hotspot IP
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/sbin/ip link set $HOTSPOT_IF up
ExecStart=/sbin/ip addr flush dev $HOTSPOT_IF
ExecStart=/sbin/ip addr add $HOTSPOT_IP/24 dev $HOTSPOT_IF
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
EOF

    systemctl daemon-reload
    systemctl enable tara-hotspot-ip.service
    systemctl restart tara-hotspot-ip.service
}

configure_hostapd() {
    echo "[+] Configuring hostapd..."

    cat > /etc/hostapd/hostapd.conf <<EOF
interface=$HOTSPOT_IF
driver=nl80211
ssid=$SSID
hw_mode=g
channel=7
wmm_enabled=0
macaddr_acl=0
auth_algs=1
ignore_broadcast_ssid=0
wpa=2
wpa_passphrase=$WIFI_PASSWORD
wpa_key_mgmt=WPA-PSK
rsn_pairwise=CCMP
EOF

    sed -i 's|^#*DAEMON_CONF=.*|DAEMON_CONF="/etc/hostapd/hostapd.conf"|' /etc/default/hostapd || true

    systemctl enable hostapd
}

configure_dnsmasq() {
    echo "[+] Configuring dnsmasq..."

    mv /etc/dnsmasq.conf /etc/dnsmasq.conf.orig 2>/dev/null || true

    cat > /etc/dnsmasq.conf <<EOF
interface=$HOTSPOT_IF
bind-interfaces
dhcp-range=$DHCP_START,$DHCP_END,255.255.255.0,12h
dhcp-option=3,$HOTSPOT_IP
dhcp-option=6,1.1.1.1,8.8.8.8
domain-needed
bogus-priv
EOF

    systemctl enable dnsmasq
}

configure_wireguard() {
    echo "[+] Configuring WireGuard..."

    mkdir -p /etc/wireguard
    chmod 700 /etc/wireguard

    if [[ ! -f /etc/wireguard/privatekey ]]; then
        wg genkey | tee /etc/wireguard/privatekey | wg pubkey > /etc/wireguard/publickey
        chmod 600 /etc/wireguard/privatekey
    fi

    local private_key
    private_key=$(cat /etc/wireguard/privatekey)

    cat > /etc/wireguard/$WG_IF.conf <<EOF
[Interface]
Address = $WG_ADDRESS
PrivateKey = $private_key

[Peer]
PublicKey = VPS_PUBLIC_KEY_HERE
Endpoint = $WG_ENDPOINT
AllowedIPs = $WG_ALLOWED_IPS
PersistentKeepalive = 25
EOF

    chmod 600 /etc/wireguard/$WG_IF.conf

    systemctl enable wg-quick@$WG_IF || true

    echo
    echo "===================================================="
    echo " WireGuard key generated"
    echo "===================================================="
    echo
    echo "Send this public key to Øystein:"
    echo
    cat /etc/wireguard/publickey
    echo
    echo "Then edit:"
    echo "  /etc/wireguard/$WG_IF.conf"
    echo
    echo "Replace:"
    echo "  WG_ADDRESS=$WG_ADDRESS"
    echo "  VPS_PUBLIC_KEY_HERE"
    echo "  WG_ENDPOINT=$WG_ENDPOINT"
    echo
    echo "Then start WireGuard:"
    echo "  sudo systemctl restart wg-quick@$WG_IF"
    echo
}

configure_nat_firewall() {
    echo "[+] Configuring NAT and forwarding..."

    iptables -P FORWARD ACCEPT

    iptables -t nat -C POSTROUTING -s "$HOTSPOT_CIDR" -o "$WAN_IF" -j MASQUERADE 2>/dev/null || \
        iptables -t nat -A POSTROUTING -s "$HOTSPOT_CIDR" -o "$WAN_IF" -j MASQUERADE

    iptables -t nat -C POSTROUTING -s "$HOTSPOT_CIDR" -o "$WG_IF" -j MASQUERADE 2>/dev/null || \
        iptables -t nat -A POSTROUTING -s "$HOTSPOT_CIDR" -o "$WG_IF" -j MASQUERADE

    iptables -C FORWARD -i "$HOTSPOT_IF" -o "$WAN_IF" -s "$HOTSPOT_CIDR" -j ACCEPT 2>/dev/null || \
        iptables -A FORWARD -i "$HOTSPOT_IF" -o "$WAN_IF" -s "$HOTSPOT_CIDR" -j ACCEPT

    iptables -C FORWARD -i "$WAN_IF" -o "$HOTSPOT_IF" -d "$HOTSPOT_CIDR" -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT 2>/dev/null || \
        iptables -A FORWARD -i "$WAN_IF" -o "$HOTSPOT_IF" -d "$HOTSPOT_CIDR" -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT

    iptables -C FORWARD -i "$HOTSPOT_IF" -o "$WG_IF" -s "$HOTSPOT_CIDR" -j ACCEPT 2>/dev/null || \
        iptables -A FORWARD -i "$HOTSPOT_IF" -o "$WG_IF" -s "$HOTSPOT_CIDR" -j ACCEPT

    iptables -C FORWARD -i "$WG_IF" -o "$HOTSPOT_IF" -d "$HOTSPOT_CIDR" -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT 2>/dev/null || \
        iptables -A FORWARD -i "$WG_IF" -o "$HOTSPOT_IF" -d "$HOTSPOT_CIDR" -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT

    netfilter-persistent save || true
}

install_tara_systems() {
    echo "[+] Installing Tara systems from GitHub..."

    if [[ "$TARA_REPO" == *"YOUR_ORG"* ]]; then
        echo "[WARN] Tara GitHub repo not configured yet."
        echo "       Edit TARA_REPO in this script."
        return
    fi

    if [[ ! -d "$TARA_DIR" ]]; then
        git clone "$TARA_REPO" "$TARA_DIR"
    else
        cd "$TARA_DIR"
        git pull
    fi

    echo "[+] Tara repo installed at $TARA_DIR"

    if [[ -f "$TARA_DIR/install.sh" ]]; then
        bash "$TARA_DIR/install.sh"
    else
        echo "[WARN] No install.sh found in Tara repo."
    fi
}

restart_services() {
    echo "[+] Restarting services..."

    systemctl restart tara-hotspot-ip.service
    systemctl restart dnsmasq
    systemctl restart hostapd

    echo "[+] Not starting WireGuard automatically until VPS public key/IP are configured."
}

print_summary() {
    echo
    echo "===================================================="
    echo " Tara Hotspot install summary"
    echo "===================================================="
    echo
    echo "SSID:              $SSID"
    echo "Wi-Fi password:    $WIFI_PASSWORD"
    echo "Hotspot interface: $HOTSPOT_IF"
    echo "Internet iface:    $WAN_IF"
    echo "Hotspot subnet:    $HOTSPOT_CIDR"
    echo "Hotspot gateway:   $HOTSPOT_IP"
    echo "DHCP range:        $DHCP_START - $DHCP_END"
    echo
    echo "WireGuard public key:"
    cat /etc/wireguard/publickey
    echo
    echo "Useful commands:"
    echo "  ip addr"
    echo "  ip route"
    echo "  sudo wg"
    echo "  sudo systemctl status hostapd"
    echo "  sudo systemctl status dnsmasq"
    echo "  sudo systemctl status wg-quick@$WG_IF"
    echo "  sudo journalctl -u hostapd -e"
    echo "  sudo journalctl -u dnsmasq -e"
    echo
    echo "Backup directory:"
    echo "  $BACKUP_DIR"
    echo
}

main() {
    require_root
    detect_interfaces
    choose_hotspot_subnet
    install_packages
    backup_configs
    configure_ip_forwarding
    configure_hotspot_ip
    configure_hostapd
    configure_dnsmasq
    configure_wireguard
    configure_nat_firewall
    install_tara_systems
    restart_services
    print_summary
}

main "$@"