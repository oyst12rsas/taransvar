#!/bin/bash
set -euo pipefail

# Install openNDS plus the complete TaraSec captive-portal integration on
# Debian/Ubuntu/Raspberry Pi OS. Prefer the distro package; fall back to the
# Cigar-validated openNDS 10.1.0 source release when required.

if [ "$(id -u)" -ne 0 ]; then
    echo "Run as root: sudo bash $0" >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
OPENNDS_VERSION="${OPENNDS_VERSION:-10.1.0}"
SRC_ROOT="${TARASEC_OPENNDS_SRC_ROOT:-/usr/local/src}"
SRC_DIR="$SRC_ROOT/tarasec-opennds-$OPENNDS_VERSION"
TARBALL="$SRC_ROOT/opennds-$OPENNDS_VERSION.tar.gz"
URL="https://codeload.github.com/opennds/opennds/tar.gz/refs/tags/v$OPENNDS_VERSION"
HOTSPOT_IF="${TARASEC_HOTSPOT_IF:-}"
HOTSPOT_ADDR="${TARASEC_HOTSPOT_ADDR:-192.168.50.1/24}"
HOTSPOT_NAME="${TARASEC_HOTSPOT_NAME:-TaraSec}"
HOTSPOT_IP="${HOTSPOT_ADDR%/*}"

. /etc/os-release
if [ "${ID:-}" != "ubuntu" ] && [ "${ID:-}" != "debian" ] && [ "${ID:-}" != "raspbian" ] && [ "${ID_LIKE:-}" != *debian* ]; then
    echo "ERROR: this helper currently supports Debian-family systems." >&2
    exit 1
fi

install_opennds_package() {
    if command -v ndsctl >/dev/null 2>&1 && [ -x /usr/lib/opennds/client_params.sh ]; then
        echo "openNDS already installed with client_params.sh."
        return
    fi

    apt-get update
    if apt-cache show opennds >/dev/null 2>&1; then
        echo "Trying distribution openNDS package..."
        DEBIAN_FRONTEND=noninteractive apt-get install -y opennds || true
    fi

    if command -v ndsctl >/dev/null 2>&1 && [ -x /usr/lib/opennds/client_params.sh ]; then
        echo "Distribution openNDS package is usable."
        return
    fi

    echo "Distribution package unavailable/incomplete; building openNDS $OPENNDS_VERSION from source."
    DEBIAN_FRONTEND=noninteractive apt-get install -y \
        build-essential ca-certificates curl pkg-config \
        libmicrohttpd-dev nftables iptables

    mkdir -p "$SRC_ROOT"
    rm -rf "$SRC_DIR"
    curl -fL "$URL" -o "$TARBALL"
    tar -xzf "$TARBALL" -C "$SRC_ROOT"
    UPSTREAM_DIR="$SRC_ROOT/openNDS-$OPENNDS_VERSION"
    if [ ! -d "$UPSTREAM_DIR" ]; then
        echo "ERROR: expected extracted directory $UPSTREAM_DIR not found." >&2
        exit 1
    fi
    mv "$UPSTREAM_DIR" "$SRC_DIR"
    make -C "$SRC_DIR"
    make -C "$SRC_DIR" install
    ldconfig
}

install_opennds_package

if ! command -v ndsctl >/dev/null 2>&1; then
    echo "ERROR: openNDS installation did not install ndsctl." >&2
    exit 1
fi
if [ ! -x /usr/lib/opennds/client_params.sh ]; then
    echo "ERROR: openNDS installation did not install /usr/lib/opennds/client_params.sh." >&2
    exit 1
fi
if ! command -v nft >/dev/null 2>&1; then
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y nftables
fi

if [ -f /etc/systemd/system/opennds.service.d/10-tarasec-boot.conf ] || \
   [ -f /etc/systemd/system/opennds.service.d/20-tarasec-captive-login.conf ] || \
   [ -f /usr/local/sbin/tarasec-opennds-local-access ]; then
    echo "Removing obsolete TaraSec openNDS service overrides..."
    systemctl stop opennds >/dev/null 2>&1 || true
    rm -f /etc/systemd/system/opennds.service.d/10-tarasec-boot.conf
    rm -f /etc/systemd/system/opennds.service.d/20-tarasec-captive-login.conf
    rm -f /usr/local/sbin/tarasec-opennds-local-access
    rmdir /etc/systemd/system/opennds.service.d 2>/dev/null || true
    systemctl daemon-reload
    systemctl reset-failed opennds >/dev/null 2>&1 || true
fi

echo "Installing TaraSec captive-portal assets..."
install -m 0755 "$REPO_ROOT/hotspot/opennds/theme_tarasec.sh" /usr/lib/opennds/theme_tarasec.sh
install -m 0755 "$REPO_ROOT/hotspot/opennds/access_policy.pl" /usr/lib/opennds/access_policy.pl
install -m 0755 "$REPO_ROOT/hotspot/opennds/custombinauth.sh" /usr/lib/opennds/custombinauth.sh
install -m 0755 "$REPO_ROOT/hotspot/opennds/tarasec-access-check" /usr/local/sbin/tarasec-access-check
install -m 0755 "$REPO_ROOT/hotspot/opennds/tarasec-access.sh" /usr/local/sbin/tarasec-central-access-check
install -m 0755 "$REPO_ROOT/hotspot/opennds/tarasec-access-refresh" /usr/local/sbin/tarasec-access-refresh
install -m 0755 "$REPO_ROOT/hotspot/opennds/tarasec-access-service" /usr/local/sbin/tarasec-access-service
install -m 0755 "$REPO_ROOT/hotspot/opennds/tarasec-subscriber-logout" /usr/local/sbin/tarasec-subscriber-logout
install -m 0755 "$REPO_ROOT/hotspot/opennds/tarasec-single-subscriber" /usr/local/sbin/tarasec-single-subscriber
install -m 0755 "$REPO_ROOT/hotspot/opennds/tarasec-global-bind" /usr/local/sbin/tarasec-global-bind

mkdir -p /var/www/html/hotspot
cp -a "$REPO_ROOT/html/hotspot/." /var/www/html/hotspot/
chown -R root:root /var/www/html/hotspot
find /var/www/html/hotspot -type d -exec chmod 0755 {} +
find /var/www/html/hotspot -type f -exec chmod 0644 {} +

mkdir -p /etc/tarasec
cat > /etc/tarasec/access-mysql.cnf <<'EOF'
[client]
user=scriptUsrAces3f3
password="rErte8Oi98e-2_#"
host=localhost
EOF
chmod 0600 /etc/tarasec/access-mysql.cnf

if ! command -v sudo >/dev/null 2>&1; then
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y sudo
fi
cat > /etc/sudoers.d/tarasec-hotspot-logout <<'EOF'
www-data ALL=(root) NOPASSWD: /usr/local/sbin/tarasec-subscriber-logout *
www-data ALL=(root) NOPASSWD: /usr/local/sbin/tarasec-global-bind *
www-data ALL=(root) NOPASSWD: /usr/local/sbin/tarasec-access-refresh
EOF
chmod 0440 /etc/sudoers.d/tarasec-hotspot-logout
visudo -cf /etc/sudoers.d/tarasec-hotspot-logout >/dev/null

# Ubuntu's hardened Apache unit hides sudoers from the service mount namespace.
# Keep its other inaccessible paths while allowing sudo to read the narrowly
# scoped TaraSec helper policy above.
mkdir -p /etc/systemd/system/apache2.service.d
cat > /etc/systemd/system/apache2.service.d/tarasec-sudo.conf <<'EOF'
[Service]
InaccessiblePaths=
InaccessiblePaths=/boot
InaccessiblePaths=/root
InaccessiblePaths=-/etc/ssh
InaccessiblePaths=-/etc/apt
InaccessiblePaths=-/etc/.git
InaccessiblePaths=-/etc/.svn
EOF

cat > /etc/systemd/system/tarasec-access.service <<'EOF'
[Unit]
Description=TaraSec hotspot access-table authority
After=mariadb.service mysql.service
Before=opennds.service

[Service]
Type=simple
ExecStart=/usr/local/sbin/tarasec-access-service
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable tarasec-access.service >/dev/null
systemctl restart tarasec-access.service

HOTSPOT_PREFIX="${HOTSPOT_ADDR#*/}"
IFS=. read -r h1 h2 h3 h4 <<<"$HOTSPOT_IP"
if [ "$HOTSPOT_PREFIX" = "24" ] && [ -n "${h1:-}" ] && [ -n "${h2:-}" ] && [ -n "${h3:-}" ]; then
    HOTSPOT_CIDR="$h1.$h2.$h3.0/24"
else
    HOTSPOT_CIDR="192.168.50.0/24"
fi

cat > /etc/apache2/conf-available/tarasec-captive-login.conf <<EOF
Listen 8080
<VirtualHost *:8080>
    DocumentRoot /var/www/html
    <Directory /var/www/html/hotspot>
        Require ip $HOTSPOT_CIDR
        Options -Indexes
        AllowOverride None
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/tarasec-captive-login-error.log
    CustomLog \${APACHE_LOG_DIR}/tarasec-captive-login-access.log combined
</VirtualHost>
EOF
a2enconf tarasec-captive-login >/dev/null
apache2ctl configtest
systemctl daemon-reload || true
systemctl restart apache2

if [ -z "$HOTSPOT_IF" ] && [ -r /etc/config/opennds ]; then
    HOTSPOT_IF="$(sed -n "s/^[[:space:]]*option[[:space:]]\+gatewayinterface[[:space:]]+'\([^']*\)'.*/\1/p" /etc/config/opennds | head -1)"
fi
if [ -n "$HOTSPOT_IF" ]; then
    if ! ip -4 addr show dev "$HOTSPOT_IF" 2>/dev/null | grep -Eq "[[:space:]]inet[[:space:]]+$HOTSPOT_IP/"; then
        echo "Hotspot interface $HOTSPOT_IF does not yet have $HOTSPOT_IP; deferring openNDS TaraSec activation until the AP is up."
        HOTSPOT_IF=""
    fi
fi

if [ -n "$HOTSPOT_IF" ]; then
    echo "Configuring openNDS TaraSec ThemeSpec on $HOTSPOT_IF..."
    mkdir -p /etc/config /etc/opennds

    # NetworkManager shared mode launches a per-interface dnsmasq using this
    # directory. openNDS generates links to status.client, so this record is a
    # required part of the portal rather than an optional convenience.
    mkdir -p /etc/NetworkManager/dnsmasq-shared.d
    cat > /etc/NetworkManager/dnsmasq-shared.d/tarasec-status-client.conf <<EOF
address=/status.client/$HOTSPOT_IP
EOF
    chmod 0644 /etc/NetworkManager/dnsmasq-shared.d/tarasec-status-client.conf

    # openNDS normally writes this directive to /etc/dnsmasq.conf. A
    # NetworkManager shared hotspot runs its own dnsmasq and reads this
    # directory instead, so install the nftset mapping where that instance
    # can see it.
    WALLEDGARDEN_DNSMASQ=/etc/NetworkManager/dnsmasq-shared.d/tarasec-walledgarden.conf
    WALLEDGARDEN_DNSMASQ_CHANGED=0
    WALLEDGARDEN_DNSMASQ_TMP="$(mktemp)"
    cat > "$WALLEDGARDEN_DNSMASQ_TMP" <<'EOF'
nftset=/tarasec.org/accounts.google.com/oauth2.googleapis.com/www.googleapis.com/ssl.gstatic.com/accounts.googleusercontent.com/4#ip#nds_filter#walledgarden
EOF
    if [ ! -f "$WALLEDGARDEN_DNSMASQ" ] || ! cmp -s "$WALLEDGARDEN_DNSMASQ_TMP" "$WALLEDGARDEN_DNSMASQ"; then
        install -m 0644 "$WALLEDGARDEN_DNSMASQ_TMP" "$WALLEDGARDEN_DNSMASQ"
        WALLEDGARDEN_DNSMASQ_CHANGED=1
    fi
    rm -f "$WALLEDGARDEN_DNSMASQ_TMP"

    if [ -f /etc/config/opennds ] && [ ! -f /etc/config/opennds.tarasec-before ]; then
        cp -a /etc/config/opennds /etc/config/opennds.tarasec-before
    fi
    if [ -f /etc/opennds/opennds.conf ] && [ ! -f /etc/opennds/opennds.conf.tarasec-before ]; then
        cp -a /etc/opennds/opennds.conf /etc/opennds/opennds.conf.tarasec-before
    fi

    LEASE_FILE="/var/lib/NetworkManager/dnsmasq-${HOTSPOT_IF}.leases"
    cat > /etc/config/opennds <<EOF
config opennds 'opennds'
    option enabled '1'
    option gatewayinterface '$HOTSPOT_IF'
    option gatewayname '$HOTSPOT_NAME'
    option login_option_enabled '3'
    option themespec_path '/usr/lib/opennds/theme_tarasec.sh'
    option dhcp_leases_file '/tmp/dhcp.leases'
    list users_to_router 'allow udp port 53'
    list users_to_router 'allow tcp port 53'
    list users_to_router 'allow udp port 67'
    list users_to_router 'allow tcp port 8080'
    # Permit only the HTTPS endpoints needed to bootstrap global sign-in.
    # Port 80 remains captive so operating-system portal detection still works.
    list walledgarden_fqdn_list 'tarasec.org accounts.google.com oauth2.googleapis.com www.googleapis.com ssl.gstatic.com accounts.googleusercontent.com'
    list walledgarden_port_list '443'
EOF

    cat > /etc/opennds/opennds.conf <<EOF
GatewayName $HOTSPOT_NAME
GatewayInterface $HOTSPOT_IF
FirewallRuleSet authenticated-users {
}
FirewallRuleSet preauthenticated-users {
}
FirewallRuleSet users-to-router {
    FirewallRule allow udp port 53
    FirewallRule allow tcp port 53
    FirewallRule allow udp port 67
    FirewallRule allow tcp port 8080
}
walledgarden_fqdn_list tarasec.org accounts.google.com oauth2.googleapis.com www.googleapis.com ssl.gstatic.com accounts.googleusercontent.com
walledgarden_port_list 443
EOF

    # dnsmasq reads nftset directives only when its process starts. Cycle only
    # the hotspot connection, and only when the generated mapping changed.
    # Existing Wi-Fi clients must reconnect after this one-time configuration.
    if [ "$WALLEDGARDEN_DNSMASQ_CHANGED" -eq 1 ]; then
        HOTSPOT_CONNECTION="$(nmcli -g GENERAL.CONNECTION device show "$HOTSPOT_IF" 2>/dev/null | head -1)"
        if [ -z "$HOTSPOT_CONNECTION" ] || [ "$HOTSPOT_CONNECTION" = "--" ]; then
            echo "ERROR: unable to identify the active NetworkManager hotspot connection for $HOTSPOT_IF." >&2
            exit 1
        fi
        echo "Restarting hotspot DNS to activate TaraSec identity bootstrap..."
        nmcli connection down "$HOTSPOT_CONNECTION"
        nmcli connection up "$HOTSPOT_CONNECTION"
        for _ in $(seq 1 20); do
            if ip -4 addr show dev "$HOTSPOT_IF" 2>/dev/null | grep -Eq "[[:space:]]inet[[:space:]]+$HOTSPOT_IP/"; then
                break
            fi
            sleep 1
        done
        if ! ip -4 addr show dev "$HOTSPOT_IF" 2>/dev/null | grep -Eq "[[:space:]]inet[[:space:]]+$HOTSPOT_IP/"; then
            echo "ERROR: hotspot $HOTSPOT_IF did not recover $HOTSPOT_IP after DNS restart." >&2
            exit 1
        fi
    fi

    # openNDS 10.1.0 on generic Linux documents dhcp_leases_file but its
    # libopennds.sh dhcp_check() helper only searches hard-coded lease paths.
    # Bridge NetworkManager's live hotspot lease database to the first path
    # the helper actually checks. Do not overwrite an unrelated DHCP database.
    cat > /etc/tarasec/opennds-dhcp-compat.conf <<EOF
LEASE_FILE=$LEASE_FILE
HOTSPOT_IF=$HOTSPOT_IF
EOF
    chmod 0644 /etc/tarasec/opennds-dhcp-compat.conf

    cat > /usr/local/sbin/tarasec-opennds-dhcp-compat <<'EOF'
#!/bin/bash
set -euo pipefail
CONF=/etc/tarasec/opennds-dhcp-compat.conf
TARGET=/tmp/dhcp.leases

[ -r "$CONF" ] || { echo "Missing $CONF" >&2; exit 1; }
. "$CONF"
[ -n "${LEASE_FILE:-}" ] || { echo "LEASE_FILE missing from $CONF" >&2; exit 1; }
[ -n "${HOTSPOT_IF:-}" ] || { echo "HOTSPOT_IF missing from $CONF" >&2; exit 1; }

sync_leases() {
    local tmp expiry
    tmp="$(mktemp /tmp/dhcp.leases.XXXXXX)"
    expiry="$(( $(date +%s) + 3600 ))"

    # NetworkManager keeps its shared-mode lease file below a root-only
    # directory on some distributions. Copy the contents instead of exposing
    # an unreadable symlink to openNDS.
    if [ -r "$LEASE_FILE" ]; then
        cat "$LEASE_FILE" > "$tmp"
    fi

    # Phones commonly retain a still-valid DHCP address while NetworkManager
    # recreates an empty lease file. A reachable neighbour is sufficient for
    # openNDS to admit the device to the portal; authentication is still
    # required before forwarding is allowed.
    ip neigh show dev "$HOTSPOT_IF" 2>/dev/null |
        awk -v expiry="$expiry" '
            $0 ~ /lladdr/ && $0 !~ /FAILED|INCOMPLETE/ {
                for (i = 1; i <= NF; i++) {
                    if ($i == "lladdr" && (i + 1) <= NF) {
                        print expiry, $(i + 1), $1, "*", "01:" $(i + 1)
                    }
                }
            }
        ' >> "$tmp"

    awk '!seen[$3]++' "$tmp" > "${tmp}.unique"
    chmod 0644 "${tmp}.unique"
    mv -f "${tmp}.unique" "$TARGET"
    rm -f "$tmp"
}

while :; do
    sync_leases
    sleep 2
done
EOF
    chmod 0755 /usr/local/sbin/tarasec-opennds-dhcp-compat

    cat > /etc/systemd/system/tarasec-opennds-dhcp-compat.service <<'EOF'
[Unit]
Description=TaraSec openNDS NetworkManager DHCP lease compatibility
After=NetworkManager.service network-online.target
Wants=NetworkManager.service
Before=opennds.service

[Service]
Type=simple
ExecStart=/usr/local/sbin/tarasec-opennds-dhcp-compat
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
EOF

    systemctl daemon-reload || true
    systemctl enable tarasec-opennds-dhcp-compat.service >/dev/null
    systemctl restart tarasec-opennds-dhcp-compat.service

    # systemctl restart returns after the service process is launched, before
    # its first two-second synchronization cycle necessarily creates TARGET.
    # Wait briefly so slower Raspberry Pi storage is not reported as failure.
    for _ in $(seq 1 20); do
        if [ -f /tmp/dhcp.leases ] && [ -r /tmp/dhcp.leases ]; then
            break
        fi
        if ! systemctl is-active --quiet tarasec-opennds-dhcp-compat.service; then
            break
        fi
        sleep 0.25
    done
    if [ ! -f /tmp/dhcp.leases ] || [ ! -r /tmp/dhcp.leases ]; then
        echo "ERROR: openNDS DHCP compatibility lease mapping was not created." >&2
        systemctl status tarasec-opennds-dhcp-compat.service --no-pager >&2 || true
        journalctl -u tarasec-opennds-dhcp-compat.service -n 40 --no-pager >&2 || true
        exit 1
    fi

    cat > /etc/tarasec/hotspot-dns.conf <<EOF
HOTSPOT_IF=$HOTSPOT_IF
HOTSPOT_IP=$HOTSPOT_IP
EOF
    chmod 0644 /etc/tarasec/hotspot-dns.conf

    cat > /usr/local/sbin/tarasec-hotspot-dns-redirect <<'EOF'
#!/bin/bash
set -euo pipefail

CONF=/etc/tarasec/hotspot-dns.conf
TABLE=tarasec_hotspot_dns

if [ "${1:-start}" = "stop" ]; then
    nft delete table ip "$TABLE" 2>/dev/null || true
    exit 0
fi

if [ ! -r "$CONF" ]; then
    echo "Missing $CONF" >&2
    exit 1
fi
. "$CONF"

if [ -z "${HOTSPOT_IF:-}" ] || [ -z "${HOTSPOT_IP:-}" ]; then
    echo "HOTSPOT_IF/HOTSPOT_IP missing from $CONF" >&2
    exit 1
fi

if ! ip -4 addr show dev "$HOTSPOT_IF" 2>/dev/null | grep -Eq "[[:space:]]inet[[:space:]]+$HOTSPOT_IP/"; then
    echo "$HOTSPOT_IF does not own $HOTSPOT_IP" >&2
    exit 1
fi

if ! ss -lnut | awk -v ip="$HOTSPOT_IP" '$5 == ip ":53" {found=1} END {exit !found}'; then
    echo "No local DNS listener on $HOTSPOT_IP:53" >&2
    exit 1
fi

nft delete table ip "$TABLE" 2>/dev/null || true
nft add table ip "$TABLE"
nft "add chain ip $TABLE prerouting { type nat hook prerouting priority -110; policy accept; }"
nft add rule ip "$TABLE" prerouting iifname "$HOTSPOT_IF" udp dport 53 dnat to "$HOTSPOT_IP:53"
nft add rule ip "$TABLE" prerouting iifname "$HOTSPOT_IF" tcp dport 53 dnat to "$HOTSPOT_IP:53"
EOF
    chmod 0755 /usr/local/sbin/tarasec-hotspot-dns-redirect

    cat > /etc/systemd/system/tarasec-hotspot-dns.service <<'EOF'
[Unit]
Description=TaraSec hotspot DNS interception
After=NetworkManager.service network-online.target
Wants=NetworkManager.service
Before=opennds.service

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/tarasec-hotspot-dns-redirect start
ExecStop=/usr/local/sbin/tarasec-hotspot-dns-redirect stop
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
EOF

    systemctl daemon-reload || true
    systemctl enable tarasec-hotspot-dns.service >/dev/null
    systemctl restart tarasec-hotspot-dns.service

    if ! nft list table ip tarasec_hotspot_dns >/dev/null 2>&1; then
        echo "ERROR: TaraSec hotspot DNS interception table was not created." >&2
        exit 1
    fi

    if ! command -v python3 >/dev/null 2>&1; then
        echo "ERROR: python3 is required for the captive DNS acceptance test." >&2
        exit 1
    fi
    if ! TARASEC_EXPECTED_DNS_IP="$HOTSPOT_IP" python3 "$REPO_ROOT/misc/check_hotspot_dns.py" "$HOTSPOT_IP" status.client; then
        echo "ERROR: status.client does not resolve to $HOTSPOT_IP through the hotspot DNS server." >&2
        echo "The NetworkManager hotspot must be restarted after installing /etc/NetworkManager/dnsmasq-shared.d/tarasec-status-client.conf." >&2
        exit 1
    fi

    systemctl enable opennds 2>/dev/null || true
    systemctl stop opennds || true
    for _ in $(seq 1 20); do
        if ! pgrep -x opennds >/dev/null 2>&1; then
            break
        fi
        sleep 1
    done
    if pgrep -x opennds >/dev/null 2>&1; then
        echo "ERROR: previous openNDS process did not exit within 20 seconds." >&2
        exit 1
    fi
    systemctl start opennds || true

    NDS_READY=0
    for _ in $(seq 1 45); do
        if systemctl is-active --quiet opennds && ndsctl status >/dev/null 2>&1; then
            NDS_READY=1
            break
        fi
        sleep 1
    done
    if [ "$NDS_READY" -ne 1 ]; then
        echo "ERROR: openNDS did not become ready after TaraSec portal configuration." >&2
        journalctl -u opennds -n 60 --no-pager >&2 || true
        exit 1
    fi

    grep -q "option login_option_enabled '3'" /etc/config/opennds
    grep -q "option themespec_path '/usr/lib/opennds/theme_tarasec.sh'" /etc/config/opennds
    grep -q "list users_to_router 'allow tcp port 8080'" /etc/config/opennds
    grep -Fqx "    list walledgarden_fqdn_list 'tarasec.org accounts.google.com oauth2.googleapis.com www.googleapis.com ssl.gstatic.com accounts.googleusercontent.com'" /etc/config/opennds
    grep -Fqx "    list walledgarden_port_list '443'" /etc/config/opennds
    grep -q 'FirewallRule allow tcp port 8080' /etc/opennds/opennds.conf
    grep -Fqx 'walledgarden_fqdn_list tarasec.org accounts.google.com oauth2.googleapis.com www.googleapis.com ssl.gstatic.com accounts.googleusercontent.com' /etc/opennds/opennds.conf
    grep -Fqx 'walledgarden_port_list 443' /etc/opennds/opennds.conf
    grep -Fqx 'nftset=/tarasec.org/accounts.google.com/oauth2.googleapis.com/www.googleapis.com/ssl.gstatic.com/accounts.googleusercontent.com/4#ip#nds_filter#walledgarden' "$WALLEDGARDEN_DNSMASQ"
    ss -lnt | grep -q ':8080 '

    # Resolve through the hotspot dnsmasq to populate the dynamic nft set,
    # then require at least one address. A configured but empty set blocks
    # exactly the sign-in bootstrap this installation is meant to provide.
    TARASEC_PUBLIC_IP="$(getent ahostsv4 tarasec.org | awk 'NR == 1 {print $1}')"
    if [ -z "$TARASEC_PUBLIC_IP" ]; then
        echo "ERROR: unable to resolve tarasec.org for the walled-garden self-test." >&2
        exit 1
    fi
    TARASEC_EXPECTED_DNS_IP="$TARASEC_PUBLIC_IP" python3 "$REPO_ROOT/misc/check_hotspot_dns.py" "$HOTSPOT_IP" tarasec.org
    sleep 1
    if ! nft list set ip nds_filter walledgarden 2>/dev/null | grep -q 'elements = {'; then
        # The generated file can already be correct while NetworkManager's
        # long-running shared-mode dnsmasq predates it. Reload the hotspot and
        # rebuild openNDS once before treating an empty set as fatal.
        echo "Reloading hotspot DNS because the openNDS walled-garden set is empty..."
        HOTSPOT_CONNECTION="$(nmcli -g GENERAL.CONNECTION device show "$HOTSPOT_IF" 2>/dev/null | head -1)"
        if [ -z "$HOTSPOT_CONNECTION" ] || [ "$HOTSPOT_CONNECTION" = "--" ]; then
            echo "ERROR: unable to identify the active NetworkManager hotspot connection for $HOTSPOT_IF." >&2
            exit 1
        fi

        systemctl stop opennds || true
        nmcli connection down "$HOTSPOT_CONNECTION"
        nmcli connection up "$HOTSPOT_CONNECTION"
        for _ in $(seq 1 30); do
            if ip -4 addr show dev "$HOTSPOT_IF" 2>/dev/null | grep -Eq "[[:space:]]inet[[:space:]]+$HOTSPOT_IP/" &&
               ss -lnut | awk -v ip="$HOTSPOT_IP" '$5 == ip ":53" {found=1} END {exit !found}'; then
                break
            fi
            sleep 1
        done
        if ! ip -4 addr show dev "$HOTSPOT_IF" 2>/dev/null | grep -Eq "[[:space:]]inet[[:space:]]+$HOTSPOT_IP/"; then
            echo "ERROR: hotspot $HOTSPOT_IF did not recover $HOTSPOT_IP after DNS reload." >&2
            exit 1
        fi

        systemctl restart tarasec-opennds-dhcp-compat.service
        systemctl restart tarasec-hotspot-dns.service
        systemctl start opennds || true
        NDS_READY=0
        for _ in $(seq 1 45); do
            if systemctl is-active --quiet opennds && ndsctl status >/dev/null 2>&1; then
                NDS_READY=1
                break
            fi
            sleep 1
        done
        if [ "$NDS_READY" -ne 1 ]; then
            echo "ERROR: openNDS did not recover after reloading hotspot DNS." >&2
            journalctl -u opennds -n 60 --no-pager >&2 || true
            exit 1
        fi

        TARASEC_EXPECTED_DNS_IP="$TARASEC_PUBLIC_IP" python3 "$REPO_ROOT/misc/check_hotspot_dns.py" "$HOTSPOT_IP" tarasec.org
        sleep 1
    fi
    if ! nft list set ip nds_filter walledgarden 2>/dev/null | grep -q 'elements = {'; then
        echo "ERROR: openNDS walled-garden set is still empty after reloading hotspot DNS." >&2
        echo "NetworkManager dnsmasq did not load $WALLEDGARDEN_DNSMASQ." >&2
        nft list set ip nds_filter walledgarden >&2 || true
        exit 1
    fi

    # Capture the complete status before matching it. With `set -o pipefail`,
    # `grep -q` can close a live ndsctl pipe after the first match, causing
    # ndsctl to receive SIGPIPE and making a valid status check fail.
    NDS_STATUS="$(ndsctl status 2>/dev/null || true)"
    if ! grep -q "$HOTSPOT_IF" <<<"$NDS_STATUS"; then
        echo "ERROR: openNDS is running but its status does not reference hotspot interface $HOTSPOT_IF." >&2
        printf '%s\n' "$NDS_STATUS" >&2
        exit 1
    fi
    if ! ip -4 addr show dev "$HOTSPOT_IF" | grep -Eq "[[:space:]]inet[[:space:]]+$HOTSPOT_IP/"; then
        echo "ERROR: hotspot interface $HOTSPOT_IF no longer owns $HOTSPOT_IP." >&2
        ip -4 addr show dev "$HOTSPOT_IF" >&2 || true
        exit 1
    fi
    # openNDS 10.1.0 may expose its HTTP listener as 0.0.0.0:2050 even
    # though the daemon is configured for and enforcing on HOTSPOT_IF. Accept
    # either a hotspot-IP-specific bind or an IPv4 wildcard bind on port 2050.
    if ! ss -lnt | awk -v ip="$HOTSPOT_IP" '
        $4 == ip ":2050" || $4 == "0.0.0.0:2050" || $4 == "*:2050" {found=1}
        END {exit !found}
    '; then
        echo "ERROR: openNDS has no usable TCP 2050 listener for hotspot $HOTSPOT_IF ($HOTSPOT_IP)." >&2
        ss -lntp >&2 || true
        exit 1
    fi

    echo "TaraSec captive portal configured:"
    echo "  interface: $HOTSPOT_IF"
    echo "  client network: $HOTSPOT_CIDR"
    echo "  ThemeSpec: /usr/lib/opennds/theme_tarasec.sh"
    echo "  TaraSec login: http://$HOTSPOT_IP:8080/hotspot/portal_login.php"
    echo "  DHCP validation: NetworkManager leases exposed to openNDS"
    echo "  DNS interception: client TCP/UDP 53 -> $HOTSPOT_IP:53"
    echo "  Portal DNS: status.client -> $HOTSPOT_IP"
else
    echo "openNDS and TaraSec portal assets are installed, but captive enforcement activation is deferred until the hotspot interface is up."
fi

echo "openNDS/TaraSec captive portal installation complete."
