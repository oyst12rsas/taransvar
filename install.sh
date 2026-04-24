#!/bin/bash
#Taransvar Cyber Security
#To install: sudo bash install.sh

if [ "$(id -u)" -ne 0 ]; then
        echo 'This script must be run by root.\nStart with: sudo bash install.sh' >&2
        exit 1
fi

#Uses these because some scripts are also run from crontab (can't relate to current user while installing)
mkdir /root
mkdir /root/setup
mkdir /root/setup/log

SCRIPT_DIR=$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )
echo $SCRIPT_DIR
cd $SCRIPT_DIR
printf "\nInstalling Taransvar Cyber Solution\n"
read -t 5 -p "Continuing in 5 seconds..."
printf "Installing required linux packages...\n"  

apt-get update -y

#This is missing in small systems so make sure it's installed
apt-get install -y debconf-utils

if [ -d /var/lib/mysql ] && [ "$(ls -A /var/lib/mysql 2>/dev/null)" ]; then
    echo "Existing database data detected — skipping MariaDB install"
else
    apt-get install -y mariadb-server
fi

apt-get install -y apache2 perl libdbd-mysql-perl libmariadb-dev libmnl-dev
apt-get install -y php libapache2-mod-php php-mysql
apt-get install -y gcc make curl libcurl4-openssl-dev dhcpdump net-tools conntrack
apt-get install -y libdbi-perl libdbd-mysql-perl conntrack dhcpdump isc-dhcp-server
apt-get install -y curl libcurl4-openssl-dev whois iptables libcjson-dev ipset

echo "wireshark-common wireshark-common/install-setuid boolean false" | debconf-set-selections
DEBIAN_FRONTEND=noninteractive apt-get install -y tshark

#Now also install for hotspot... 
apt-get install -y ipfm

#260307 - install for get() used in lib_cron.pm
sudo apt-get install -y libwww-perl

apt-get update -y
apt-get upgrade -y

#read -n 1 -s -p "About to install perl libraries (this should be checked manually..)"
PERL_MM_USE_DEFAULT=1 
perl -MCPAN -e "URI"
perl -MCPAN -e "LWP"
#read -n 1 -s -p "Finished installing perl libraries (this should be checked manually..)"

#if ! perl connect.pl; then
#	read -n 1 -s  "The database is already there... So aborting the installation. Press a key."
#	exit
#fi

printf "Installing the database\n"

printf "The install routine may now generate some error message while trying to create DB user.\n"
mysql -e "create database taransvar;"

(
	#Run from misc dir because of library
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

if [ $? -eq 0 ]
then
	printf "Successfully connected (database and user seems correct installed)..\n"
else
	read -n 1 -s -p "Unable to connect to DB (probably because failed to create user.  (please check)... Press a key to continue (or Ctrl-C to abort)."
	echo ""	#Next line
fi

if ! grep -q crontasks "/var/spool/cron/crontabs/root" ; then
  echo "* * * * * perl /root/taransvar/perl/crontasks.pl cron" >> /var/spool/cron/crontabs/root
fi

if ! grep -q startup.pl "/var/spool/cron/crontabs/root" ; then
  echo "@reboot perl /root/taransvar/perl/startup.pl > /root/wifi/log/startup.txt" >> /var/spool/cron/crontabs/root
fi
service cron reload

#mariadb < ../db/create.sql
mariadb taransvar < db/taransvar.sql
mariadb taransvar < db/postcreate.sql

echo "Database created. Copying files/"

mkdir /root/taransvar
mkdir /root/taransvar/perl

#ØT 260302 - rsync instead of copying
#cp misc/*.* /root/taransvar/perl
rsync -a --exclude '.git' misc/ /root/taransvar/perl/

echo "Copying (rsync) html files..."
#cp -r html /var/www
rsync -a --exclude '.git' html/ /var/www/html/

echo "We're now about to do network installation.\n\nIf you know how to set this up youself, then you probably want to skip this.\n\n"
read -p "Do you want to run network installation script? (y/n): " answer

if [[ "$answer" == "y" || "$answer" == "Y" ]]; then
    echo "Running network installation..."
	pause 5

(
	cd misc
	perl setup_network.pl
)
else
    echo "Skipping network installation.\n"
fi


(
	cd misc
	perl compile.pl install
)


if [ $? -eq 0 ]
then
	printf "Successfully compiled..\n"
	cp taralink/taralink /root/taransvar
else
	printf "******* ERROR ****** Could not compile taralink...\n"
	read -n 1 -s -p "Ctrl-C to break or press any key to coontinue"
fi

#perl install.pl - nothing left here 

#echo "Seems like the installation succeeded.\n\n"
#echo "To install the hotspot system:\n"
#echo "- cd ../hotspot"
#echo "- sudo bash distro/install.sh"

printf "**** Gatekeeper is now installed ****\n\nThis system also contains routines to run your computer as a wifi hotspot\n"
printf "It requies that you have an additional network device and will share the one in which you're not connected to internet with others\n\n"

#read -n 1 -s -p "Press Ctrl-C to abort or any key to coontinue."

#Hotspot system is removed from install for now... The iptables there and also a bult inn extremely simple firewall easily cause iptables problems 
#read -p "Do you want to install the hotspot system? [y/n]: " answer
answer="n"

# Convert input to lowercase to handle 'Y' or 'y'
case "$answer" in
    [yY][eE][sS]|[yY])
        echo "Installing hotspot..."
		read -t 5 -p "Continuing in 5 seconds..."
        (
			cd hotspot
			bash distro/install.sh
		)
        ;;
    *)
        echo "Skipping hotspot installation"
		read -t 5 -p "Continuing in 5 seconds..."
        exit 1
        ;;
esac

(
	cd misc
	perl startup.pl
)

