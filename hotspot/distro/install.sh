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
    php libapache2-mod-php php-mysql \
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
    TRUST_COLUMN_PRESENT="$(mysql -N -s -e "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='taransvar' AND table_name='partner' AND column_name='trustScore';")"
    if [ "${TRUST_COLUMN_PRESENT:-0}" = "0" ] && [ -s "$REPO_ROOT/db/migrate_partner_trust.sql" ]; then
        echo "Adding TaraSec partner trust metadata..."
        mysql taransvar < "$REPO_ROOT/db/migrate_partner_trust.sql"
    fi
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

# Enroll this hotspot with the central trust service when a bootstrap token has
# been provisioned locally. Secrets are deliberately never shipped in Git.
# /etc/tarasec-partner.conf may define:
#   PARTNER_TRUST_URL=https://tarasec.org/script/partnerTrust.php
#   PARTNER_TRUST_TOKEN=<bootstrap token>
if [ -r /etc/tarasec-partner.conf ]; then
    # shellcheck disable=SC1091
    . /etc/tarasec-partner.conf
fi
PARTNER_TRUST_URL="${PARTNER_TRUST_URL:-https://tarasec.org/script/partnerTrust.php}"
if [ -n "${PARTNER_TRUST_TOKEN:-}" ]; then
    HOTSPOT_EXTERNAL_ID="$(cat /etc/machine-id 2>/dev/null || hostname)"
    HOTSPOT_NAME="$(hostname)"
    echo "Enrolling hotspot as a low-trust TaraSec partner..."
    if curl -fsS --connect-timeout 5 --max-time 15 \
        -H "X-TaraSec-Token: $PARTNER_TRUST_TOKEN" \
        --data-urlencode "action=enroll" \
        --data-urlencode "externalId=hotspot:$HOTSPOT_EXTERNAL_ID" \
        --data-urlencode "name=$HOTSPOT_NAME" \
        "$PARTNER_TRUST_URL" >/root/wifi/log/partner-enrollment.json; then
        echo "Hotspot trust enrollment: OK (initial score assigned by server)."
    else
        echo "WARNING: Hotspot trust enrollment failed. Installation will continue; enrollment can be retried later." >&2
    fi
else
    echo "Partner trust enrollment deferred: no PARTNER_TRUST_TOKEN provisioned."
fi

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
