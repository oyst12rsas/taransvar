#!/usr/bin/perl
# - Parses new ipfm usage log files and moves them to archive
# - Checking the database who should have access and updates the IPTABLES rules

use strict;
use warnings;
use autodie;
use DBI;

my $nLimit = 10;

my $szSysRoot = "/root/wifi/";

my $database = "taransvar";
my $hostname = "localhost";
my $port = "3306";
my $user = "perl";
my $password = "RevSjoko731";

my @cArinTags = ("NetName","NetHandle","Parent","NetType","OriginAS","Organization","RegDate","Updated","Comment","OrgName","OrgId","Address","City","StateProv","PostalCode","Country","OrgAbuseHandle", "OrgAbuseName", "OrgAbusePhone", "OrgAbuseEmail", "OrgAbuseRef","OrgTechHandle","OrgTechName","OrgTechEmail","OrgTechReg", "inetnum");

my $dsn = "DBI:mysql:database=$database;host=$hostname;port=$port";
my $dbh = DBI->connect($dsn, $user, $password);

my $sth = $dbh->prepare(
	"select ip_id, ip, coalesce(arin_from_ip,'') as arin_from_ip from log_resolv where status = 'unresolved' order by ip_id desc limit $nLimit")
	or die "prepare statement failed: $dbh->errstr()";
$sth->execute() or die "execution failed: $dbh->errstr()";

my $dbUpdateH = DBI->connect($dsn, $user, $password);

my $szTmpFile = $szSysRoot."temp/resolv.txt";


while (my $cIpRec = $sth->fetchrow_hashref()) 
{
	my $szIP = $cIpRec->{'ip'};
	my $szIpId = $cIpRec->{'ip_id'};
	print "\n\nIP found: $szIP\n";
	
#	system("nslookup $szIP > $szTmpFile");
#	print "\nnslookup:\n";
#	open(CURRENT_CONF, $szTmpFile) or die("Could not open file: $szTmpFile");

#	foreach my $szLine (<CURRENT_CONF>)  {  
#		print "$szLine";
#	}
#	close (CURRENT_CONF);

	
	system("host $szIP > $szTmpFile");
	print "\nhost:\n";
	open(CURRENT_CONF, $szTmpFile) or die("Could not open file: $szTmpFile");
	my $szLine;
	if ($szLine = (<CURRENT_CONF>)) {
		print "$szLine";
		my $szStatus = "";
		my $szOtherFields = "";

		if ($szLine =~ /NXDOMAIN/) {
			$szStatus = "NXDOMAIN";
			print "Status set up nonexisting domain (NXDOMAIN)\n";
		} else {

			if ($szLine =~ /domain name pointer (\S*)/) {
				print "Domain set to $1\n";
				
				$szStatus = "unknown";
				$szOtherFields = ", domain = '$1'";
				
			} else {
				if ($szLine =~ /has no PTR record/) {
					print "Status set to N/A\n";
					$szStatus = "NA";
				} else {
					if ($szLine =~ /\(SERVFAIL\)/) {
						print "Status set to SERVFAIL\n";
						$szStatus = "SERVFAIL";
					} else {
						
						if ($szLine =~ /connection timed out/) {
							print "Status set to timeout\n";
							$szStatus = "timeout";
						} #else {
						#}
					}
				}
			}
		}

		if ($szStatus ne "")
		{
			my $szIpId = $cIpRec->{'ip_id'};
		
			my $sth = $dbUpdateH->prepare(
				"update log_resolv set status = '$szStatus' $szOtherFields where ip_id = $szIpId")
				or die "prepare statement failed: $dbh->errstr()";
			$sth->execute() or die "execution failed: $dbh->errstr()";
		}
		
	} else {
		print "***** ERROR fetching host string...\n";
	} 
	
	close (CURRENT_CONF);

} 
