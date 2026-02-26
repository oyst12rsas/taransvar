#!/bin/bash

press_enter()
{
    echo -en "\nPress Enter to continue"
    read line
    #clear
}

if [ "$(id -u)" -ne 0 ]; then
        echo 'This script must be run by root.\nStart with: sudo bash install.sh' >&2
        exit 1
fi

echo "Please wait a few seconds while installing the files...... "

mkdir /root/wifi/
mkdir /root/wifi/perl
mkdir /root/wifi/log
mkdir /root/wifi/temp
mkdir /root/wifi/distro
mkdir /var/log/ipfm
mkdir /var/log/ipfm/subnet
mkdir /var/log/ipfm/subnet/daily
mkdir /var/log/ipfm/subnet/daily/archived
mkdir /var/log/ipfm/subnet/hourly
mkdir /var/log/ipfm/subnet/hourly/archived
mkdir /var/log/ipfm/subnet/minute
mkdir /var/log/ipfm/subnet/minute/archived
mkdir /var/log/ipfm/individual
mkdir /var/log/ipfm/individual/archived

#mysqldump -d -u root -p > /root/wifi/distro/copythese
#mkdir /var/www/html/hotspot
#cp -r html /var/www/hotspot
#chown www-data:www-data /var/www/html/hotspot
#mkdir /var/www/html/temp
chown www-data:www-data /var/www/html/temp
chown www-data:www-data /var/www/html/temp/*

cp distro/copythese/*.sql /root/wifi/distro 
#cp distro/copythese/iptables.sh /root/wifi
cp perl/* /root/wifi/perl
#cp distro/copythese/dhcpd.conf /etc/dhcp
cp distro/copythese/ipfm.conf /etc
cp distro/copythese/startup.conf /etc/init
cp distro/copythese/taransvar.service /etc/systemd/system

#my $szCrontabFile = "";

#OT 250226 - disabled
#cp distro/copythese/crontab /var/spool/cron/crontabs/root
#chmod 0600 /var/spool/cron/crontabs/root
#touch -m /var/spool/cron/crontabs/root
#systemctl daemon-reload
if ! grep -q sleepingbeauty "/var/spool/cron/crontabs/root" ; then #OT 250313 - changed from here to "fi"
   printf "\n* * * * * perl /root/wifi/perl/sleepingbeauty.pl > /root/wifi/log/sleeping.txt\n" >> /var/spool/cron/crontabs/root
   printf "sleepingbeauty put in crontab\n"
else
   printf "sleepingbeauty was already in crontab\n"
fi

cp distro/copythese/*.gpg /root/wifi/temp

systemctl enable taransvar
systemctl start taransvar

#To enable perl script to read syslog (not working) - or chmod 644 syslog
usermod -a -G adm www-data

file="/var/www/html/index.html"
if [ -f "$file" ] ; then
    rm "$file"
fi

a2enmod cgi
cp distro/copythese/debugserver /usr/lib/cgi-bin
chmod 705 /usr/lib/cgi-bin/*

#sed -i 's/#net.ipv4.ip_forward/net.ipv4.ip_forward/g' /etc/sysctl.conf
#Because the sed doesn't seem to work:
sysctl -w net.ipv4.ip_forward=1

#sed -i 's/#net.ipv6.conf.all.forwarding/net.ipv6.conf.all.forwarding/g' /etc/sysctl.conf
sed -i "s/Options Indexes FollowSymLinks/Options FollowSymLinks/"  /etc/apache2/apache2.conf

echo "<Directory /usr/lib/cgi-bin>" >> /etc/apache2/apache2.conf
echo "  Options +ExecCGI" >> /etc/apache2/apache2.conf
echo "</Directory>" >> /etc/apache2/apache2.conf
echo "" >> /etc/apache2/apache2.conf
echo "AddHandler cgi-script .cgi .pl" >> /etc/apache2/apache2.conf
echo "" >> /etc/apache2/apache2.conf

systemctl restart apache2
systemctl restart mysql

#mysql taransvar < /root/wifi/distro/emptydb.sql
#Moved to install.pl:  mysql taransvar < /root/wifi/distro/aftercreate.sql	#NOTE! Should check first that it's not yet run....

#Network setup is handled much better by Gatekeeper system...
#perl /root/wifi/perl/setup_network.pl 
(
    cd perl
    perl install.pl 
)
#perl /root/wifi/perl/callCheckCert.pl

service cron reload

#Radius
mv /etc/freeradius/sites-enabled/default /etc/freeradius/sites-enabled/default.old
cp distro/copythese/radiusdefault /etc/freeradius/sites-enabled/default
sed -i "s/#$INCLUDE sql.conf/$INCLUDE sql.conf/"  /etc/freeradius/radiusd.conf

perl /root/wifi/perl/checkSleepingRunning.pl

printf "Install script is finished\n";
read -n 1 -s -p "********** The system should now restart. Press Ctrl-C to abort or any other key to boot./n"  
#press_enter

reboot

