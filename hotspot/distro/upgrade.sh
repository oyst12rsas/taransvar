#!/bin/bash

press_enter()
{
    echo -en "\nPress Enter to continue"
    read line
    #clear
}

echo "Please wait a few seconds while upgrading the system...... "

cp -r html /var/www
#cp distro/copythese/*.sql /root/wifi/distro 
cp perl/* /root/wifi/perl

cp distro/copythese/debugserver /usr/lib/cgi-bin
chmod 705 /usr/lib/cgi-bin/*

#mysql -u root  -s --password=cebPh18and taransvar -e "update setup set maintenanceRequest = 'checkdb';"

perl /root/wifi/perl/checkdb.pl 

service cron reload

cd ../..
rm -r home.old
mv home home.old

#perl /root/wifi/perl/checkSleepingRunning.pl

echo "Install script is finished";
echo "*********** NOTE! We will now restart your machine! *******";
press_enter

echo "Booting dropped for testing.. you should  do it yourself.. After first checking if you have Internet connection now and then again after the booting."
#reboot

