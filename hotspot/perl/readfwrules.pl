#Called from sleepingbeauty.pl before db.pl

use strict;
use warnings;
use autodie;
use DBI;
use Data::Dumper;

use lib ('/root/taransvar/perl');
use func;	#NOTE! See comment above regarding lib..


#my $szSysRoot = "/home/setup/";
my $szSysRoot = "/root/wifi/";

my $szIpTables = "/sbin/iptables";



#NOTE!!!!This section being moved here from php 

my $dbh = getConnection();

#Read setup...

	my $szLAN = "";
	my $szWAN = "";
	my $nLogRejected = 0;

my $sth = $dbh->prepare(
        'select WAN, LAN, cast(logRejected AS UNSIGNED) as logRejected from hotspotSetup')
        or die "prepare statement failed: $dbh->errstr()";
$sth->execute() or die "execution failed: $dbh->errstr()";
if (my $cSetup = $sth->fetchrow_hashref()) 
{
	$szLAN = $cSetup->{'LAN'};
	$szWAN = $cSetup->{'WAN'};
	$nLogRejected = $cSetup->{'logRejected'}+0;
}

if (!length($szLAN) || !length($szWAN)) {
	my $cTaraSetup = getSetup();	
	$szLAN = $cTaraSetup->{'internalNic'};
	$szWAN = $cTaraSetup->{'externalNic'};

	if (!length($szLAN) || !length($szWAN)) {
		print 'Network setup is not fully registered. Can\'t assemble the fw setup!';
	}
}


my %cServices = (
	"SSH" 	=> ["tcp^22"],
	"Samba" 	=> ["tcp^139","tcp^445","udp^137", "udp^138"],
	"HTTP" 	=> ["tcp^80","tcp^443"]
);


my %cDummy;
$cDummy{'SSH'} = ["tcp^22"];
#$cServices[0][0] = "SSH";
#$cServices[0][1] = ["tcp^22"];

$cDummy{'Samba'} = ["tcp^139","tcp^445","udp^137", "udp^138"];
#$cServices[1][0] = "Samba";
#$cServices[1][1] = ["tcp^139","tcp^445","udp^137", "udp^138"];
#$cServices[1][1] = "tcp^139";
#$cServices[1][2] = "tcp^445";
#$cServices[1][3] = "udp^137";
#$cServices[1][4] = "udp^138";

$cDummy{'HTTP'} = ["tcp^80","tcp^443"];
#$cServices[2][0] = "HTTP";
#$cServices[2][1] = ["tcp^80","tcp^443"];
#$cServices[2][1] = "tcp^80";
#$cServices[2][2] = "tcp^443";

#print Dumper(%cServices);

my @cSetupFlds;
$cSetupFlds[0] = "outwardsInside";
$cSetupFlds[1] = "incomingInside";
$cSetupFlds[2] = "outwardsOutside";
$cSetupFlds[3] = "incomingOutside";

$sth = $dbh->prepare(
        "select ruleTemplate, CAST(outwardsInside as UNSIGNED) as outwardsInside, CAST(incomingInside as UNSIGNED) as incomingInside, CAST(outwardsOutside as UNSIGNED) as outwardsOutside, CAST(incomingOutside as UNSIGNED) as incomingOutside from fw_acceptTemplate")
        or die "prepare statement failed: $dbh->errstr()";
$sth->execute() or die "execution failed: $dbh->errstr()";

my $nFound = 0;
my $szLineBreak = "\n";
my $szRules = "";

while (my $cFetched = $sth->fetchrow_hashref())
{
	$nFound++;
	my $szService = $cFetched->{'ruleTemplate'};
		
	foreach my $szFld (@cSetupFlds)
	{
		my $szValue = $cFetched->{$szFld};

		if ($szValue+0)
		{
			my $szDirection = substr $szFld, 0, 8;	#incoming or outwards
			my $szSide = substr($szFld, 8);			#Inside or Outside
			print $szService.": ".$szFld." is set (".$szDirection."-".$szSide.")\n";
			$szRules .= "#".$szService.": ".$szDirection."/".$szSide.$szLineBreak;
				
			my @cSourceDestiny;
			my @cSource;
			my @cDestiny;

			if ($szDirection eq "incoming") {
				@cSourceDestiny = [ ["IN","d","i"] ,["OUT","s","o"]];
				@cSource = ("IN","d","i");
				@cDestiny = ("OUT","s","o");
				
			} else {
				 @cSourceDestiny = [ ["OUT","d","o"], ["IN","s","i"]];	
				@cSource = ("OUT","d","o");
				@cDestiny = ("IN","s","i");
			 }
			 
			my $szDevice = ($szSide eq "Inside"? $szLAN : $szWAN);
				
			#Allow incoming SSH: sudo iptables -A INPUT -p tcp --dport 22 -j DROP
				
			#my @cThisService = $cServices->{$szService};
			#my @cThisService = $cServices{$szService}[0];

			#print "Testing: ".$cServices{$szService}[0]."\n";
			#print "Testing2: ".$cServices{$szService}[1]."\n";

			#if(ref(@cThisService) eq 'ARRAY') {
		#		print "Is erray\n";
		#	} else {
		#		print "Is NOT erray (".ref(@cThisService).")\n";
		#	}
			
			print "\nShould open:\n";
			
			for (my $n =0; $n<10 && exists $cServices{$szService}[$n]; $n++)
			{
				my $szProtocolAndPort = $cServices{$szService}[$n];
				
				print $szProtocolAndPort.$szLineBreak;
				#$cProtPort = explode("^", $szProtocolAndPort);
				my @cProtPort = split /\^/, $szProtocolAndPort;
				if (length($szDevice))
				{
				
					#my $szLine = $szIpTables." -A ".$cSourceDestiny[0][0]."PUT -".$cSourceDestiny[0][2]." ".$szDevice." -p ".$cProtPort[0]." --".$cSourceDestiny[0][1]."port ".$cProtPort[1]." -m state --state NEW,ESTABLISHED,RELATED -j ACCEPT".$szLineBreak;
					#NOTE! The next line has to include "NEW" for incoming Samba to work.. probably not the others...
					#$szLine .= $szIpTables." -A ".$cSourceDestiny[1][0]."PUT -".$cSourceDestiny[1][2]." ".$szDevice." -p ".$cProtPort[0]." --".$cSourceDestiny[1][1]."port ".$cProtPort[1]." -m state --state NEW,ESTABLISHED,RELATED -j ACCEPT".$szLineBreak;
					
					#Above are wrong... interface is always specified as: -i <device>
					#print "Sourcedest: ".$cSourceDestiny[0][0]."/".$cSourceDestiny[0][1];
					
					my $szLine = $szIpTables." -A ".$cSource[0]."PUT -".$cSource[2]." ".$szDevice." -p ".$cProtPort[0]." --".$cSource[1]."port ".$cProtPort[1]." -m state --state NEW,ESTABLISHED,RELATED -j ACCEPT".$szLineBreak;
					
					my $szState;
					if ($szService eq "Samba") {
						$szState = "NEW,ESTABLISHED,RELATED";
					} else {
						$szState = "ESTABLISHED,RELATED";
					}
					
					$szLine .= $szIpTables." -A ".$cDestiny[0]."PUT -".$cDestiny[2]." ".$szDevice." -p ".$cProtPort[0]." --".$cDestiny[1]."port ".$cProtPort[1]." -m state --state ".$szState." -j ACCEPT".$szLineBreak;

					print $szLine;
					$szRules .= $szLine;
				}
					#$szRules .= "#Rules skipped because networks are not properly setup..".$szLineBreak;
			}
		}
	}
	$szRules .= $szLineBreak;
		
}


if ($nLogRejected > 0)
{
		$szRules .= "\n#Logging\n";
		$szRules .= $szIpTables." -N LOGGING\n";
		$szRules .= $szIpTables." -A INPUT -j LOGGING\n";
		$szRules .= $szIpTables." -A OUTPUT -j LOGGING\n";

		$szRules .= "\n#Drop those we don't want in the log\n";
		$szRules .= $szIpTables." -A LOGGING -d 224.0.0.251 -j DROP\n\n";

		$szRules .= $szIpTables." -A LOGGING -m limit --limit 10/min -j LOG --log-prefix \"IPTables-Dropped: \" --log-level 4\n";
		$szRules .= $szIpTables." -A LOGGING -j DROP\n";
	}

my $szRulesFile = $szSysRoot."temp/wwwRulesByPerl.txt";

open my $fh, '>', $szRulesFile or die "Cannot open output.txt: $!";
print $fh $szRules;    # Print each entry in our new array to the file
close $fh;

