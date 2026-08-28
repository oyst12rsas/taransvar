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

echo "Installing TaraSec captive-portal assets..."
install -m 0755 "$REPO_ROOT/hotspot/opennds/theme_tarasec.sh" /usr/lib/opennds/theme_tarasec.sh
install -m 0755 "$REPO_ROOT/hotspot/opennds/access_policy.pl" /usr/lib/opennds/access_policy.pl
install -m 0755 "$REPO_ROOT/hotspot/opennds/custombinauth.sh" /usr/lib/opennds/custombinauth.sh
install -m 0755 "$REPO_ROOT/hotspot/opennds/tarasec-access-check" /usr/local/sbin/tarasec-access-check
install -m 0755 "$REPO_ROOT/hotspot/opennds/tarasec-subscriber-logout" /usr/local/sbin/tarasec-subscriber-logout

# The captive PHP endpoints depend on the existing hotspot PHP library files.
mkdir -p /var/www/html/hotspot
cp -a "$REPO_ROOT/html/hotspot/." /var/www/html/hotspot/
chown -R root:root /var/www/html/hotspot
find /var/www/html/hotspot -type d -exec chmod 0755 {} +
find /var/www/html/hotspot -type f -exec chmod 0644 {} +

# openNDS runs as root and may use this credentials file for the access-table
# helper. These are the existing TaraSec application DB credentials created by
# createUsers.pl and verified by hotspot/distro/install.sh.
mkdir -p /etc/tarasec
cat > /etc/tarasec/access-mysql.cnf <<'EOF'
[client]
user=scriptUsrAces3f3
password=rErte8Oi98e-2_#
host=localhost
EOF
chmod 0600 /etc/tarasec/access-mysql.cnf

# portal_status.php needs exactly one privileged operation: close the calling
# subscriber's TaraSec/openNDS session. Do not grant www-data general ndsctl or
# shell privileges.
if ! command -v sudo >/dev/null 2>&1; then
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y sudo
fi
cat > /etc/sudoers.d/tarasec-hotspot-logout <<'EOF'
www-data ALL=(root) NOPASSWD: /usr/local/sbin/tarasec-subscriber-logout *
EOF
chmod 0440 /etc/sudoers.d/tarasec-hotspot-logout
visudo -cf /etc/sudoers.d/tarasec-hotspot-logout >/dev/null

# Determine the local /24 exposed by the captive-login Apache vhost. TaraSec's
# current hotspot setup uses /24 client networks; retain 192.168.50.0/24 as the
# safe default if a non-/24 address is supplied.
HOTSPOT_IP="${HOTSPOT_ADDR%/*}"
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
systemctl restart apache2

# If no interface was supplied, try to preserve/use an already configured one.
if [ -z "$HOTSPOT_IF" ] && [ -r /etc/config/opennds ]; then
    HOTSPOT_IF="$(sed -n "s/^[[:space:]]*option[[:space:]]\+gatewayinterface[[:space:]]\+'\([^']*\)'.*/\1/p" /etc/config/opennds | head -1)"
fi

if [ -n "$HOTSPOT_IF" ]; then
    echo "Configuring openNDS TaraSec ThemeSpec on $HOTSPOT_IF..."
    mkdir -p /etc/config /etc/opennds
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
    option dhcp_leases_file '$LEASE_FILE'
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
EOF

    systemctl daemon-reload || true
    systemctl enable opennds 2>/dev/null || true
    systemctl restart opennds

    sleep 2
    if ! systemctl is-active --quiet opennds; then
        echo "ERROR: openNDS did not remain active after TaraSec portal configuration." >&2
        journalctl -u opennds -n 40 --no-pager >&2 || true
        exit 1
    fi

    grep -q "option login_option_enabled '3'" /etc/config/opennds
    grep -q "option themespec_path '/usr/lib/opennds/theme_tarasec.sh'" /etc/config/opennds
    grep -q 'FirewallRule allow tcp port 8080' /etc/opennds/opennds.conf
    ss -lnt | grep -q ':8080 '
    ss -lnt | grep -q ':2050 '

    echo "TaraSec captive portal configured:"
    echo "  interface: $HOTSPOT_IF"
    echo "  client network: $HOTSPOT_CIDR"
    echo "  ThemeSpec: /usr/lib/opennds/theme_tarasec.sh"
    echo "  TaraSec login: http://$HOTSPOT_IP:8080/hotspot/portal_login.php"
else
    echo "openNDS and TaraSec portal assets are installed, but no hotspot interface is configured yet."
    echo "Run setupWifiNicAsHotspot.pl (or rerun this helper with TARASEC_HOTSPOT_IF set) to activate captive enforcement."
fi

echo "openNDS/TaraSec captive portal installation complete."
