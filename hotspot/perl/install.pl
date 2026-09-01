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

my @chars = split //, "23456789ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz";

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
system('gpg --import '.$szCertFile);
system('gpg --list-keys > ~/grpkeys2.txt');

#Fix crontab
#Generate crontab job desc (during the night, once a week). Format:  m h  dom mon dow   command
my $szString = int(rand(59))." ".int(rand(6))." * * ".int(rand(7));
my $szCrontabFile = "/var/spool/cron/crontabs/root";

open(my $fCronh, '>>', $szCrontabFile) or die "Could not open file '$szCrontabFile' $!";
print $fCronh $szString." perl ".$szSysRoot."perl/sendReport.pl";
close $fCronh;

# IPFM was used by the original hotspot for legacy bandwidth accounting.
# It is no longer packaged by current Ubuntu releases, so do not make modern
# TaraSec installation depend on it. If an older host still has ipfm, preserve
# the historical configuration and restart behaviour.
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

# Import one-time hotspot defaults. radcheck is no longer a TaraSec account
# store, so its row count cannot be used as the installation marker. Use the
# hotspotSetup row that aftercreate.sql itself creates instead.
my $conn = getConnection();
my $szSQL = "select count(*) as cnt from hotspotSetup";
my $sth = $conn->prepare($szSQL) or die "prepare statement failed: $conn->errstr()";
$sth->execute() or die "execution failed: $sth->errstr()";
my $nCount = 0;
if (my $row = $sth->fetchrow_hashref()) {
	$nCount = 0+$row->{'cnt'};
}
if (!$nCount) {
	my $rc = system('mysql taransvar < /root/wifi/distro/aftercreate.sql');
	die "Unable to import TaraSec hotspot defaults from aftercreate.sql\n" if $rc != 0;
} else {
	print "hotspot setup already imported so skipping..\n";
}

# The roaming/reward schema is deliberately repeatable and must run for both
# fresh installs and upgrades so existing Student/Cigar pilots gain new fields.
my $roaming_schema = '../opennds/schema.sql';
if (-f $roaming_schema) {
	print "Applying TaraSec global subscriber credit/roaming schema...\n";
	my $rc = system('mysql taransvar < '.$roaming_schema);
	die "Unable to import TaraSec hotspot roaming schema\n" if $rc != 0;

	# The first roaming pilot required hotspotSession.entitlementId. Credit-based
	# sessions deliberately have no legacy time entitlement, so upgrades must
	# relax that old NOT NULL constraint. This ALTER is safe to repeat.
	$rc = system(q{mysql taransvar -e "ALTER TABLE hotspotSession MODIFY entitlementId BIGINT UNSIGNED NULL"});
	die "Unable to migrate hotspotSession entitlementId for credit roaming\n" if $rc != 0;
} else {
	die "TaraSec hotspot roaming schema not found at $roaming_schema\n";
}

# Subscriber-facing Android/iOS clients use the same global customer and credit
# records. Apply only the credential/token layer here; passwords themselves are
# never created by the installer.
my $subscriber_schema = '../opennds/subscriber-schema.sql';
if (-f $subscriber_schema) {
	print "Applying TaraSec subscriber app authentication schema...\n";
	my $rc = system('mysql taransvar < '.$subscriber_schema);
	die "Unable to import TaraSec subscriber authentication schema\n" if $rc != 0;
} else {
	die "TaraSec subscriber authentication schema not found at $subscriber_schema\n";
}

# Credit facilities are optional and explicitly approved. The schema is safe to
# apply everywhere; no subscriber receives borrowing capacity by default.
my $facility_schema = '../opennds/credit-facility-schema.sql';
if (-f $facility_schema) {
	print "Applying TaraSec subscriber credit facility schema...\n";
	my $rc = system('mysql taransvar < '.$facility_schema);
	die "Unable to import TaraSec subscriber credit facility schema\n" if $rc != 0;
} else {
	die "TaraSec subscriber credit facility schema not found at $facility_schema\n";
}

$conn->disconnect;
