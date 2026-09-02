#!/bin/bash
set -e

press_enter()
{
    echo -en "\nPress Enter to continue"
    read line
}

if [ "$(id -u)" -ne 0 ]; then
    echo 'This script must be run by root. Start with: sudo bash install.sh' >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOTSPOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
REPO_ROOT="$(cd "$HOTSPOT_DIR/.." && pwd)"
cd "$HOTSPOT_DIR"

echo "=== TaraSec hotspot preflight ==="
if [ ! -r /etc/os-release ]; then
    echo "ERROR: Cannot identify this Linux installation." >&2
    exit 1
fi
. /etc/os-release
echo "OS: ${PRETTY_NAME:-$ID}"

if [ "${ID:-}" != "ubuntu" ] && [ "${ID:-}" != "debian" ] && [ "${ID:-}" != "raspbian" ] && [ "${ID_LIKE:-}" != *debian* ]; then
    echo "ERROR: This installer currently supports Ubuntu/Debian/Raspberry Pi OS family systems." >&2
    exit 1
fi

if ! command -v apt-get >/dev/null 2>&1; then
    echo "ERROR: apt-get is required by this installer." >&2
    exit 1
fi

echo
echo "Checking/installing TaraSec prerequisites..."
apt-get update

has_apt_candidate()
{
    local pkg="$1"
    local candidate
    candidate="$(apt-cache policy "$pkg" 2>/dev/null | awk '/Candidate:/ {print $2; exit}')"
    [ -n "$candidate" ] && [ "$candidate" != "(none)" ]
}

DB_SERVER_PKG=""
DB_CLIENT_PKG=""
if has_apt_candidate mysql-server && has_apt_candidate mysql-client; then
    DB_SERVER_PKG="mysql-server"
    DB_CLIENT_PKG="mysql-client"
elif has_apt_candidate mariadb-server && has_apt_candidate mariadb-client; then
    DB_SERVER_PKG="mariadb-server"
    DB_CLIENT_PKG="mariadb-client"
else
    echo "ERROR: Neither MySQL nor MariaDB server/client packages have an installable candidate in the configured apt repositories." >&2
    echo "mysql-server candidate: $(apt-cache policy mysql-server 2>/dev/null | awk '/Candidate:/ {print $2; exit}')" >&2
    echo "mysql-client candidate: $(apt-cache policy mysql-client 2>/dev/null | awk '/Candidate:/ {print $2; exit}')" >&2
    echo "mariadb-server candidate: $(apt-cache policy mariadb-server 2>/dev/null | awk '/Candidate:/ {print $2; exit}')" >&2
    echo "mariadb-client candidate: $(apt-cache policy mariadb-client 2>/dev/null | awk '/Candidate:/ {print $2; exit}')" >&2
    exit 1
fi

echo "Database packages: $DB_SERVER_PKG $DB_CLIENT_PKG"
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    ca-certificates curl git perl python3 gnupg \
    network-manager iw rfkill iproute2 \
    apache2 "$DB_SERVER_PKG" "$DB_CLIENT_PKG" \
    php libapache2-mod-php php-mysql php-curl \
    cron \
    libdbi-perl libdbd-mysql-perl \
    freeradius freeradius-mysql

if apt-cache show ipfm >/dev/null 2>&1; then
    echo "Installing optional legacy IPFM accounting package..."
    DEBIAN_FRONTEND=noninteractive apt-get install -y ipfm
else
    echo "IPFM is not available on this OS; legacy IPFM accounting will be skipped."
fi

required_commands=(curl git perl python3 nmcli iw rfkill ip systemctl apache2 mysql)
missing=0
for cmd in "${required_commands[@]}"; do
    if command -v "$cmd" >/dev/null 2>&1; then
        printf "  %-12s OK\n" "$cmd"
    else
        printf "  %-12s MISSING\n" "$cmd"
        missing=1
    fi
done
if [ "$missing" -ne 0 ]; then
    echo "ERROR: One or more required commands are still missing after package installation." >&2
    exit 1
fi

systemctl enable --now NetworkManager
systemctl enable --now apache2
if systemctl list-unit-files --no-legend mysql.service 2>/dev/null | grep -q .; then
    DB_SERVICE="mysql"
elif systemctl list-unit-files --no-legend mariadb.service 2>/dev/null | grep -q .; then
    DB_SERVICE="mariadb"
else
    echo "ERROR: Installed database server has no mysql.service or mariadb.service unit." >&2
    exit 1
fi
systemctl enable --now "$DB_SERVICE"
systemctl enable --now cron

WAN_IF="$(ip -4 route show default | awk 'NR==1 {print $5}')"
if [ -z "$WAN_IF" ]; then
    echo "ERROR: No IPv4 default route found. Connect this computer to the Internet first." >&2
    exit 1
fi
if [[ "$WAN_IF" =~ ^(wt[0-9]+|netbird[0-9]*)$ ]]; then
    echo "ERROR: NetBird cannot be the hotspot's normal WAN/default route." >&2
    exit 1
fi
echo "WAN/uplink detected: $WAN_IF (will be preserved)"

if ! curl -fsS --connect-timeout 5 --max-time 10 https://tarasec.org/ >/dev/null; then
    echo "ERROR: Internet/TaraSec HTTPS connectivity test failed. The WAN will not be modified." >&2
    exit 1
fi
echo "Internet/TaraSec HTTPS: OK"

echo
echo "Please wait while installing the hotspot application..."

mkdir -p /root/wifi/perl /root/wifi/log /root/wifi/temp /root/wifi/distro
mkdir -p /root/setup/log
if command -v ipfm >/dev/null 2>&1; then
    mkdir -p /var/log/ipfm/subnet/daily/archived
    mkdir -p /var/log/ipfm/subnet/hourly/archived
    mkdir -p /var/log/ipfm/subnet/minute/archived
    mkdir -p /var/log/ipfm/individual/archived
fi

mkdir -p /var/www/html/temp
chown www-data:www-data /var/www/html/temp
chown www-data:www-data /var/www/html/temp/* 2>/dev/null || true

cp distro/copythese/*.sql /root/wifi/distro
cp perl/* /root/wifi/perl
if command -v ipfm >/dev/null 2>&1; then
    cp distro/copythese/ipfm.conf /etc
fi
cp distro/copythese/startup.conf /etc/init
cp distro/copythese/taransvar.service /etc/systemd/system

mkdir -p /var/spool/cron/crontabs
touch /var/spool/cron/crontabs/root
if ! grep -q sleepingbeauty /var/spool/cron/crontabs/root 2>/dev/null; then
    printf "\n* * * * * perl /root/wifi/perl/sleepingbeauty.pl > /root/wifi/log/sleeping.txt\n" >> /var/spool/cron/crontabs/root
    chmod 0600 /var/spool/cron/crontabs/root
    printf "sleepingbeauty put in crontab\n"
else
    printf "sleepingbeauty was already in crontab\n"
fi

cp distro/copythese/*.gpg /root/wifi/temp

systemctl daemon-reload
systemctl enable taransvar
systemctl start taransvar
usermod -a -G adm www-data

file="/var/www/html/index.html"
if [ -f "$file" ]; then rm "$file"; fi

a2enmod cgi
cp distro/copythese/debugserver /usr/lib/cgi-bin
chmod 705 /usr/lib/cgi-bin/*
sysctl -w net.ipv4.ip_forward=1

echo "Configuring Apache CGI support..."
if ! grep -q '^<Directory /usr/lib/cgi-bin>$' /etc/apache2/apache2.conf; then
    cat >> /etc/apache2/apache2.conf <<'EOF'
<Directory /usr/lib/cgi-bin>
  Options +ExecCGI
</Directory>

AddHandler cgi-script .cgi .pl
EOF
fi

sed -i "s/Options Indexes FollowSymLinks/Options FollowSymLinks/" /etc/apache2/apache2.conf
systemctl restart apache2
systemctl restart "$DB_SERVICE"

echo
echo "Checking TaraSec database bootstrap..."
mysql -e "CREATE DATABASE IF NOT EXISTS taransvar;"

SCHEMA_PRESENT="$(mysql -N -s -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='taransvar' AND table_name='hotspotSetup';")"
if [ "${SCHEMA_PRESENT:-0}" = "0" ]; then
    echo "Fresh TaraSec database detected; importing base schema..."
    mysql taransvar < "$REPO_ROOT/db/taransvar.sql"
    if [ -s "$REPO_ROOT/db/postcreate.sql" ]; then
        mysql taransvar < "$REPO_ROOT/db/postcreate.sql"
    fi
else
    echo "Existing TaraSec schema detected; leaving data intact."
fi

(
    cd "$REPO_ROOT/misc"
    perl createUsers.pl
)

if ! mysql -uscriptUsrAces3f3 -prErte8Oi98e-2_# -N -s taransvar -e "SELECT 1;" >/dev/null 2>&1; then
    echo "ERROR: TaraSec application database account could not connect after bootstrap." >&2
    exit 1
fi
echo "TaraSec database/application account: OK"

( cd perl && perl install.pl )
service cron reload

RADIUS_ROOT=""
for d in /etc/freeradius /etc/freeradius/*; do
    if [ -d "$d/sites-enabled" ]; then
        RADIUS_ROOT="$d"
        break
    fi
done

if [ -n "$RADIUS_ROOT" ]; then
    echo "Configuring FreeRADIUS in $RADIUS_ROOT..."
    mkdir -p "$RADIUS_ROOT/sites-enabled"
    if [ -e "$RADIUS_ROOT/sites-enabled/default" ] && [ ! -e "$RADIUS_ROOT/sites-enabled/default.old" ]; then
        mv "$RADIUS_ROOT/sites-enabled/default" "$RADIUS_ROOT/sites-enabled/default.old"
    fi
    cp distro/copythese/radiusdefault "$RADIUS_ROOT/sites-enabled/default"

    if [ -e "$RADIUS_ROOT/radiusd.conf" ]; then
        sed -i 's/#$INCLUDE sql.conf/$INCLUDE sql.conf/' "$RADIUS_ROOT/radiusd.conf" || true
    fi
else
    echo "WARNING: FreeRADIUS is installed but no sites-enabled directory was found; skipping legacy TaraSec radiusdefault copy."
fi

echo "Checking sleepingbeauty worker (non-blocking)..."
if pgrep -f 'perl .*sleepingbeauty\.pl' >/dev/null 2>&1; then
    echo "sleepingbeauty is running."
else
    echo "sleepingbeauty is not running yet; continuing installation. Cron will start it on its next scheduled run."
fi

echo
echo "Installing and verifying openNDS captive portal..."
bash "$REPO_ROOT/misc/install_opennds.sh"

echo
echo "Installing and enrolling TaraSec NetBird management..."
bash "$REPO_ROOT/misc/install_netbird_management.sh"

# Running this hotspot-specific installer is itself explicit consent to enable
# Wi-Fi. The general TaraSec installer asks that question before invoking us.
CREATE_TEST_ACCOUNT=0
TEST_QUOTA_MB=""
echo
read -p "Create a test Wi-Fi access account? [y/N]: " TEST_ACCOUNT_ANSWER
case "$TEST_ACCOUNT_ANSWER" in
    [yY][eE][sS]|[yY])
        CREATE_TEST_ACCOUNT=1
        while true; do
            read -p "Test account quota (for example 500 MB, 2 GB, 10 GB) [10 GB]: " TEST_QUOTA_INPUT
            TEST_QUOTA_INPUT="${TEST_QUOTA_INPUT:-10 GB}"
            TEST_QUOTA_INPUT="$(printf '%s' "$TEST_QUOTA_INPUT" | tr '[:lower:]' '[:upper:]' | tr -d ' ')"
            if [[ "$TEST_QUOTA_INPUT" =~ ^([0-9]+)MB$ ]]; then
                TEST_QUOTA_MB="${BASH_REMATCH[1]}"
            elif [[ "$TEST_QUOTA_INPUT" =~ ^([0-9]+)GB$ ]]; then
                TEST_QUOTA_MB="$(( ${BASH_REMATCH[1]} * 1024 ))"
            elif [[ "$TEST_QUOTA_INPUT" =~ ^[0-9]+$ ]]; then
                TEST_QUOTA_MB="$TEST_QUOTA_INPUT"
            else
                echo "Please enter a quota such as 500 MB, 2 GB or 10240."
                continue
            fi
            if [ "$TEST_QUOTA_MB" -le 0 ]; then
                echo "Quota must be greater than zero."
                continue
            fi
            break
        done
        ;;
esac

echo
echo "Configuring TaraSec Wi-Fi hotspot..."
ADDR="${TARASEC_HOTSPOT_ADDR:-192.168.50.1/24}"
perl "$REPO_ROOT/misc/setupWifiNicAsHotspot.pl" \
    "${TARASEC_HOTSPOT_IF:-}" "${TARASEC_HOTSPOT_SSID:-}" "$ADDR"

# The core TaraSec firewall may have been applied before the hotspot interface
# and /etc/tarasec/hotspot-dns.conf existed. Reapply it now so a default-DROP
# host can receive client DHCP/DNS and captive-portal traffic. Restart openNDS
# afterwards because the firewall reload flushes its filter chains.
if [ -f /etc/tarasecfw.conf ]; then
    echo
    echo "Reapplying TaraSec firewall with hotspot client allowances..."
    bash "$REPO_ROOT/misc/firewall.sh"
    systemctl restart opennds
    for _ in $(seq 1 45); do
        if systemctl is-active --quiet opennds && ndsctl status >/dev/null 2>&1; then
            break
        fi
        sleep 1
    done
    if ! systemctl is-active --quiet opennds; then
        echo "ERROR: openNDS did not recover after the TaraSec firewall reload." >&2
        journalctl -u opennds -n 60 --no-pager >&2 || true
        exit 1
    fi

    # shellcheck disable=SC1091
    source /etc/tarasec/hotspot-dns.conf
    iptables -C INPUT -i "$HOTSPOT_IF" -p udp --dport 53 -j ACCEPT
    iptables -C INPUT -i "$HOTSPOT_IF" -p tcp --dport 53 -j ACCEPT
    iptables -C INPUT -i "$HOTSPOT_IF" -p udp --dport 67 -j ACCEPT
    iptables -C INPUT -i "$HOTSPOT_IF" -p tcp --dport 2050 -j ACCEPT
    iptables -C INPUT -i "$HOTSPOT_IF" -p tcp --dport 8080 -j ACCEPT
    echo "TaraSec firewall permits hotspot DHCP, DNS and captive-portal traffic on $HOTSPOT_IF."
fi

if [ "$CREATE_TEST_ACCOUNT" -eq 1 ]; then
    TEST_USERNAME="hotspot-test"
    N=1
    while mysql -N -s taransvar -e "SELECT 1 FROM hotspotSubscriber WHERE username='${TEST_USERNAME}' LIMIT 1;" | grep -q 1; do
        N=$((N + 1))
        TEST_USERNAME="hotspot-test${N}"
    done
    # Human-friendly credentials: omit 0/O/o, 1/I/i/l/L so characters remain
    # distinguishable on phones, printed tickets and common sans-serif fonts.
    TEST_PASSWORD="$(tr -dc '23456789ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz' </dev/urandom | head -c 10)"
    mysql taransvar -e "INSERT INTO hotspotSubscriber(username,password,confirmedTime,subscriptionType,quotaMB,usageMB,enabled) VALUES('${TEST_USERNAME}','${TEST_PASSWORD}',NOW(),'quota',${TEST_QUOTA_MB},0,b'1');"
    echo "Created test Wi-Fi account '$TEST_USERNAME' with ${TEST_QUOTA_MB} MB quota."
    echo "Test Wi-Fi password: $TEST_PASSWORD"
else
    echo "No test Wi-Fi account created. Existing subscriber accounts, if any, were preserved."
fi

printf "\nInstall script is finished\n"
printf "WAN configuration was preserved; openNDS controls captive access and NetBird wt0/wt* is management only.\n"

# Current tarakernel versions explicitly advertise that they fail open before
# taralink supplies configuration. Keep those modules loaded so they can still
# enforce invariant protections. Unload only a legacy fail-closed module.
if lsmod | awk '{print $1}' | grep -qx tarakernel && \
   ! pgrep -x taralink >/dev/null 2>&1; then
    if [ -r /sys/module/tarakernel/parameters/fail_open_without_config ] && \
       grep -Eiq '^(1|y|yes)$' /sys/module/tarakernel/parameters/fail_open_without_config; then
        echo "tarakernel is fail-open without configuration; keeping it loaded while taralink is unavailable."
    else
        echo "Legacy fail-closed tarakernel detected without taralink; unloading it to preserve hotspot forwarding."
        if ! modprobe -r tarakernel; then
            echo "ERROR: unable to unload legacy unconfigured tarakernel; hotspot forwarding would be blocked." >&2
            exit 1
        fi
    fi
fi

read -n 1 -s -p "********** The system should now restart. Press Ctrl-C to abort or any other key to reboot. "
echo
reboot
