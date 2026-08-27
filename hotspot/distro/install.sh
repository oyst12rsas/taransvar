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

echo "Please wait a few seconds while installing the files...... "

mkdir -p /root/wifi/perl /root/wifi/log /root/wifi/temp /root/wifi/distro
mkdir -p /var/log/ipfm/subnet/daily/archived
mkdir -p /var/log/ipfm/subnet/hourly/archived
mkdir -p /var/log/ipfm/subnet/minute/archived
mkdir -p /var/log/ipfm/individual/archived

# The hotspot application assumes the Gatekeeper/base web tree already exists.
mkdir -p /var/www/html/temp
chown www-data:www-data /var/www/html/temp
chown www-data:www-data /var/www/html/temp/* 2>/dev/null || true

cp distro/copythese/*.sql /root/wifi/distro
cp perl/* /root/wifi/perl
cp distro/copythese/ipfm.conf /etc
cp distro/copythese/startup.conf /etc/init
cp distro/copythese/taransvar.service /etc/systemd/system

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
