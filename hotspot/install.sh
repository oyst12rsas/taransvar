#!/usr/bin/env bash
set -euo pipefail

# TaraSec Hotspot automatic installer
# Normal use:
#   sudo bash hotspot/install.sh
#
# Optional overrides for unusual hardware/automation:
#   TARASEC_HOTSPOT_IF=wlan1
#   TARASEC_WAN_IF=eth0
#   TARASEC_HOTSPOT_CIDR=192.168.50.0/24
#   TARASEC_SSID=TaraSec
#   TARASEC_WIFI_PASSWORD=...     # blank = open/captive portal
#   TARASEC_COUNTRY=NO
#   TARASEC_CHANNEL=6
#   TARASEC_INSTALL_OPENNDS=auto|yes|no
#   TARASEC_NETBIRD_SETUP_KEY=...
#   TARASEC_NONINTERACTIVE=1

VERSION=2.2
CONF_DIR=/etc/tarasec
STATE_FILE=$CONF_DIR/hotspot.conf
SSID="${TARASEC_SSID:-TaraSec}"
PASSWORD="${TARASEC_WIFI_PASSWORD:-}"
COUNTRY="${TARASEC_COUNTRY:-}"
CHANNEL="${TARASEC_CHANNEL:-6}"
HOTSPOT_IF="${TARASEC_HOTSPOT_IF:-}"
WAN_IF="${TARASEC_WAN_IF:-}"
CIDR="${TARASEC_HOTSPOT_CIDR:-}"
OPENNDS="${TARASEC_INSTALL_OPENNDS:-auto}"
NB_KEY="${TARASEC_NETBIRD_SETUP_KEY:-}"
NONINTERACTIVE="${TARASEC_NONINTERACTIVE:-0}"
BACKUP=/root/tarasec-hotspot-backup-$(date +%Y%m%d-%H%M%S)
GW=""; DHCP_START=""; DHCP_END=""

log(){ echo "[TaraSec] $*"; }
warn(){ echo "[TaraSec WARNING] $*" >&2; }
die(){ echo "[TaraSec ERROR] $*" >&2; exit 1; }
have(){ command -v "$1" >/dev/null 2>&1; }

become_root(){
  if [[ ${EUID:-$(id -u)} -ne 0 ]]; then
    have sudo || die "sudo is required when not running as root"
    exec sudo -E bash "$0" "$@"
  fi
}

state_value(){
  local key="$1"
  [[ -r "$STATE_FILE" ]] || return 0
  sed -n "s/^${key}=//p" "$STATE_FILE" | tail -1
}

load_previous_state(){
  [[ -n "$HOTSPOT_IF" ]] || HOTSPOT_IF="$(state_value HOTSPOT_IF)"
  [[ -n "$WAN_IF" ]] || WAN_IF="$(state_value WAN_IF)"
  [[ -n "$CIDR" ]] || CIDR="$(state_value HOTSPOT_CIDR)"
}

install_packages(){
  have apt-get || die "Debian/Ubuntu/Raspberry Pi OS is currently required"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -y
  apt-get install -y hostapd dnsmasq-base iw rfkill iproute2 iptables curl ca-certificates
  systemctl unmask hostapd 2>/dev/null || true
}

wifi_list(){ iw dev 2>/dev/null | awk '$1=="Interface"{print $2}'; }

supports_ap(){
  local i="$1" w
  w=$(iw dev "$i" info 2>/dev/null | awk '/wiphy/{print $2;exit}')
  [[ -n "$w" ]] || return 1
  iw phy "phy$w" info 2>/dev/null |
    sed -n '/Supported interface modes:/,/^[[:space:]]*Band /p' |
    grep -Eq '^[[:space:]]*\*[[:space:]]+AP$'
}

choose_from(){
  local prompt="$1"; shift; local a=("$@") n
  [[ ${#a[@]} -gt 0 ]] || return 1
  [[ ${#a[@]} -eq 1 ]] && { echo "${a[0]}"; return; }
  [[ "$NONINTERACTIVE" == 1 ]] && die "$prompt Set TARASEC_HOTSPOT_IF explicitly."
  echo "$prompt" >&2
  for ((n=0;n<${#a[@]};n++)); do echo "  $((n+1))) ${a[$n]}" >&2; done
  while true; do
    read -r -p "Select [1-${#a[@]}]: " n
    [[ "$n" =~ ^[0-9]+$ ]] && ((n>=1 && n<=${#a[@]})) && { echo "${a[$((n-1))]}"; return; }
  done
}

detect_interfaces(){
  local current_default candidates=() i
  current_default=$(ip -4 route show default | awk '$1=="default"{for(i=1;i<=NF;i++)if($i=="dev"){print $(i+1);exit}}')
  if [[ -n "${TARASEC_WAN_IF:-}" ]]; then WAN_IF="$TARASEC_WAN_IF";
  elif [[ -n "$current_default" ]]; then WAN_IF="$current_default";
  fi
  [[ -n "$WAN_IF" ]] || die "No Internet/default-route interface found"
  ip link show "$WAN_IF" >/dev/null 2>&1 || die "WAN interface '$WAN_IF' does not exist"

  if [[ -n "$HOTSPOT_IF" ]] && { ! ip link show "$HOTSPOT_IF" >/dev/null 2>&1 || ! supports_ap "$HOTSPOT_IF"; }; then
    warn "Previously selected hotspot interface '$HOTSPOT_IF' is no longer usable; redetecting"
    HOTSPOT_IF=""
  fi
  if [[ -z "$HOTSPOT_IF" ]]; then
    while read -r i; do
      [[ -n "$i" && "$i" != "$WAN_IF" ]] || continue
      supports_ap "$i" && candidates+=("$i")
    done < <(wifi_list)
    HOTSPOT_IF=$(choose_from "Multiple AP-capable Wi-Fi adapters were found." "${candidates[@]}") || true
  fi
  [[ -n "$HOTSPOT_IF" ]] || die "No separate AP-capable Wi-Fi adapter found. Connect one and rerun."
  [[ "$HOTSPOT_IF" != "$WAN_IF" ]] || die "Refusing to use the same interface for WAN and hotspot"
  rfkill unblock wifi 2>/dev/null || true
  rfkill list 2>/dev/null | grep -A4 -F 'Wireless LAN' | grep -q 'Hard blocked: yes' && die "Wi-Fi is hardware blocked"
  log "WAN: $WAN_IF"
  log "Hotspot Wi-Fi: $HOTSPOT_IF"
}

route_conflict_except_own(){
  local c="$1"
  ip -4 route show table all 2>/dev/null |
    grep -F "$c" |
    grep -vE "(^|[[:space:]])dev[[:space:]]+$HOTSPOT_IF([[:space:]]|$)" |
    grep -q .
}

choose_network(){
  local c prefix
  if [[ -n "$CIDR" ]] && route_conflict_except_own "$CIDR"; then
    warn "Saved/requested subnet $CIDR now conflicts with another interface; choosing a new subnet"
    CIDR=""
  fi
  if [[ -z "$CIDR" ]]; then
    for c in 192.168.50.0/24 192.168.60.0/24 192.168.70.0/24 192.168.80.0/24 192.168.90.0/24 10.42.0.0/24 10.43.0.0/24 10.44.0.0/24 172.20.50.0/24 172.21.50.0/24; do
      if ! ip -4 route show table all | grep -qF "$c"; then CIDR="$c"; break; fi
    done
  fi
  [[ "$CIDR" =~ ^([0-9]{1,3}\.){3}0/24$ ]] || die "Could not choose a free /24 hotspot network"
  prefix=${CIDR%0/24}
  GW=${prefix}1; DHCP_START=${prefix}50; DHCP_END=${prefix}200
  log "Hotspot network: $CIDR"
}

backup(){
  mkdir -p "$BACKUP" "$CONF_DIR"
  for f in /etc/hostapd/hostapd.conf /etc/tarasec/dnsmasq-hotspot.conf /etc/systemd/system/tarasec-hotspot-interface.service /etc/systemd/system/tarasec-hotspot-firewall.service /etc/systemd/system/tarasec-hotspot-dnsmasq.service /usr/local/sbin/tarasec-hotspot-firewall "$STATE_FILE"; do
    [[ -e "$f" ]] && cp -a "$f" "$BACKUP/$(basename "$f")" 2>/dev/null || true
  done
}

release_wifi(){
  have nmcli && nmcli device set "$HOTSPOT_IF" managed no 2>/dev/null || true
  systemctl stop "wpa_supplicant@$HOTSPOT_IF.service" 2>/dev/null || true
  pkill -f "wpa_supplicant.*$HOTSPOT_IF" 2>/dev/null || true
}

write_network_config(){
  cat >/etc/sysctl.d/90-tarasec-hotspot.conf <<EOF
net.ipv4.ip_forward=1
EOF
  sysctl -w net.ipv4.ip_forward=1 >/dev/null

  cat >/etc/systemd/system/tarasec-hotspot-interface.service <<EOF
[Unit]
Description=TaraSec hotspot interface
After=network-online.target
Wants=network-online.target
Before=hostapd.service tarasec-hotspot-dnsmasq.service
[Service]
Type=oneshot
ExecStart=/sbin/ip link set dev $HOTSPOT_IF up
ExecStart=/sbin/ip addr flush dev $HOTSPOT_IF
ExecStart=/sbin/ip addr add $GW/24 dev $HOTSPOT_IF
RemainAfterExit=yes
[Install]
WantedBy=multi-user.target
EOF

  [[ -n "$COUNTRY" ]] || COUNTRY=$(iw reg get 2>/dev/null | awk '/^country [A-Z][A-Z]:/{gsub(":","",$2);print $2;exit}')
  [[ "$COUNTRY" =~ ^[A-Z]{2}$ ]] || COUNTRY=""
  mkdir -p /etc/hostapd
  {
    echo "interface=$HOTSPOT_IF"
    echo driver=nl80211
    echo "ssid=$SSID"
    [[ -n "$COUNTRY" ]] && echo "country_code=$COUNTRY"
    echo hw_mode=g
    echo "channel=$CHANNEL"
    echo ieee80211n=1
    echo wmm_enabled=1
    echo auth_algs=1
    if [[ -n "$PASSWORD" ]]; then
      (( ${#PASSWORD} >= 8 && ${#PASSWORD} <= 63 )) || die "Wi-Fi password must be 8-63 characters"
      echo wpa=2; echo "wpa_passphrase=$PASSWORD"; echo wpa_key_mgmt=WPA-PSK; echo rsn_pairwise=CCMP
    else
      echo wpa=0
    fi
  } >/etc/hostapd/hostapd.conf

  if [[ -f /etc/default/hostapd ]]; then
    grep -q '^DAEMON_CONF=' /etc/default/hostapd && sed -i 's|^DAEMON_CONF=.*|DAEMON_CONF="/etc/hostapd/hostapd.conf"|' /etc/default/hostapd || echo 'DAEMON_CONF="/etc/hostapd/hostapd.conf"' >>/etc/default/hostapd
  fi

  mkdir -p "$CONF_DIR"
  cat >$CONF_DIR/dnsmasq-hotspot.conf <<EOF
interface=$HOTSPOT_IF
bind-interfaces
listen-address=$GW
port=0
dhcp-range=$DHCP_START,$DHCP_END,255.255.255.0,12h
dhcp-option=3,$GW
dhcp-option=6,1.1.1.1,8.8.8.8
dhcp-authoritative
EOF
  dnsmasq --test --conf-file=$CONF_DIR/dnsmasq-hotspot.conf >/dev/null 2>&1 || die "Generated TaraSec dnsmasq configuration is invalid"

  cat >/etc/systemd/system/tarasec-hotspot-dnsmasq.service <<EOF
[Unit]
Description=TaraSec hotspot DHCP
After=tarasec-hotspot-interface.service
Requires=tarasec-hotspot-interface.service
Before=opennds.service
[Service]
Type=simple
ExecStart=/usr/sbin/dnsmasq --keep-in-foreground --conf-file=$CONF_DIR/dnsmasq-hotspot.conf --pid-file=/run/tarasec-hotspot-dnsmasq.pid
Restart=on-failure
RestartSec=2
[Install]
WantedBy=multi-user.target
EOF
}

write_firewall(){
  cat >/usr/local/sbin/tarasec-hotspot-firewall <<EOF
#!/usr/bin/env bash
set -e
H='$HOTSPOT_IF'; W='$WAN_IF'; C='$CIDR'
iptables -N TARASEC-HOTSPOT-FWD 2>/dev/null || true
iptables -F TARASEC-HOTSPOT-FWD
iptables -C FORWARD -j TARASEC-HOTSPOT-FWD 2>/dev/null || iptables -I FORWARD 1 -j TARASEC-HOTSPOT-FWD
iptables -A TARASEC-HOTSPOT-FWD -i "\$H" -o "\$W" -s "\$C" -j ACCEPT
iptables -A TARASEC-HOTSPOT-FWD -i "\$W" -o "\$H" -d "\$C" -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT
iptables -t nat -N TARASEC-HOTSPOT-NAT 2>/dev/null || true
iptables -t nat -F TARASEC-HOTSPOT-NAT
iptables -t nat -C POSTROUTING -j TARASEC-HOTSPOT-NAT 2>/dev/null || iptables -t nat -I POSTROUTING 1 -j TARASEC-HOTSPOT-NAT
iptables -t nat -A TARASEC-HOTSPOT-NAT -s "\$C" -o "\$W" -j MASQUERADE
EOF
  chmod 0755 /usr/local/sbin/tarasec-hotspot-firewall
  cat >/etc/systemd/system/tarasec-hotspot-firewall.service <<EOF
[Unit]
Description=TaraSec hotspot firewall
After=network-online.target tarasec-hotspot-interface.service
[Service]
Type=oneshot
ExecStart=/usr/local/sbin/tarasec-hotspot-firewall
RemainAfterExit=yes
[Install]
WantedBy=multi-user.target
EOF
}

setup_opennds(){
  [[ "$OPENNDS" == no ]] && return
  if ! dpkg-query -W -f='${Status}' opennds 2>/dev/null | grep -q 'ok installed'; then
    if apt-cache show opennds >/dev/null 2>&1; then apt-get install -y opennds
    elif [[ "$OPENNDS" == yes ]]; then die "openNDS requested but unavailable from apt"
    else warn "openNDS unavailable from apt; installing hotspot networking without captive portal engine"; return
    fi
  fi
  if have uci; then
    uci set opennds.@opennds[0].gatewayinterface="$HOTSPOT_IF" 2>/dev/null || true
    uci set opennds.@opennds[0].gatewayname=TaraSec 2>/dev/null || true
    uci commit opennds 2>/dev/null || true
  elif [[ -f /etc/opennds/opennds.conf ]]; then
    grep -qE '^[#[:space:]]*GatewayInterface' /etc/opennds/opennds.conf && sed -i -E "s|^[#[:space:]]*GatewayInterface.*|GatewayInterface $HOTSPOT_IF|" /etc/opennds/opennds.conf || echo "GatewayInterface $HOTSPOT_IF" >>/etc/opennds/opennds.conf
  fi
  systemctl enable opennds 2>/dev/null || true
}

setup_netbird(){
  if have netbird && netbird status 2>/dev/null | grep -Eqi 'Connected|Management: Connected'; then
    log "Existing NetBird connection retained"
    return
  fi
  if [[ -n "$NB_KEY" ]]; then
    have netbird || curl -fsSL https://pkgs.netbird.io/install.sh | sh
    netbird up --management-url "${TARASEC_NETBIRD_MGMT_URL:-https://netbird.taransvar.no}" --setup-key "$NB_KEY"
  else
    warn "NetBird not enrolled; local hotspot works and remote management can be enrolled later"
  fi
}

save_state(){
  cat >"$STATE_FILE" <<EOF
INSTALLER_VERSION=$VERSION
HOTSPOT_IF=$HOTSPOT_IF
WAN_IF=$WAN_IF
HOTSPOT_CIDR=$CIDR
HOTSPOT_IP=$GW
SSID=$SSID
CHANNEL=$CHANNEL
EOF
}

start_and_check(){
  systemctl daemon-reload
  # Leave the host's normal dnsmasq service alone; TaraSec owns a dedicated instance.
  systemctl enable tarasec-hotspot-interface tarasec-hotspot-firewall tarasec-hotspot-dnsmasq hostapd >/dev/null
  systemctl restart tarasec-hotspot-interface
  systemctl restart tarasec-hotspot-dnsmasq
  systemctl restart hostapd
  systemctl restart tarasec-hotspot-firewall
  systemctl restart opennds 2>/dev/null || true

  local bad=0
  systemctl is-active --quiet hostapd || { warn "hostapd failed"; bad=1; }
  systemctl is-active --quiet tarasec-hotspot-dnsmasq || { warn "TaraSec DHCP failed"; bad=1; }
  ip -4 addr show "$HOTSPOT_IF" | grep -q "inet $GW/24" || { warn "hotspot IP missing"; bad=1; }
  [[ $bad -eq 0 ]] || warn "See: journalctl -u hostapd -u tarasec-hotspot-dnsmasq -n 100 --no-pager"
}

summary(){
  echo
  echo "TaraSec hotspot installation complete"
  echo "  WAN:       $WAN_IF"
  echo "  Wi-Fi:     $HOTSPOT_IF"
  echo "  SSID:      $SSID"
  echo "  Security:  $([[ -n "$PASSWORD" ]] && echo WPA2 || echo 'open/captive portal')"
  echo "  Network:   $CIDR"
  echo "  Gateway:   $GW"
  echo "  DHCP:      $DHCP_START - $DHCP_END"
  echo "  State:     $STATE_FILE"
  echo "  Backup:    $BACKUP"
  echo
  echo "Rerunning this installer reuses the saved hotspot radio/subnet when still valid."
}

main(){
  become_root "$@"
  load_previous_state
  install_packages
  detect_interfaces
  choose_network
  backup
  release_wifi
  write_network_config
  write_firewall
  setup_opennds
  setup_netbird
  save_state
  start_and_check
  summary
}
main "$@"
