#!/usr/bin/perl
#TO DO!
# /etc/default/dhcpd.conf
# INTERFACES="eth1"	#NOTE replace with LAN nic

use strict;
use warnings;
use autodie;

#my $szSysRoot = "/home/setup/";
my $szSysRoot = "/root/wifi/";


#Abort if installed before..
my $szInstallSuccessfulFile = $szSysRoot."log/installcompleted.txt";

my ($sec,$min,$hour,$mday,$mon,$year,$wday,$yday,$isdst) = localtime(time);
$year += 1900;
my $szDateTime = "$year-$mon-$mday $hour:$min:$sec";

if (-e $szInstallSuccessfulFile)
{
	system("echo ".$szDateTime." Install was completed before, so aborting.. >> ".$szInstallSuccessfulFile);
	exit;
}

system("echo First time install started ".$szDateTime.". Delete this file and restart to rerun install.. >> ".$szInstallSuccessfulFile);

system ("sh ".$szSysRoot."distro/doInitialImport.sh");

system("echo First time install completed ".$szDateTime.". >> ".$szInstallSuccessfulFile);
