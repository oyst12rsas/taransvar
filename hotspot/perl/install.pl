#!/usr/bin/perl
use strict;
use warnings;
use autodie;
use DBI;
use Data::Dumper qw(Dumper);
use File::Copy;

use lib '.'; # ('/root/taransvar/perl');
use func;

#TO DO!
# /etc/default/dhcpd.conf
# INTERFACES="eth1"	#NOTE replace with LAN nic

#my $szSysRoot = "/home/setup/";
my $szSysRoot = "/root/wifi/";


print "\n\n *********** install.pl **********************\n\n";

my $cSetup = getSetup();

my @chars = ("A".."Z", "a".."z", "0".."9");

my $szSysSetupFile = $szSysRoot."setup.txt";

if  (! -f $szSysSetupFile) {
	my ($sec,$min,$hour,$mday,$mon,$year,$wday,$yday,$isdst)=localtime(time);
	my $nice_timestamp = sprintf ( "%04d%02d%02d_%02d%02d%02d",
                                   $year+1900,$mon+1,$mday,$hour,$min,$sec);
	my $szWrite = "sysdata|$nice_timestamp\n";
	open(my $fh, '>', $szSysSetupFile) or die "Could not open file '$szSysSetupFile' $!";
	print $fh $szWrite;
	close $fh;
}


print "Printing keys..";
system('gpg --list-keys > ~/grpkeys.txt');

my $szCertFile = $szSysRoot."temp/oystein.gpg";
	
if (! -f $szCertFile)
{
	print "\nWARNING! **** gpg file not found, using default! *********\n";
	$szCertFile = "/home/oystein/Downloads/home/setup/distro/copythese/oystein.gpg";
}
	
#$szCertFile = "/home/setup/distro/copythese/oystein.gpg";
#$szCertFile = "~/Downloads/home/setup/distro/copythese/oystein.gpg";
system('gpg --import '.$szCertFile);
system('gpg --list-keys > ~/grpkeys2.txt');
	
#open(my $fh, '>>', $szSysSetupFile) or die "Could not open file '$szSysSetupFile' $!";
#print $fh "Superuser=???\n";
#close $fh;

#Fix crontab
#Generate crontab job desc (during the night, once a week). Format:  m h  dom mon dow   command
my $szString = int(rand(59))." ".int(rand(6))." * * ".int(rand(7));
my $szCrontabFile = "/var/spool/cron/crontabs/root";

#system( "cp distro/copythese/crontab $szCrontabFile");
#Moved to install.sh: copy("distro/copythese/crontab", $szCrontabFile)  or die "Copy failed: $!";
#Moved to install.sh: system( "chmod 0600 /var/spool/cron/crontabs/root");

open(my $fCronh, '>>', $szCrontabFile) or die "Could not open file '$szCrontabFile' $!";
print $fCronh $szString." perl ".$szSysRoot."perl/sendReport.pl";
close $fCronh;

my $szDevice = $cSetup->{"internalNic"};
my $szCmd = 'sed -i "s/DEVICE eth0/DEVICE '.$szDevice.'/"  /etc/ipfm.conf';
system($szCmd);
#Restart ipfm
system("sudo killall ipfm && sudo ipfm");

#Create guest user (don't know what this is good for so dropping)
#my $szGuestPass;
#$szGuestPass .= $chars[rand @chars] for 1..5;
#my $szGuestUser;
#$szGuestUser = "user".$chars[rand @chars];
#print "Creating $szGuestUser/$szGuestPass\n";
#system("useradd -m -p \$(openssl passwd -1 $szGuestPass) $szGuestUser");

#open(my $fSysSetup, '>>', $szSysSetupFile) or die "Could not open file '$szSysSetupFile' $!";
#print $fSysSetup "Guest User:$szGuestUser/$szGuestPass\n";
#close $fSysSetup;

#Update tables unless it's already done....
my $conn = getConnection();
my $szSQL = "select count(*) as cnt from radcheck";
my $sth = $conn->prepare($szSQL) or die "prepare statement failed: $conn->errstr()";
$sth->execute() or die "execution failed: $sth->errstr()";
my $nCount = 0;
if (my $row = $sth->fetchrow_hashref()) {
	$nCount = 0+$row->{'cnt'}; 
}
if (!$nCount) {
	system('mysql taransvar < /root/wifi/distro/aftercreate.sql');
} else {
	print "hotspot setup already imported so skipping..\n";
}



