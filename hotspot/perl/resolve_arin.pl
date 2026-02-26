#!/usr/bin/perl
# - Parses new ipfm usage log files and moves them to archive
# - Checking the database who should have access and updates the IPTABLES rules

use strict;
use warnings;
use autodie;
use DBI;

my $nLimit = 100;

my $szSysRoot = "/root/wifi/";

my $database = "taransvar";
my $hostname = "localhost";
my $port = "3306";
my $user = "perl";
my $password = "RevSjoko731";

my @cArinTags = ("NetName","NetHandle","Parent","NetType","OriginAS","Organization","RegDate","Updated","Comment","OrgName","OrgId","Address","City","StateProv","PostalCode","Country","OrgAbuseHandle", "OrgAbuseName", "OrgAbusePhone", "OrgAbuseEmail", "OrgAbuseRef","OrgTechHandle","OrgTechName","OrgTechEmail","OrgTechReg", "inetnum");

my $dsn = "DBI:mysql:database=$database;host=$hostname;port=$port";
my $dbh = DBI->connect($dsn, $user, $password);


#update log_resolv set arin_from_ip = null, arin_from_ip_bin = null where arin_from_ip_bin in (83886080,84516576,86453440,93917096);
#delete from log_arin where from_ip_bin in (83886080,84516576,86453440,93917096);

my $sth = $dbh->prepare(
	"select ip_id, ip, coalesce(arin_from_ip,'') as arin_from_ip from log_resolv where arin_from_ip is null order by ip_id desc limit $nLimit")
	or die "prepare statement failed: $dbh->errstr()";
$sth->execute() or die "execution failed: $dbh->errstr()";

my $dbUpdateH = DBI->connect($dsn, $user, $password);

my $szTmpFile = $szSysRoot."temp/resolv_arin.txt";

sub saveArin {		#Params: ip_id, fromIp, toIp, fldList, varlist, dbconnection
	my @list = @_;
	my $szIpId = $list[0];
	my $szFromIp = $list[1];
	my $szToIp = $list[2];
	my $szFldList = $list[3];
	my $szValList = $list[4];
	my $dbUpdateH = $list[5];

	#saveArin($szIpId, $szFromIp, $szToIp, $szFldList, $szValList, $dbUpdateH);		#Params: ip_id, fromIp, toIp, fldList, varlist, dbconnection
	my $sth = $dbUpdateH->prepare(
		"select from_ip, to_ip from log_arin where from_ip_bin = INET_ATON('$szFromIp') and to_ip_bin = INET_ATON('$szToIp')")
		or die "prepare statement failed: $dbh->errstr()";
	$sth->execute() or die "execution failed: $dbh->errstr()";

	if  (my $cIpRec = $sth->fetchrow_hashref()) 
	{
		print "$szFromIp - $szToIp is already stored!\n";
	} else {
		my $szSQL = "insert ignore into log_arin($szFldList) values ($szValList)";
		print "Saving new from IP: $szFromIp\nFlds: $szFldList\nvalues: $szValList\n";
		$sth = $dbUpdateH->prepare($szSQL)
				or die "prepare statement failed: $dbh->errstr()";
		$sth->execute() or die "execution failed: $dbh->errstr()";
	}

	print "Updating log_resov for $szFromIp (id: $szIpId)\n";
	my $szSQL = "update log_resolv set arin_from_ip_bin = INET_ATON('$szFromIp'), arin_from_ip = '$szFromIp' where ip_id = $szIpId";
	$sth = $dbUpdateH->prepare($szSQL)
			or die "prepare statement failed: $dbh->errstr()";
	$sth->execute() or die "execution failed: $dbh->errstr()";
}

my %cFldMapping;


while (my $cIpRec = $sth->fetchrow_hashref()) 
{
	my $szIP = $cIpRec->{'ip'};
	my $szIpId = $cIpRec->{'ip_id'};
	print "\n\nIP found: $szIP\n";
	
	my $szArinFromIP = $cIpRec->{'arin_from_ip'};

	my $szTmpFile = $szSysRoot."temp/arin.txt";
	system("whois -h whois.arin.net $szIP > $szTmpFile");
	
	open my $handle, '<', $szTmpFile;
	chomp(my @lines = <$handle>);
	close $handle;
	my $szFromIp = "";
	my $szToIp = "";
	
	my $szFldList = "";
	my $szValList = "";
	
	foreach (@lines) {
		my $szLine = $_;
		
		#Get keyword: <keyword>: <value>
		if ($szLine =~ /^(\w*):(\s*)(.*)$/) {
			print "$1 - $3\n";
			my $szKeyword = $1;
			
			my $szValue = $3;
			$szValue =~ s/'/`/g;
			#my $szValue =  str_replace($3,'\'','`');
			
			if ($szKeyword eq "NetRange" || $szKeyword eq "inetnum")
			{
				if ($szKeyword eq "inetnum")
				{
					print "****** inetnum found! - saving old data! *****\n";
					saveArin($szIpId, $szFromIp, $szToIp, $szFldList, $szValList, $dbUpdateH);		#Params: ip_id, fromIp, toIp, fldList, varlist, dbconnection
					$szFromIp = $szToIp = $szFldList = $szValList = "";

					#my @cArinTags = ("NetName","NetHandle","Parent","NetType","OriginAS","Organization","RegDate","Updated","Comment","OrgName","OrgId","Address","City","StateProv","PostalCode","Country","OrgTechHandle","OrgTechName","OrgTechEmail","OrgTechReg", "inetnum");

					@cArinTags = ("netname","descr","country","address","abuse-mailbox", "inetnum");
					
					%cFldMapping = ("netname" => "NetName", 
							"descr" => "Organization",
							"country" => "Country",
							"address" => "Address",
							"abuse-mailbox","OrgAbuseEmail"
							);
				}

				if ($szValue =~ /^(.*)\s-\s(.*)$/) {
					$szFromIp = $1;
					$szToIp = $2;
					#print "2: $2, 3: $3, 4: $4\n";
					$szFldList = "from_ip_bin, from_ip, to_ip_bin, to_ip";
					$szValList = "INET_ATON('$szFromIp'), '$szFromIp', INET_ATON('$szToIp'), '$szToIp'";
					print "IP range found! $szValList\n";
				} else {
					print "****** ERROR **** IP RANGE NOT FOUND!\n";
				}
				
			}
			else {
				if ( grep( /^$szKeyword$/, @cArinTags ) ) {
					if (exists $cFldMapping{$szKeyword}) {
						print "Keyword changed from ".$szKeyword." -> ".$cFldMapping{$szKeyword}."\n";
						$szKeyword = $cFldMapping{$szKeyword};
					}
					
					print "Found $szKeyword\n";
					
					if (index($szFldList, ", $szKeyword") == -1){
						$szFldList .= ", $szKeyword";
						$szValList .= ", '$szValue'";
					} else {
						print "Skipping $szKeyword (already found)\n";
					}
				} else {
					#print "Not a keyword: $szKeyword\n";
				}
			}
		} else {
			print "No match: $szLine\n";
		}
	}

	saveArin($szIpId, $szFromIp, $szToIp, $szFldList, $szValList, $dbUpdateH);		#Params: ip_id, fromIp, toIp, fldList, varlist, dbconnection
	$szFromIp = $szToIp = $szFldList = $szValList = "";

} 
