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

if [ "${ID:-}" != "ubuntu" ] && [ "${ID_LIKE:-}" != *debian* ]; then
    echo "ERROR: This installer currently supports Ubuntu/Debian-family systems." >&2
    exit 1
fi

if ! command -v apt-get >/dev/null 2>&1; then
    echo "ERROR: apt-get is required by this installer." >&2
    exit 1
fi

# Do not assume a fresh hotspot already has the web/database/network tooling.
# apt is idempotent: already installed packages are left alone.
echo
echo "Checking/installing TaraSec prerequisites..."
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    ca-certificates curl git perl python3 \
    network-manager iw rfkill iproute2 \
    apache2 mysql-server mysql-client \
    php libapache2-mod-php php-mysql \
    cron \
    libdbi-perl libdbd-mysql-perl \
    ipfm freeradius freeradius-mysql

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
systemctl enable --now mysql
systemctl enable --now cron

# We require a working non-NetBird Internet uplink before changing Wi-Fi.
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
mkdir -p /var/log/ipfm/subnet/daily/archived
mkdir -p /var/log/ipfm/subnet/hourly/archived
mkdir -p /var/log/ipfm/subnet/minute/archived
mkdir -p /var/log/ipfm/individual/archived

mkdir -p /var/www/html/temp
chown www-data:www-data /var/www/html/temp
chown www-data:www-data /var/www/html/temp/* 2>/dev/null || true

cp distro/copythese/*.sql /root/wifi/distro
cp perl/* /root/wifi/perl
cp distro/copythese/ipfm.conf /etc
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
systemctl restart mysql

( cd perl && perl install.pl )
service cron reload

if [ -e /etc/freeradius/sites-enabled/default ] && [ ! -e /etc/freeradius/sites-enabled/default.old ]; then
    mv /etc/freeradius/sites-enabled/default /etc/freeradius/sites-enabled/default.old
fi
cp distro/copythese/radiusdefault /etc/freeradius/sites-enabled/default
if [ -e /etc/freeradius/radiusd.conf ]; then
    sed -i 's/#$INCLUDE sql.conf/$INCLUDE sql.conf/' /etc/freeradius/radiusd.conf || true
fi

perl /root/wifi/perl/checkSleepingRunning.pl

echo
echo "Installing and enrolling TaraSec NetBird management..."
bash "$REPO_ROOT/misc/install_netbird_management.sh"

# Configure the client Wi-Fi side. If no explicit SSID is supplied, the helper
# scans nearby Wi-Fi names, proposes TaraSec_<hostname>, validates the short
# name, shows exactly what phone users will see, and asks for confirmation.
if [ -n "${TARASEC_HOTSPOT_IF:-}" ]; then
    ADDR="${TARASEC_HOTSPOT_ADDR:-192.168.50.1/24}"
    perl "$REPO_ROOT/misc/setupWifiNicAsHotspot.pl" \
        "$TARASEC_HOTSPOT_IF" "${TARASEC_HOTSPOT_SSID:-}" "$ADDR"
else
    echo
    echo "No hotspot Wi-Fi interface was supplied."
    echo "Configure it with:"
    echo "  sudo perl $REPO_ROOT/misc/setupWifiNicAsHotspot.pl <wifi-if>"
fi

printf "\nInstall script is finished\n"
printf "WAN configuration was preserved; NetBird wt0/wt* is management only.\n"
read -n 1 -s -p "********** The system should now restart. Press Ctrl-C to abort or any other key to reboot. "
echo
reboot
