#!/bin/bash
#Taransvar Cyber Security
#To install: sudo bash install.sh

if [ "$(id -u)" -ne 0 ]; then
        echo 'This script must be run by root.\nStart with: sudo bash install.sh' >&2
        exit 1
fi

#Uses these because some scripts are also run from crontab (can't relate to current user while installing)
mkdir -p /root/setup/log

SCRIPT_DIR=$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )
echo $SCRIPT_DIR
cd $SCRIPT_DIR
printf "\nInstalling Taransvar Cyber Solution\n"
read -t 5 -p "Continuing in 5 seconds..."
printf "Installing required linux packages...\n"

apt-get update -y
apt-get install -y debconf-utils

if [ -d /var/lib/mysql ] && [ "$(ls -A /var/lib/mysql 2>/dev/null)" ]; then
    echo "Existing database data detected — skipping MariaDB install"
else
    apt-get install -y mariadb-server
fi

apt-get install -y apache2 perl libdbd-mysql-perl libmariadb-dev libmnl-dev
apt-get install -y php libapache2-mod-php php-mysql php-curl
apt-get install -y gcc make curl libcurl4-openssl-dev dhcpdump net-tools conntrack
apt-get install -y libdbi-perl libdbd-mysql-perl libjson-perl conntrack dhcpdump isc-dhcp-server
apt-get install -y curl libcurl4-openssl-dev whois iptables libcjson-dev ipset

echo "wireshark-common wireshark-common/install-setuid boolean false" | debconf-set-selections
DEBIAN_FRONTEND=noninteractive apt-get install -y tshark

# Hotspot packages are installed by hotspot/distro/install.sh only if the owner opts in.

#260307 - install for get() used in lib_cron.pm
apt-get install -y libwww-perl

apt-get update -y
apt-get upgrade -y

PERL_MM_USE_DEFAULT=1
perl -MCPAN -e "URI"
perl -MCPAN -e "LWP"

printf "Installing the database\n"
printf "The install routine may now generate some error message while trying to create DB user.\n"
mysql -e "create database taransvar;"

(
    cd misc
    perl createUsers.pl
)

if [ $? -eq 0 ]; then
    printf "Able to create users...\n"
else
    read -n 1 -s -p "Unable to create users... "
    printf "\n"
fi

printf "Now checking if user successfully created.. This sould not generate error..\n"
(
    cd misc
    perl connect.pl
)

if [ $? -eq 0 ]; then
    printf "Successfully connected (database and user seems correct installed)..\n"
else
    read -n 1 -s -p "Unable to connect to DB (probably because failed to create user. (please check)... Press a key to continue (or Ctrl-C to abort)."
    echo ""
fi

mkdir -p /var/spool/cron/crontabs
touch /var/spool/cron/crontabs/root
if ! grep -q crontasks "/var/spool/cron/crontabs/root" ; then
  echo "* * * * * /bin/perl /root/taransvar/perl/crontasks.pl cron" >> /var/spool/cron/crontabs/root
fi

if ! grep -q startup.pl "/var/spool/cron/crontabs/root" ; then
  echo "@reboot /bin/perl /root/taransvar/perl/startup.pl > /root/wifi/log/startup.txt" >> /var/spool/cron/crontabs/root
fi
service cron reload

mariadb taransvar < db/taransvar.sql
mariadb taransvar < db/postcreate.sql

echo "Database created. Copying files/"
mkdir -p /root/taransvar/perl
rsync -a --exclude '.git' misc/ /root/taransvar/perl/

echo "Copying (rsync) html files..."
rsync -a --exclude '.git' html/ /var/www/html/

printf "\nWe're now about to do network installation.\n\nIf you know how to set this up youself, then you probably want to skip this.\n\n"
read -p "Do you want to run network installation script? (y/n): " answer

if [[ "$answer" == "y" || "$answer" == "Y" ]]; then
    echo "Running network installation..."
    sleep 5
    (
        cd misc
        perl setup_network.pl
    )
else
    echo "Skipping network installation."
fi

(
    cd misc
    perl compile.pl install
)

if [ $? -eq 0 ]; then
    printf "Successfully compiled..\n"
    cp taralink/taralink /root/taransvar
else
    printf "******* ERROR ****** Could not compile taralink...\n"
    read -n 1 -s -p "Ctrl-C to break or press any key to continue"
fi

printf "**** Gatekeeper is now installed ****\n\n"
printf "TaraSec can also use a spare Wi-Fi adapter as a protected hotspot.\n"
printf "The active Internet/uplink interface will not be converted into the hotspot.\n\n"
read -p "Do you want this computer to provide TaraSec Wi-Fi/hotspot access? [y/N]: " answer

case "$answer" in
    [yY][eE][sS]|[yY])
        echo "Installing TaraSec hotspot..."
        # This is an explicit owner opt-in from the general installer. The
        # hotspot-specific installer therefore goes straight to hotspot setup
        # and only asks about an optional test subscriber/quota.
        TARASEC_HOTSPOT_REQUESTED=1 bash "$SCRIPT_DIR/hotspot/distro/install.sh"
        ;;
    *)
        echo "Skipping hotspot installation."
        ;;
esac

(
    cd misc
    perl startup.pl
)
