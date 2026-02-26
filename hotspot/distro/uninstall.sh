#!/bin/bash

press_enter()
{
    echo -en "\nPress Enter to continue delete the Taransvar system."
    read line
    #clear
}

echo "NOTE! This script will uninstall the Taransvar Wifi system"
press_enter

rm -r /root/wifi/*
rm -r /var/www/html/*

mysql -u root -s --password=cebPh18and -e "drop database taransvar;"

echo "Uninstall script is finished";
#reboot

