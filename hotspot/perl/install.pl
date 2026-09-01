#!/usr/bin/perl
use strict;
use warnings;
use autodie;
use DBI;
use Data::Dumper qw(Dumper);
use File::Copy;

use lib '.';
use func;

my $szSysRoot = "/root/wifi/";

print "\n\n *********** install.pl **********************\n\n";

my $cSetup = getSetup();
my @chars = split //, "23456789ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz";
my $szSysSetupFile = $szSysRoot."setup.txt";

if  (! -f $szSysSetupFile) {
	my ($sec,$min,$hour,$mday,$mon,$year,$wday,$yday,$isdst)=localtime(time);
	my $nice_timestamp = sprintf ( "%04d%02d%02d_%02d%02d%02d", $year+1900,$mon+1,$mday,$hour,$min,$sec);
	my $szWrite = "sysdata|$nice_timestamp\n";
	open(my $fh, '>', $szSysSetupFile) or die "Could not open file '$szSysSetupFile' $!";
	print $fh $szWrite;
	close $fh;
}

print "Printing keys..";
system('gpg --list-keys > ~/grpkeys.txt');

my $szCertFile = $szSysRoot."temp/oystein.gpg";
if (! -f $szCertFile) {
	print "\nWARNING! **** gpg file not found, using default! *********\n";
	$szCertFile = "/home/oystein/Downloads/home/setup/distro/copythese/oystein.gpg";
}
system('gpg --import '.$szCertFile);
system('gpg --list-keys > ~/grpkeys2.txt');

my $szString = int(rand(59))." ".int(rand(6))." * * ".int(rand(7));
my $szCrontabFile = "/var/spool/cron/crontabs/root";
open(my $fCronh, '>>', $szCrontabFile) or die "Could not open file '$szCrontabFile' $!";
print $fCronh $szString." perl ".$szSysRoot."perl/sendReport.pl";
close $fCronh;

my $szDevice = $cSetup->{"internalNic"};
if (system('command -v ipfm >/dev/null 2>&1') == 0) {
	if (-f '/etc/ipfm.conf') {
		my $szCmd = 'sed -i "s/DEVICE eth0/DEVICE '.$szDevice.'/"  /etc/ipfm.conf';
		system($szCmd);
	}
	system("killall ipfm >/dev/null 2>&1 || true");
	system("ipfm");
	print "Legacy IPFM accounting enabled.\n";
} else {
	print "IPFM is not installed on this OS; skipping legacy IPFM bandwidth accounting.\n";
}

my $conn = getConnection();
my $szSQL = "select count(*) as cnt from hotspotSetup";
my $sth = $conn->prepare($szSQL) or die "prepare statement failed: $conn->errstr()";
$sth->execute() or die "execution failed: $sth->errstr()";
my $nCount = 0;
if (my $row = $sth->fetchrow_hashref()) { $nCount = 0+$row->{'cnt'}; }
if (!$nCount) {
	my $rc = system('mysql taransvar < /root/wifi/distro/aftercreate.sql');
	die "Unable to import TaraSec hotspot defaults from aftercreate.sql\n" if $rc != 0;
} else {
	print "hotspot setup already imported so skipping..\n";
}

my $roaming_schema = '../opennds/schema.sql';
if (-f $roaming_schema) {
	print "Applying TaraSec global subscriber credit/roaming schema...\n";
	my $rc = system('mysql taransvar < '.$roaming_schema);
	die "Unable to import TaraSec hotspot roaming schema\n" if $rc != 0;
	$rc = system(q{mysql taransvar -e "ALTER TABLE hotspotSession MODIFY entitlementId BIGINT UNSIGNED NULL"});
	die "Unable to migrate hotspotSession entitlementId for credit roaming\n" if $rc != 0;
} else {
	die "TaraSec hotspot roaming schema not found at $roaming_schema\n";
}

# Subscriber authentication and the idempotent local grant-receipt cache remain
# in core TaraSec. Credit limits, debt and financial ledgers are intentionally
# NOT installed here; they belong only to the private tarasec_payment DB server.
my $subscriber_schema = '../opennds/subscriber-schema.sql';
if (-f $subscriber_schema) {
	print "Applying TaraSec subscriber app authentication schema...\n";
	my $rc = system('mysql taransvar < '.$subscriber_schema);
	die "Unable to import TaraSec subscriber authentication schema\n" if $rc != 0;
} else {
	die "TaraSec subscriber authentication schema not found at $subscriber_schema\n";
}

$conn->disconnect;
