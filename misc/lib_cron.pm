#!/usr/bin/perl
#NOTE! Room for improvement: Could run this multiple times on the same file and only import the new ones... E.g Save the last time read and fast forward there before starting importing.. Then maybe once day move the file to "handled" folder (the current functionality).
#There's also a probable problem with because it takes several messages to assemble the total picture.. Meaning maybe we should re-read files processed before saving.
#Processing DHCP IP assignments.
#Should be scheduled for running maybe once an hour or so by:
#sudo crontab -u root -e
#* * * * * sudo perl <insert correct path>/conntrack.pl
package lib_cron;
use strict;
use warnings;
use Exporter;

our @ISA= qw( Exporter );

# these CAN be exported.
our @EXPORT_OK = qw();

# these are exported by default.
our @EXPORT = qw( getActiveLink setCronLibDbh runningAsCron runningBootCheck saveWarning isInternal createDirectories columnExists url_encode getWgetResult sendStatus updateStatus logDmesg updateWhoIsId checkWhoIs handleConntrack sendPendingWgets systemBootedMinutesAgo fixDevicesOldWay updateGlobalDemo startTaraKernelOk startTaraLinkOk startTaraSystemsOk checkDbVersion workshopSetup checkRequests );

use autodie;
use DBI;
use func;

my $dbh = 0;

sub 	setCronLibDbh {
	my ($theDbh) = @_;
	$dbh = $theDbh;
}


my $szActiveLink = "";
my $szInternalLink = "";
my $szExternalIP = "";
my $szInternalIP = "";

sub getActiveLink {
	return $szActiveLink;
}


sub runningAsCron {
        return ($ARGV[0] && ($ARGV[0] eq "cron"));
}

sub runningBootCheck() {
 return ($ARGV[0] && ($ARGV[0] eq "boot"));
}


sub saveWarning {
	my ($szWarning) = @_;
	addWarningRecord($dbh,$szWarning); 
	#my $sth = $dbh->prepare("insert into warning (warning) values (?)") or die "prepare statement failed: $dbh->errstr()";
	#$sth->execute($szWarning) or die "execution failed: $dbh->errstr()";
}

sub isInternal {
	my ($szIp) = @_;
	my @cParts = split(/\./, $szIp);
	#print "parts: ".@cParts.", parts: ".$cParts[2]."\n";
	my $nInternal = (@cParts == 4 && ($cParts[2] eq "50" || $cParts[2] eq "60"));
	#print "****".$szIp." is".($nInternal?"":" NOT")." internal\n";
	return $nInternal;
}

sub createDirectories {
	if (-d getSysRoot()) { 
	    # directory called cgi-bin exists
	    #print "Setup directory already exists...\n";
	}
	else {
	       system("mkdir ".getSysRoot());
	}
	if (-d getSysRoot()."log") {
	    # directory called cgi-bin exists
	    #print "Setup log directory already exists...\n";
	}
	else {
	       system("mkdir ".getSysRoot()."log");
	}
}

sub columnExists {
	my ($szTableName, $szFieldName) = @_;
	my $szSQL = "SHOW COLUMNS FROM `$szTableName` LIKE '$szFieldName'";
	my $sth = $dbh->prepare($szSQL);
	$sth->execute();
	if (my $cRow = $sth->fetchrow_hashref()) {
		if ($cRow->{'Field'} eq $szFieldName)
		{
			print $cRow->{'Field'}." found..\n";
			return 1;
		}
	}
	return 0;
}#sub columnExists

sub url_encode {
	my $rv = shift;
	$rv =~ s/([^a-z\d\Q.-_~ \E])/sprintf("%%%2.2X", ord($1))/geix;
	$rv =~ tr/ /+/;
	return $rv;
}#sub url_encode


sub getWgetResult {
	
	#opendir ( DIR, "." ) || die "Error in opening current dir\n";
	#my @files = grep(/config_update.php/,readdir(DIR));
	#closedir(DIR);
	
	opendir my $dh, "." or die "Could not open current directory for reading: $!\n";	
	my @files = grep(/config_update.php/,readdir($dh));
	closedir($dh);
	
	my $szLastResult = 0;
	foreach my $szFile (@files) {
		#print "*********** There's wget file: $szFile: \n";
		$szLastResult = getFileContents($szFile);
		#print $szLastResult;
		#print "\n<end of file> (and deleting)\n";
		unlink($szFile);
	}
	return trim($szLastResult);
}

sub sendStatus {
	my ($szIP, $szIAm, $szStatus) = @_;
	if (!defined($szIP) || $szIP eq "" || !validIp($szIP)) {
		if (defined($szIP)) {
			print "**** WARNING **** was instructed to send status to invalid ip: $szIP\n";
		} 
		return;
	}
	
	my $szUrl = "http://".$szIP."/script/config_update.php?f=demo&iam=".$szIAm."&status=".url_encode($szStatus);
	print "******* Sending status: URL: $szUrl\n";
	system("wget --level=1 $szUrl > /root/setup/log/wget.txt");
	#asdfasdf
	my $szSQL = "update partnerRouter set demoStatusReplied = now() where ip = inet_aton(?);";
	my $conn = getConnection();
        my $stmt = $conn->prepare($szSQL);
	$stmt->execute($szIP);
	$conn->disconnect;
	
	#wget above downloads the reply from config_udate.php and saves a file... find and delete it...
	
	#opendir my $dir, "config_update*" or die "Cannot open directory: $!";
	#my @files = readdir $dir;
	#closedir $dir;	
	deleteConfigUpdateTempFiles();
}

sub updateStatus { 
	my ($szIam, $szStatus) = @_;
	
	if ($szIam && $szIam ne "") {
		my $szSQL = "update demo set ".$szIam."Checked = now(), ".$szIam."Status = ?";
		#print "SQL: ".$szSQL." (where status is: $szStatus) \n";
		my $sthUpdate = $dbh->prepare($szSQL) or die "prepare statement failed in updateStatus: $dbh->errstr()";
		$sthUpdate->execute($szStatus) or die "execution failed in updateStatus(): $dbh->errstr()";
	}
}

sub changeDemoIpAddress {	#NOTE Only used in this lib, don't export
	my ($szField, $szFrom, $szTo) = @_;
        my $szSQL = "update demo set ip".ucfirst($szField)." = inet_aton(?) where inet_ntoa(ip".ucfirst($szField).") = ?";
	my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
	$sth->execute($szTo, $szFrom) or die "execution failed: $dbh->errstr()";
}


sub logDmesg {
	#************ Capture tarakernel records from dmesg and store in setup->dmesg field
	print "\n\n******************** Updating setup->dmesg **********************\n\n";
	my $szDmesgLogFile = getLogRoot()."dmesg.txt"; 
	system('sudo dmesg | grep tarakernel | grep -v "^[[:space:]]*$" > '.$szDmesgLogFile);
	open my $fhDmesg, '<', $szDmesgLogFile or die "Can't open file $!";
	my $file_content = do { local $/; <$fhDmesg> };
	close $fhDmesg;
	my $szSQL = "update setup set dmesg = ?, dmesgUpdated = now()";
        my $sthDemo = $dbh->prepare($szSQL);
        $file_content=~s/tarakernel\:\s//g;
        $sthDemo->execute($file_content) or die "execution failed: $sthDemo->errstr()";
        my $nDmsgLen = length($file_content); 
        if ($nDmsgLen < 100) {
		print "****** WARNING **** dmesg log is only $nDmsgLen characters:\n$file_content\n";
	}#asdf
}

sub deleteConfigUpdateTempFiles {
	my @files = glob( './config_update*' );
	
	foreach(@files) {
		#File from wget found. Delete it..
		my $szFile = $_;
		print "Found file: $szFile\n";
		unlink($szFile);
	}
}

sub updateWhoIsId {
	my ($dbhLookup, $szToOrFrom, $szIp, $szWhoIsId) = @_;
	my $szSQL = "update traffic set whoIsId = ? where whoIsId is null and ip".$szToOrFrom." = ?";
	my $sthLookup = $dbhLookup->prepare($szSQL);
	$sthLookup->execute($szWhoIsId, $szIp) or die "execution failed: $sthLookup->errstr()";
}

sub checkWhoIs {
	my ($dbh, $nNumberOfWhoIsLookupsPerIteration) = @_;
	#Should be able to check whois from perl.. E.g through: Net::DNS::Resolver->new;
	#However it's easier in PHP because we already have this working...
	#system("wget localhost/checkWhoIs.php");
	my $szSQL = "select trafficId, ipFrom as ipFromN, inet_ntoa(ipFrom) as ipFromA from traffic where whoIsId is null and isLan = b'0' order by trafficId desc limit $nNumberOfWhoIsLookupsPerIteration";
	my $sth = $dbh->prepare($szSQL);
	print "\n\nFinding unhandled IP addresses\n";
	$sth->execute() or die "execution failed: $sth->errstr()";
	my $szLogFile = getLogRoot()."whois.txt";
	my $dbhLookup = 0;
	
	while (my $row = $sth->fetchrow_hashref()) {
		my $szIpFrom = "";
		my $szIpTo = "";
		my $szOrgName = "";
		my $szNetName = "";
		my $szCountry = "";

		#print "\nHandle: ".$row->{'ipFromA'}."\n";
		my $szLookupIp = $row->{'ipFromA'};
		my $nDoLookup = $row->{'ipFromN'};
		my $nTrafficId = $row->{"trafficId"};

		if (!$dbhLookup) {
			$dbhLookup = getConnection();
		}

		#First check if this is a lan address
		if (isLanAddress($szLookupIp)) 
		{
			#This is a lan address... skip.
			#print " LAN address - just setting flag..\n";
			
			$szSQL = "update traffic set isLan = b'1' where trafficId = ?";
			my $sthLookup = $dbhLookup->prepare($szSQL);
			$sthLookup->execute($nTrafficId) or die "execution failed: $sthLookup->errstr()";
			next;
		}	
		
		#Then check if this record is already assigned whoIsId by previous record (could also be checked by storing the looked up IPs)
		$szSQL = "select T.whoIsId, inet_ntoa(T.ipFrom) as ipFromA, name from traffic T left outer join whoIs W on W.whoIsId = T.whoIsId where trafficId = ?";		
		my $sthLookup = $dbhLookup->prepare($szSQL);
		$sthLookup->execute($nTrafficId) or die "execution failed: $sthLookup->errstr()";
		if (my $lookupRow = $sthLookup->fetchrow_hashref()) {
			if (!$lookupRow->{"name"}) {
				print "... not yet looked up...\n";
			} else {
				#print "This record is already assigned whoIsId (probably by previous record same batch).\nIP: ".
				#		$lookupRow->{"ipFromA"}.", ".$lookupRow->{"name"}."\nSkipping to next.\n\n";
				next;
			}
		} else {
			print "***** ERROR - unable to find the traffic record while doing whoIs lookups.\n";
		}

		#Not already found, check if already registered in whoIs table
		$szSQL = "select whoIsId, name, inet_ntoa(ipFrom) as ipFrom, inet_ntoa(ipTo) as ipTo from whoIs where ipFrom <= ? and ipTo >= ?";
		$sthLookup = $dbhLookup->prepare($szSQL);
		$sthLookup->execute($nDoLookup, $nDoLookup) or die "execution failed: $sthLookup->errstr()";
		if (my $lookupRow = $sthLookup->fetchrow_hashref()) {
			if ($lookupRow->{"whoIsId"}) {
				print "This Ip is owned by ".$lookupRow->{"name"}." (".$lookupRow->{"ipFrom"}."-".$lookupRow->{"ipTo"}.")\n";
				updateWhoIsId($dbhLookup, "From", $nDoLookup, $lookupRow->{"whoIsId"}); 
				next;
			} else {
				print "Owner of this IP is not yet looked up (shouldn't get here...)\n";
			}
		} else {
				print "Owner of this IP is not yet looked up..\n";
		}
		
		#It's a new one... look it up..
		system("whois -B ".$szLookupIp." > ".$szLogFile);
		#print "\n";
		open my $handle, '<', $szLogFile;
		chomp(my @lines = <$handle>);
		close $handle;
		
		if (@lines < 5) {
			system("apt-get install whois");# > ".$szLogFile);
			print "\n\n********* ERROR ******* whois seemed not to be installed... So tried to install.. Aborting.\n\n";
			exit;
		}
		
		foreach (@lines) {
			my $szLine = $_;
			
	                if ($szLine =~ /No\ssuch\sfile\sor\sdirectory/ ) {
				#bash: /usr/bin/whois: No such file or directory
				system("apt-get install whois");# > ".$szLogFile);
				print "\n\n********* ERROR ******* whois seemed not to be installed... So tried to install.. Aborting.\n\n";
				exit;
			}
			
	                if ($szLine =~ /^inetnum:\s*(\S+)\s-\s(\S+)/ ) {
	                	print "Found range: $1 - $2\n";
	                	$szIpFrom = $1;
	                	$szIpTo = $2;
	                }
	                if ($szLine =~ /^org-name:\s*(\S+)/ ) {
	                	print "Org name found: $1\n";
	                	$szOrgName = $1;
	                }
	                if ($szLine =~ /^netname:\s*(\S+)/ ) {
	                	print "Org name found: $1\n";
	                	$szNetName = $1;
	                }
	                if ($szLine =~ /^country:\s*(\S+)/ ) {
	                	print "Country found: $1\n";
	                	$szCountry = $1;
	                }
		}
		if ($szOrgName eq "") {
			$szOrgName = $szNetName;
		}

		if ($szIpFrom ne "" && $szIpTo ne "" && $szOrgName ne "" && $szCountry ne "") {
			my $szPuttin = "$szOrgName ($szCountry)";
			$szSQL = "insert into whoIs (ipFrom, ipTo, name) values (inet_aton(?),inet_aton(?),?)";
			my $sthLookup = $dbhLookup->prepare($szSQL);
			$sthLookup->execute($szIpFrom, $szIpTo, $szPuttin) or die "execution failed: $dbhLookup->errstr()";
			#Find id of new record and put that in traffic table.
			my $nLastId = getLastInsertId($dbhLookup);
			
			if ($nLastId) {
				print "created new whoIs record with id: $nLastId\n";
				updateWhoIsId($dbhLookup, "From", $nDoLookup, $nLastId); 
			}
			else {
				print "********* ERROR ******* Never happens..\n";
			}
			
		} else {
			print "**** ERROR - missing fields: $szIpFrom - $szIpTo, owner: $szOrgName ($szCountry)\n";
		}
	}
} #sub checkWhoIs()


sub getNewUnknownUnitId {
	my ($dbh, $szInternalIp) = @_;
	my $szSQL = "insert into unit (ipAddress, description, dhcpClientId) values(inet_aton('$szInternalIp'), 'Unknown not via HDCP?', '')";
	#print "$szSQL\n";
	doExecute($dbh, $szSQL);
	my $nUnitId = getLastInsertId($dbh); 	
	
	#ØT 250130 - try also to insert new session...
	$szSQL = "insert into dhcpSession (clientId, ip, discovered) values ($nUnitId, inet_aton('$szInternalIp'), now())";
	print "$szSQL\n";
	my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
	$sth->execute() or die "execution failed: $sth->errstr()";
	$sth->finish;
	
	return $nUnitId;
}
                                   
sub findWhatUnitHasIp {
	my ($dbh, $szInternalIp) = @_;
	my $szSQL = "select unitId from unit where ipAddress = inet_aton('$szInternalIp') order by lastSeen limit 1";
	my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
	$sth->execute() or die "execution failed: $sth->errstr()";
	my $szUnitId;
	if (my $row = $sth->fetchrow_hashref()) {
		$szUnitId = $row->{'unitId'};
	} else {
		$szUnitId = getNewUnknownUnitId($dbh,$szInternalIp);  
	}
	return $szUnitId; 
}
                                   
                                   
sub handleConntrack {  
	my ($dbh) = @_;
	my $nice_timestamp = getNiceTimestamp();
	my $szGrabFile = getLogRoot()."conntrack/conntrack".$nice_timestamp.".txt";

	#Change here if testing on specific file (NB!In current directory!) (otherwise generates new file - if filename > 35 char)
	#$szGrabFile = $szSysRoot."/log/conntrack.txt";

	if (-d getLogRoot()."conntrack") {
	    # directory called cgi-bin exists
	    print "Setup log/conntrack directory already exists...\n";
	}
	else {
	       system("mkdir ".getLogRoot()."conntrack");
	}

	if (length($szGrabFile) > 35) {
		my $szCmd = "conntrack -L -n > $szGrabFile";
		print "\n\n************** RUNNING:\n$szCmd\n\n";
	        system($szCmd);
	} else {
	        print "**************** Short file name.. assuming test fine.. Dropping getting new file..\n";
	}

	my $dbLookupH = getConnection();

	if (!$dbLookupH)
	{
		print "Error connecting (maybe database is not yet installed!\n";
		exit;
	}
	print "\n************* HANDLING PORT ASSIGNMENTS **********************\n\n";

	open my $info, $szGrabFile or die "Could not open $szGrabFile: $!";

	my $nExists = 0;
	my $nNewOnes = 0;
	my $nNoMatch = 0;
	my $nReturnPortDiffers = 0;
	my $szGatewayIp = "";

	while( my $szLine = <$info>)  {
	        # Matching [ASSURED] - records
	        #tcp      6 48 CLOSE_WAIT src=192.168.50.100 dst=172.217.170.163 sport=40968 dport=443 src=172.217.170.163 dst=192.168.100.10 sport=443 dport=40968 [ASSURED] mark=0 use=1

	        #udp      17 52 src=192.168.50.100 dst=8.8.8.8 sport=48886 dport=443 src=8.8.8.8 dst=192.168.100.10 sport=443 dport=48886 [ASSURED] mark=0 use=1
        
	        my $bMatchFound = 0;
	        my $nSourcePort, my $nDestPort, my $szSourceIp, my $szDestIp, my $szRetSourceIp, my $szRetDestIp, my $nRetSourcePort, my $nRetDestPort;        

	        if ($szLine =~ /tcp\s*(\d+)\s(\d+)\s(\w*)\ssrc\=(\S*)\sdst=(\S*)\ssport=(\d*)\sdport=(\d*)\ssrc\=(\S*)\sdst=(\S*)\ssport\=(\d*)\sdport=(\d*)(.+)/)        	
		{
		        $bMatchFound = 1;
		        print "$1|$2|$3|$4|$5|$6|$7|$8|$9|$10|$11|$12\n"; 
		        $szSourceIp = $4;
		        $szDestIp = $5;
		        $nSourcePort = $6;
		        $nDestPort = $7;
		        $szRetSourceIp = $8;
		        $szRetDestIp = $9;
		        $nRetSourcePort = $10;
		        $nRetDestPort = $11;
		} else {
	                # Matching [UNREPLIED] - records
	                if ($szLine =~ /tcp\s*(\d+)\s(\d+)\s(\w*)\ssrc\=(\S*)\sdst=(\S*)\ssport=(\d*)\sdport=(\d*)\s\S*\ssrc\=(\S*)\sdst=(\S*)\ssport\=(\d*)\sdport=(\d*)(.+)/)
	                {
		#tcp      6 95 ESTABLISHED src=192.168.50.100 dst=4.152.45.219 sport=44228 dport=443 [UNREPLIED] src=4.152.45.219 dst=192.168.100.19 sport=443 dport=44228 mark=0 use=1
	        	        $bMatchFound = 1;
	        	        print "$1|$2|$3|$4|$5|$6|$7|$8|$9|$10|$11|$12\n"; 
	        	        $szSourceIp = $4;
	        	        $szDestIp = $5;
	               	        $nSourcePort = $6;
	        	        $nDestPort = $7;
	        	        $szRetSourceIp = $8;
	        	        $szRetDestIp = $9;
	        	        $nRetSourcePort = $10;
	        	        $nRetDestPort = $11;
	                }
		
		}
	
		if ($bMatchFound) {
		       if ($szGatewayIp eq "")
		       {
		                $szGatewayIp = $szRetDestIp; 
		       } else {
		                if ($szGatewayIp ne $szRetDestIp) {
		                        print "Return destination IP should always be the gateway IP ($szGatewayIp <> $szRetDestIp)....\n"; 
					saveWarning("Return destination IP should always be the gateway IP ($szGatewayIp <> $szRetDestIp)....");
		                }
		       }

		        print "$szSourceIp:$nSourcePort -> $szDestIp:$nDestPort | $szRetSourceIp:$nRetSourcePort -> $szRetDestIp:$nRetDestPort\n"; 
		 #       print "$szLine\n";

			my $szInternalIp;
			my $nInternalPort;
			
			if (isInternal($szSourceIp)) {
				$szInternalIp = $szSourceIp;
				$nInternalPort = $nSourcePort;
			} else {
				saveWarning("**** ERROR **** Source IP is not internal when processing conntrack..");
				if (isInternal($szRetDestIp)) {
					$szInternalIp = $szRetDestIp;
					$nInternalPort = $nRetDestPort;
				} else {
					if (isInternal($szRetSourceIp)) {
						$szInternalIp = $szRetSourceIp;
						$nInternalPort = $nRetSourcePort;
					} else {
						if (isInternal($szDestIp)) {
							$szInternalIp = $szDestIp;
							$nInternalPort = $nDestPort;
						} else {
							saveWarning("****** ERROR ***** None are internal when saving NAT port assignment. Aborting.");
							return;
						}
					}
				}
			}

		       #Skip saving if last use of same port was same IP.. NOTE! Should also check if it's same mac or other ID...
	               my $szSQL = "select portAssignmentId, inet_ntoa(ipAddress) as ip, unitId from unitPort where port = $nInternalPort order by portAssignmentId desc limit 1";

	                my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
	                print "$szSQL\n";
	                $sth->execute() or die "execution failed: $sth->errstr()";
	                my $row;
	                my $nFound = 0;
	                my $szUnitId = "NULL";

	                if ($row = $sth->fetchrow_hashref()) {
	                        if ($row->{'ip'} eq $szInternalIp) {
	                        	#Last use of this port was same IP... 
	                                print "Port $nInternalPort recently used by same IP ($szInternalIp). Skipping saving duplicate record.\n";
	                                $nFound = 1;
					$szUnitId = $row->{'unitId'}                                
	                        } else {
	                        	print "Other ip (".$row->{'ip'}.") last used this port. Save new for ".$szInternalIp."\n"; 
	                        }
	                }

	                if (!$nFound)
	                {
	                	#NOTE! This means that this port is not registered before or registered on other unit...
	                        #Find the unitId
	                        #NOTE - ***** This may be wrong... maybe other unit had that IP....(there should still be a session....)
	                        $szSQL = "select clientId from dhcpSession where ip = inet_aton('$szInternalIp') order by sessionId desc limit 1";  
	                        my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
	                        $sth->execute() or die "execution failed: $sth->errstr()";
	                        if (my $cRec = $sth->fetchrow_hashref()) {
	                        	$szUnitId = $cRec->{'clientId'};
	                        	print "******** Found the unitId: $szUnitId\n"; 
	                        } else {
#NOTE!!!                        	#NOTE! Don't just create new UNIT here... Get new session from 
#NOTE!!!				$szUnitId = getNewUnknownUnitId($dbh, $szInternalIp);
					$szUnitId = findWhatUnitHasIp($dbh, $szInternalIp);
	                        	my $szMsg = "******** WARNING - Port assignment found for unknown unit. New created with id $szUnitId. Due to static IP?";
	                        	print "$szMsg\n";
	                        	addWarningRecord($dbh, $szMsg); 
	                        }

	        	        $szSQL = "insert into unitPort (unitId, ipAddress, port) values ($szUnitId, inet_aton('".$szInternalIp."'), ".$nInternalPort.")";
	        	        doExecute($dbh, $szSQL); 
#NOTE! This is wrong.. unitPort, not unit...        	        $szUnitId = "".getLastInsertId($dbh);
	                        #$sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
	                        print "Saving external port assignment: $szInternalIp - $nInternalPort.\n";
	                        #print "$szSQL\n";
	                        #$sth->execute() or die "execution failed: $sth->errstr()";
	                } else {
	                	#NOTE! This means that this port was last used by the same unit....
	                	#port assignment found but unitId may still be blank.....
 #                       	addWarningRecord($dbh, "**** WARNING *** Port assignment without unit. This shouldn't happen."); 
				
#ØT 250130 - this is very wrong...	$szUnitId = getNewUnknownUnitId($dbh, $szInternalIp);

	                }
                
	                #Update the unit table lastSeen
	                if ($szUnitId ne "NULL") {
	                	$szSQL = "update unit set lastSeen = now() where unitId = ?";
	        	        #doExecute($dbh, $szSQL); 
				$sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
	                	$sth->execute($szUnitId) or die "execution failed: $sth->errstr()";

				$szSQL = "update unitPort set lastSeen = now() where portAssignmentId = ?";
	        	        #doExecute($dbh, $szSQL); 
				$sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
	                	$sth->execute($szUnitId) or die "execution failed: $sth->errstr()";
	                } else {
	                	my $szMsg = "****** ERROR - unit not found.. (this shouldn't happen anymore)...."; 
	                	print "$szMsg\n";
                        	addWarningRecord($dbh, $szMsg); 
	                }

	                if (($nSourcePort ne $nRetDestPort) || ($szDestIp ne $szRetSourceIp) || ($nDestPort ne $nRetSourcePort))
	                {
	                	my $szWarn = "********* WARNING! Traffic not returned back to same port...! \n$szLine";
	                        print "$szWarn\n\n";
                        	addWarningRecord($dbh, $szWarn); 
	                        $nReturnPortDiffers++;
	                }
		        print "\n"; 
  
		} else 
		{
		        if ($szLine =~ /udp\s*(\d+)\s(\d+)\ssrc\=(\S*)\sdst=(\S*)\ssport=(\d*)\sdport=(\d*)\ssrc\=(\S*)\sdst=(\S*)\ssport\=(\d*)\sdport=(\d*)(.+)/)
	                        #udp      17 52 src=192.168.50.100 dst=8.8.8.8 sport=48886 dport=443 src=8.8.8.8 dst=192.168.100.10 sport=443 dport=48886 [ASSURED] mark=0 use=1
	        	{
	                        print "udp matching (skipping): $3:$5 -> $4:$6\n\n";                        	
		        }
		        else
		        {
		                print "No match: $szLine\n"; 
		                $nNoMatch++;
		        }
		}
	}

	close $info;

	print "$nNewOnes records inserted. $nExists were already stored.. No match: $nNoMatch\n";
	if ($nReturnPortDiffers)
	{
	        print "************** WARNING ********* One packet not sent back to origin.... Check for warnings\n\n";
	}
} #sub handleConntrack()



sub sendPendingWgets {
	my $dbh = getConnection();
	my $szSQL = "select wgetId, url, category, regardingId from pendingWget where handled is null";
        my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
	$sth->execute() or die "execution failed: $sth->errstr()";
	my $dbhUpdate = 0;
	print "\nProcessing pending wgets:\n";
	
	while (my $row = $sth->fetchrow_hashref()) {
		my $szLogFile = getLogRoot()."wget.txt";
		system("wget ".$row->{"url"}." > ".$szLogFile);
		my $szLog = getFileContents($szLogFile);
		print "url: ".$row->{"url"}."\n\nReturned: ".$szLog."\n";
		
		if (!$dbhUpdate) {
			$dbhUpdate = getConnection();
		}
		my $szSQL = "update pendingWget set handled = now() where wgetId = ".$row->{"wgetId"};
	        my $sth = $dbhUpdate->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
		$sth->execute() or die "execution failed: $sth->errstr()";
        }
	if ($dbhUpdate) {
		$dbhUpdate->disconnect;
	}
}

sub systemBootedMinutesAgo {
	my ($nMinimumMinutes) = @_;
	my $comm = getConnection();
	my $cSetup = getSetup($comm);
	my $nLastUptime = $cSetup->{"uptime"};
	
	my $nUptime = uptime();
	
	my $nBootedMinimuSecondsAgo = $nMinimumMinutes * 60; 
	print "Old uptime: $nLastUptime, new: $nUptime, min sec after boot: $nBootedMinimuSecondsAgo\n";
	
	my $szSQL = "update setup set uptime = ?";
        my $sth = $comm->prepare($szSQL) or die "prepare statement failed: $comm->errstr()";
	$sth->execute($nUptime) or die "execution failed: $comm->errstr()";
	$comm->disconnect;
	
	if ($nUptime < $nLastUptime && $nUptime > $nBootedMinimuSecondsAgo) { 
		print "Booted since last time... Run network setup.\n";
		return 1;
	} else {
		return 0;
	}
}


sub checkChangeSomething {
	my ($dbh, $szWhat, $szWhatField, $szCurrent, $szNew) = @_;
	
	if (!defined($szNew)){ $szNew = "";}
	if (!defined($szCurrent)){ $szCurrent = "";}
	
	if ($szCurrent ne $szNew && $szNew ne "") {
		my $szChangeHandled = ($szWhat eq "IP" ? ", handled = b'0'" : "");
		my $szNewDBval = ($szWhat eq "IP" ? "inet_aton(?)" : "?");
		my $szSQL = "update setup set $szWhatField = $szNewDBval $szChangeHandled";
		my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
		$sth->execute($szNew) or die "execution failed: $dbh->errstr()";
		return 1;
	} else {
		return 0;
	}
}




sub fixDevicesOldWay {
	#Find external and internal (if any) IP address and put in the setup table
	my $szLogFile = getLogRoot."log.txt";
	system("ip route > $szLogFile");

	open my $handle, '<', $szLogFile;
	chomp(my @lines = <$handle>);
	close $handle;

	print "Scanning through devices:\n";
	foreach (@lines) {
		my $szLine = $_;
		#print "Line read:->$szLine<-\n";

        	#Check if this is the default route giving internet (and grab device and IP address)
        	#Format: default via 192.168.100.1 dev wlp0s20f3 proto dhcp src 192.168.100.19 metric 600 
		if ($szLine =~ /^default\s.+\sdev\s(\w+)\sproto\sdhcp\ssrc\s(\S+)(\.*)/)
		{
		        print "Found external connection: $1 - $2\n";
	                $szActiveLink = $1;
        	        $szExternalIP = $2;
		} 
		#Format: default via 192.168.39.1 dev wlp2s0 proto static metric 600

		if ($szLine =~ /^default\s.+\sdev\s(\w+)\sproto\s(\.*)/)
		{
        		print "Found external connection: $1 - $2\n";
        	       	$szActiveLink = $1;
        	       	$szExternalIP = "";	#This format doesn't include IP address... Find when the same link shows up below...
		} 

		if ($szLine =~ /^(.+)\sdev\s(\w+)\s(.*)\ssrc\s(\S+)\s.*/)
			#192.168.1.0/24 dev wlp2s0 proto kernel scope link src 192.168.1.9 metric 600 
			#192.168.50.0/24 dev enp1s0 proto kernel scope link src 192.168.50.1 
        	{
        		if ($szActiveLink ne $2) {
		        	#Check if marked as linkdown
		        	print "found other device...$2s, ip: $4 \n";
		        	my $szDevice = $2;
		        	my $ip = $4;
		        	if ($szLine =~ /linkdown/)
		        	{
		        		print "Device found: $szDevice, but skipped: Line is down\n";
		        	}
		        	else
		        	{
		        		if ($szActiveLink eq "") {
		        			$szActiveLink = $szDevice;
		        		} else {
		        		        if ($szDevice ne $szActiveLink && index($szDevice, "docker") == -1) {
		        		                $szInternalLink = $szDevice;
		        		                $szInternalIP = $ip;
		        		                print "Found internal network device: $szInternalLink\n"; 
		        		        }
		        		}
        			}
        		}
        		else
        		{
        			#This is the active link. Check if IP address has already been registered.... (isn't always)
        			if ($szExternalIP eq "") {
        				$szExternalIP = $4;
		        		print "External IP found on 2nd attempt: $szExternalIP\n";
        			}
			}
		}
	}
	my $setIntern = "";
	my $setExtern = "";

	if ($szInternalIP eq "") {
		        print "Seems like there's no internal network.\n";
	        $setIntern = "NULL"
	} else {
	        print "Internal nett: ($szInternalLink): $szInternalIP\n";
	        $setIntern = "inet_aton('$szInternalIP')"
	}

	if ($szExternalIP eq "") {
	        print "***** WARNING! You seem not to be online!\n";
	        $setExtern = "NULL"
	} else {
	        print "External nett: ($szActiveLink): $szExternalIP\n";
	        $setExtern = "inet_aton('$szExternalIP')";
	}

	my $cSetup = getSetup($dbh);

	if ($cSetup) #Defined in func.pm - Note: Only fetched selected fields
	{
		my $bChanged = 0;
		my $szCurrentExternal = $cSetup->{'adminIP'};
		my $szCurrentInternal = $cSetup->{'internalIP'};
		my $szCurrentExternalNic = $cSetup->{'externalNic'};
		my $szCurrentInternalNic = $cSetup->{'internalNic'};

		checkChangeSomething($dbh, "NIC", "internalNic", $szCurrentInternalNic, $szInternalLink);	
		checkChangeSomething($dbh, "NIC", "externalNic", $szCurrentExternalNic, $szActiveLink);	

		if ($szExternalIP ne "" && $szCurrentExternal ne $szExternalIP) {
			print "****************** About to change adminIP: $szCurrentExternal -> $setExtern\n";  
			my $szSQL = "update setup set adminIP = $setExtern, internalIP = $setIntern, handled = b'0'";
			my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
			$sth->execute() or die "execution failed: $dbh->errstr()";
			my $szMsg = "";

			if ($szInternalIP ne "") {
				if (checkChangeSomething($dbh, "IP", "internalIP", $szCurrentInternal, $szInternalIP)) {
		                	$szMsg = "Internal IP addresses changed from $szCurrentInternal to $szInternalIP";
				}
			}
			if ($szExternalIP ne "") {
				if (checkChangeSomething($dbh, "IP", "adminIP", $szCurrentExternal, $szExternalIP)) {
		                	$szMsg = "External IP addresses changed from $szCurrentExternal to $szExternalIP";
				}
			}
		
			if ($szMsg ne "") {
		        	saveWarning($szMsg);
		                print "$szMsg\n";        		
			}
        	
	        	#Change from the old IP to the new for all active demos (regardless of what rold the computer has in this demo)... 
	        	changeDemoIpAddress("targetHost", $szCurrentExternal, $szExternalIP);
	        	changeDemoIpAddress("botHost", $szCurrentExternal, $szExternalIP);
	        	changeDemoIpAddress("bot", $szCurrentExternal, $szExternalIP);
		} 
	
	 	#if ($szCurrentInternal && $setIntern ne "" && $szCurrentInternal ne $szInternalIP) 
	 	if ($szInternalIP ne "" && $szCurrentInternal ne $szInternalIP) 
	 	{
	 		#print "******** Should update
			my $szSQL = "update setup set internalIP = $setIntern, handled = b'0'";
			my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
			$sth->execute() or die "execution failed: $dbh->errstr()";
	
	                my $szLog = "\nInternal IP addresses changed from $szCurrentInternal to $szInternalIP\n";
	        	saveWarning($szLog);
	                print $szLog        		
		}

		if ($szCurrentExternalNic eq "") {
			my $szSQL = "update setup set externalNic = '$szActiveLink', handled = b'0'";
			my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
			$sth->execute() or die "execution failed: $dbh->errstr()";
	                my $szLog = "\nExternal Nic changed from $szCurrentExternalNic to $szActiveLink\n";
	        	saveWarning($szLog);
	                print $szLog        		
		}

		if (!defined($szCurrentInternalNic) || $szCurrentInternalNic eq "") {
			if ($szInternalLink ne "") {
				my $szSQL = "update setup set internalNic = '$szInternalLink', handled = b'0'";
				my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
				$sth->execute() or die "execution failed: $dbh->errstr()";
		                my $szLog = "\nInternal Nic changed from $szCurrentInternalNic to $szInternalLink\n";
		        	saveWarning($szLog);
		                print $szLog
		        } else {
		        	print "Still no additional network found for connected units.\n"; 
		        }
		}

		#if ($szInternalLink ne "") {
		#        my $szLog = "\nNics changed to $szActiveLink (external) and $szInternalLink (internal)\n";
	        #	saveWarning($szLog);
		#        print $szLog;
		#}
	
		if (!$bChanged) {
			print "\nIP addresses are unchanged: $szExternalIP/$szInternalIP\n\n";
		}
	
	} else {
	        print getNiceTimestamp().": crontasks.pl: **** ERROR *** couldn't read setup!\n";
	        exit;
	}
}#sub fixDevicesOldWay()


sub getDemo {
	#NOTE! This is old code... There is no longer only one demo but up to one per logged in user... 
	my $sthDemo = $dbh->prepare('SELECT *, inet_ntoa(ipTargetHost) as targetHost, inet_ntoa(ipBotHost) as botHost, inet_ntoa(ipBot) as bot from demo');
	$sthDemo->execute() or die "execution failed: $sthDemo->errstr()";
	return $sthDemo->fetchrow_hashref()
}

sub updateGlobalDemo {
	print "************* NOTE! This section still doesn't reflect that there's several demo rows per computer....\n";

	my $cDemoRow = 0;

	#Delete old demos - the user kan set up a new one tomorrow...
	my $sthDeleteDemos = $dbh->prepare("delete from demo where lastVisited < DATE_SUB(NOW(), INTERVAL '1' MINUTE)");
	$sthDeleteDemos->execute() or die "execution failed: $sthDeleteDemos->errstr()";

	#Now see if there's any active demo left...
	$cDemoRow = getDemo();

	#NOTE! This is old code... There is no longer only one demo but one per logged in user... 
	if ($cDemoRow) 
	{
		print "Checking network: External: $szExternalIP, $szActiveLink, Internal: $szInternalLink, $szInternalIP\n";
	
		my $szIam = $cDemoRow->{'iAm'};
		my $szStatus = "";
		#First check if network devices are ok... 
		if ($szExternalIP eq "" || $szActiveLink eq "") {
				$szStatus = "The computer appears not to have Internett. Nettwork: $szActiveLink, IP: $szExternalIP";
		}

		if ($szStatus eq "" && $szIam eq "botHost")	#NOTE! status field is only 255 char... So can't hold the whole story.... 
		{
			if ($szInternalLink eq "" || $szInternalIP eq "") {
				if (length($szStatus)) {
					$szStatus .= "<br><br>";
				}
				$szStatus .= "The computer is registered as \"bot-host\". It should have a connected wifi or switch where other units can connect. However, there appears not to be more than one network device. You can try to run \"sudo perl misc/setup_network.pl\" to set it up.";
			}
			
		}
		
		if ($szStatus eq "") {
			$szStatus = "ok";
		}

		updateStatus($szIam, $szStatus);
		#Replaced by call to updateStatus() my $szSQL = "update demo set ".$szIam."Checked = now(), ".$szIam."Status = ?";
		#print $szSQL."\n";
		#my $sthUpdate = $dbh->prepare($szSQL);
		#$sthUpdate->execute($szStatus);

		#Send this status field to the other computers for displaying on the Demo page on dashboard

		if ($cDemoRow->{'iAm'} eq "targetHost") {
			sendStatus($cDemoRow->{'botHost'}, $cDemoRow->{'iAm'}, $szStatus);
		} else
		{
			if ($cDemoRow->{'iAm'} eq "botHost") {
				sendStatus($cDemoRow->{'targetHost'}, $cDemoRow->{'iAm'}, $szStatus);
				sendStatus($cDemoRow->{'bot'}, $cDemoRow->{'iAm'}, $szStatus);
			}
			else {
				if ($cDemoRow->{'iAm'} eq "bot") {
					sendStatus($cDemoRow->{'targetHost'}, $cDemoRow->{'iAm'}, $szStatus);
					sendStatus($cDemoRow->{'botHost'}, $cDemoRow->{'iAm'}, $szStatus);
				} else {
					print "********** ERROR - iAm field should be targetHost, botHost or bot. Is: ".$cDemoRow->{'iAm'}."...\n";
				}
			}
		}
	}



#}
#sub sendUpdateToPartners {


	print "\n#******** Send update message to partner routers (maybe same as ping???)********\n\n";
	my $szAge = "DATE_SUB(NOW(), INTERVAL '5' MINUTE)";
	my $szSQL = "select inet_ntoa(ip) as ip from partnerRouter where (partnerStatusReceived is null or partnerStatusReceived < $szAge) and (partnerStatusReplied is null or partnerStatusReplied < $szAge)";
	my $sthDemo = $dbh->prepare($szSQL);
	$sthDemo->execute() or die "execution failed: $sthDemo->errstr()";
	my $nFound = 0;
	while (my $row = $sthDemo->fetchrow_hashref()) {
		my $szUrl = "http://".$row->{"ip"}."/script/config_update.php?f=partner";
		print "******* Sending status: URL: $szUrl\n";
		system("wget $szUrl --tries=1 > /root/setup/log/wget.txt");
		my $szReply = getWgetResult();
		if ($szReply eq "ok") {
			my $szSQL = "update partnerRouter set partnerStatusReplied = now() where ip = inet_aton(?);";
			my $conn = getConnection(); 
        		my $stmt = $conn->prepare($szSQL);
			$stmt->execute($row->{"ip"});
			$conn->disconnect;
			print "Partner returned ok (config_update.php). Updating database.\n";
		} else {
			my $szMsg = "*** ERROR **** config_update.php returned: $szReply";
			addWarningRecord(0, $szMsg);
			print "$szMsg\n";
		}
		#deleteConfigUpdateTempFiles();
		$nFound++;
	}
	if (!$nFound) {
		print "******* No partners found to poll\n";
	}



#}
#sub handleRequestsForDmsg {

	# *********************** Read requests into array so can check dmesg lines to see if should send
	print "Handle requests for dmesg\n";
	$szSQL = "select inet_ntoa(ip) as ip from requestDmesg where unix_timestamp(now()) - unix_timestamp(registered) < 24*60*60";
	$sthDemo = $dbh->prepare($szSQL);
	my @requests = ();	#create empty array
	$sthDemo->execute() or die "execution failed: $sthDemo->errstr()";
	my $nNdx = 0;
	my $log = "";
	my @ipLastLinesArrays = ();
	while (my $row = $sthDemo->fetchrow_hashref()) {
		push @requests, $row->{'ip'};	
		$log .= ",ip: ".$requests[$nNdx];
		$ipLastLinesArrays[$nNdx] = "";
		$nNdx++;
	}
	# *************** Add IP addresses involved in any active demo ****
	$szSQL = "select distinct ip from
		 (
		select inet_ntoa(ipTargetHost) as ip from demo where activeDemo = b'1'
		union
		select inet_ntoa(ipBotHost) as ip from demo where activeDemo = b'1' 
		union
		select inet_ntoa(ipBot) as ip from demo where activeDemo = b'1'
		) as T1";
			
	$sthDemo = $dbh->prepare($szSQL);
	$sthDemo->execute() or die "execution failed: $sthDemo->errstr()";
	@ipLastLinesArrays = ();
	while (my $row = $sthDemo->fetchrow_hashref()) {
		if (grep { $_ eq $row->{'ip'} } @requests) {
			#$row->{'ip'} is already in the array... do nothing.
		} else {
			#add the new ip to the array
			push @requests, $row->{'ip'};	
			$ipLastLinesArrays[$nNdx] = "";
			print "Added from active demo: ".$row->{'ip'}."\n";
			$nNdx++;
		}
	}

	#Just testing testing.....
	$szSQL = "update demo set botStatus = ?";
	$sthDemo = $dbh->prepare($szSQL);
	$sthDemo->execute($log) or die "execution failed: $sthDemo->errstr()";
	my @linesFound = ();

	#********************* Scan through dmesg messages and send them to relevant partner computers as requested ****
	#Don't think the role of this computer matters... as long as someone requests the status 
	#NOTE! What about security? This is just for demo computers.... setup->doDemos
	#my $szIam = $cDemoRow->{'iAm'};
	my $nNewest = -1;
	#if ($szIam && ($szIam eq "targetHost" || $szIam eq "botHost")) 

	my $szDmesgLog = getLogRoot()."dmesg.txt"; 
	system("sudo dmesg | grep -v \"^[[:space:]]*\$\" > $szDmesgLog");
	open my $handle, '<', $szDmesgLog;
	chomp(my @lines = <$handle>);
	close $handle;
	my $bSuspiciousPartnerTrafficFound = 0;
	my $nFirstTime = 0;
	my $nLastTime = 0;
	my $nLastTimeStartDbMonitor = 0;
	my $nPrinted = 0;
	my $szStatus = "";
	print "Scanning through dmesg lines:\n";
	my @lastLines = ();
	@lines = reverse (@lines);
	
	foreach (@lines) {	#Scan through the dmesg lines in reverse order (reversed by reverse())
		my $szLine = $_;
	
		#First find the time... 
		#[181000.nnnn]	
                if ($szLine =~ /^\[(\d+)\.*/ ) {
               		$nLastTime = $1;
                	if ($nNewest == -1) {
                		$nNewest = $nLastTime;
                	}
                }
                if ($szLine =~ /^\[(\d+)\.\d+\]\starakernel\:\sStart\staralink\sto\ssend\sconfiguration\.*/ ) {
			#Before: [180977.224661] tarakernel: Start taralink to send configuration! 10.0.0.1 -> 192.168.39.195  
			#[180977.224661] tarakernel: Start taralink to send configuration! 10.0.0.1 -> 192.168.39.195
                	$nLastTimeStartDbMonitor = $nLastTime;
                	print "Start taralink ($nLastTime)\n";
                	last;
                }

                if ($szLine =~ /^\[(\d+)\.\d+\]\starakernel\:\s(.*)/ ) {
			#[187836.724002] tarakernel: POST ROUTING Outbound for partner (tagging is handled in forwarding):  (192.168.39.195 -> 192.168.39.198) 
                	if ($nPrinted > 6) {
                		last;
                	}

 			print "Found: $2\n";
			if (length($szStatus) > 0) {
               			$szStatus .= "<br>";
               		}

               		my $nSekAgo = $nNewest - $1; 
               		my $szStatusNow = "($nSekAgo sek ago) $2"; 
               		$szStatus .= $szStatusNow;
                	$nPrinted++;
                	
                	#******** Instead of just dumping all lines to screen, should send them to relevant reciepients...
			for (my $n = 1; $n < @requests; $n++) {
				my $szIp = $requests[$n];
				my $nPos = index ($szLine, $szIp);
				print "Checking if IP $szIp found in: $szLine\n"; 
				if ($nPos >= 0){
					no warnings 'uninitialized';
					print "IP $szIp found in: $szLine\n"; 
					if (length($ipLastLinesArrays[$n])) {
						$ipLastLinesArrays[$n] .= "<br>";
					}
					
					$ipLastLinesArrays[$n] .= $szStatusNow; 
					# ... put it in array if found and create array if not yet exists....
				}				
			}
                	
                	if (!$bSuspiciousPartnerTrafficFound) {
				#asdf  If the partner setup is wrong, then forwarded traffic will not be picket up by tarakernel
				
			}
		}
		else {
			#print "Not found in: ".substr($szLine, 0, 30)."\n";
		}
        }

        my $nSecsSinceStartAbMonitor = $nNewest - $nLastTimeStartDbMonitor;
        print "Last reminder to start taralink was $nSecsSinceStartAbMonitor seconds ago.\n";
        if ($nSecsSinceStartAbMonitor < 30)
        {
        	$szStatus = "You need to start the taralink program <b>on the \"target-host\"</b> to send the setup to tarakernel module for it to operate.";
        } 

	updateStatus($cDemoRow->{'iAm'}, $szStatus);
		
	if (defined($cDemoRow->{'iAm'}) && $cDemoRow->{'iAm'} eq "targetHost") {
		sendStatus($cDemoRow->{'botHost'}, $cDemoRow->{'iAm'}, $szStatus);
	} else {
		sendStatus($cDemoRow->{'targetHost'}, $cDemoRow->{'iAm'}, $szStatus);
	}

	print "Info requests:\n";
	for (my $n=0;$n<@requests; $n++) {
		print "IP: ".$requests[$n]."\n";
		print "$ipLastLinesArrays[$n]\n";
		
		if ($requests[$n] eq $szExternalIP || $requests[$n] eq $szInternalIP) {
			#Don't send to myself.. just put in database..
			my $szSQL = "update demo set botHostStatus = ? where inet_ntoa(ipBotHost) = ? and activeDemo = b'1';";
			my $sthDemo = $dbh->prepare($szSQL);
			$sthDemo->execute($ipLastLinesArrays[$n], $requests[$n]) or die "execution failed: $sthDemo->errstr()";
			
			$szSQL = "update demo set targetHostStatus = ? where inet_ntoa(ipTargetHost) = ? and activeDemo = b'1';";
			$sthDemo = $dbh->prepare($szSQL);
			$sthDemo->execute($ipLastLinesArrays[$n], $requests[$n]) or die "execution failed: $sthDemo->errstr()";

			#Probably irrelevant to do this on the bot...so skipping
		} else {
			#This is other computer... Send it to that computer using wget..
			print "**** WARNING *** Dropping sending dmesg log to other computer \n";
			#sendStatus($requests[$n], "dummyIam", $ipLastLinesArrays[$n]);
		}
	}
}


sub startTaraKernelOk {
	if (!moduleRunning("tarakernel")) {
		system ("modprobe tarakernel");
		return moduleRunning("tarakernel");
	}
	return 1;	
}

sub startTaraLinkOk {
	#NOTE doesn't work without: sudo apt-get install dbus-x11
	if (programRunning("taralink")) {
		my $szMsg = "Taralink seems already to be running (may be wrong, though)";
		addWarningRecord(0,$szMsg);
		print "$szMsg\n";
		return 1;	#Alredy running.
	}
	
#	my $szPath = getSourceRoot()."taralink/taralink";
	my $szPath = "/root/taransvar/taralink";

	#This command launches taralink in separate terminal. Problem is it normally runs when no user is logged in... so it's not working
	#my $szCmd = 'dbus-launch gnome-terminal -- "'.$szPath.'" &';	#Requires sudo apt-get install dbus-x11 (???)

	#Start taralink as background process
	my $szCmd = "$szPath &";

	my $szMsg = "Trying to start Taralink: $szCmd\n";
	addWarningRecord(0,$szMsg);
	print "$szMsg\n";
	system($szCmd);
	return programRunning("taralink");
}

sub startTaraSystemsOk {
	my $bFailed = 0;
	if (!startTaraKernelOk()) {
		my $szMsg = "Unable to start tarakernel...";
		addWarningRecord(0,$szMsg);
		print "$szMsg\n";
		$bFailed = 1;
	}
	
	if (!startTaraLinkOk()) {
		my $szMsg = "Unable to start taralink - (try to install: sudo apt install dbus-x11)";
		addWarningRecord(0,$szMsg);
		print "$szMsg\n";
		$bFailed = 1;
	}
	
	if (!$bFailed) {	
		my $szMsg = "Taransvar systems successfully started";
		addWarningRecord(0,$szMsg);
		print "$szMsg\n";
	}		
	
	return (!$bFailed);
}

sub checkDbVersion {
	my ($dbh) = @_;
	addWarningRecord(0,"Entering checkDbVersion()");
	#First check if setup table has the dbVersion field
	my $szSQL = "SHOW COLUMNS FROM `setup` LIKE 'dbVersion'";
	my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
	#print "$szSQL\n";
	$sth->execute() or die "execution failed: $sth->errstr()";
	#if ($row = $sth->fetchrow_hashref()) {
	#$row->{'ip'} 
	my $row = $sth->rows;
	if (!$row)
	{
		print "You're running a too old version of the database for automatic update.\nPlease implement the changes in install.sql and re-run this script.\n";
		addWarningRecord(0,"Too old db version!");
	} else {
		#Get the current DB version
		my $szSQL = "select dbVersion from setup";
		my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
		#print "$szSQL\n";
		$sth->execute() or die "execution failed: $sth->errstr()";
		my $dbVersion = "";
		if ($row = $sth->fetchrow_hashref()) {
			$dbVersion = $row->{'dbVersion'};
			my $szMsg = "Current DB version: $dbVersion"; 
			print "$szMsg\n";
			addWarningRecord(0,$szMsg);
		} else {
			print "********* ERROR *** Unable to read db verison.\n";
			exit;
		}

		#Open the sql file with database structure updates.
		my $szSqlSetup = getPerlScriptDir()."install.sql";
		print "Reading $szSqlSetup\n";
		open my $info, $szSqlSetup or die "Could not open $szSqlSetup.\nThe programming/misc folder should be current folder when running this script!: $!";
		
		my $bCronFound = 0;
		my $bImplement = 0;
		my $bCommandsWritten = 0;
		my $szCommand = "";	#Collect command to implement here... (and run it once semicolon is found - mysql commands ends with semicolon...)
	
		my $szLogTxt = "/root/setup/log/sqltemp.txt";
		#$szLogTxt = "log.txt";
		my $szSqlCmdFile = "/root/setup/log/sql.txt"; 
		open(FH, '>', $szSqlCmdFile) or die $!;	
		print FH "#SQL commands:\n\n";
	
		while(my $szLine = <$info>)  {
			if ($szLine =~ /^#version\s(\d*)\s\((\d*)\)/ ) {
				if ($1+0 > $dbVersion) {
					print "New DB changes found: $1 - $2... implement the following lines..\n";
					$bImplement = 1;
				} else {
					#print "New DB changes found: $1 - $2... but already implemented..\n";
				}
			} else {
				if ($bImplement) {
					#$szLine = trim($szLine);
					$szLine =~ s/^\s+|\s+$//g ;     # remove both leading and trailing whitespace
					#if ($szLine ne "") {
					#	print "Should run: $szLine\n";
					#}
	
        	                        if (index($szLine,"#") != 0) {
        					$szCommand .= " $szLine";
	
        					if ($szLine =~ /^.*;/ ) {
        						#Line ends with semicolon (mysql commands may be several lines ending with semicolon)
        						print "Run command: $szCommand\n";
        						#This mysql user doesn't have the rights to alter database.. So save commands in file and run as root   user. 
        						#my $sth = $dbh->prepare($szCommand) or die "prepare statement failed: $dbh->errstr()";
        						#$sth->execute() or die "execution failed: $sth->errstr()";
        						print FH "$szCommand\n\n";
        						$szCommand = "";	#Clear the command to hold next command.
        						$bCommandsWritten = 1;
        					}
					}
				}
			}
		}
		close($info);
		close(FH);
	
		if ($bCommandsWritten) {
			addWarningRecord(0,"DB has been upgraded");
			print "About to save changes to the database\n(cmd: $szSqlCmdFile, log: $szLogTxt)....\n";
			system("sudo mysql -u root taransvar < $szSqlCmdFile > $szLogTxt");
			addWarningRecord(0,"Database updated to new version");
		} else {
			my $szMsg = "Database was up to date..";
			print "$szMsg\n";
			addWarningRecord(0, $szMsg);
		}
	}
} #sub checkDbVersion()

sub workshopSetup {
	my $cSetup = getSetup();
	if (!defined($cSetup->{"workshopId"})) {
		print "Workshop id not defined\n";
		return;
	}
	
	my $nWorkshopId = $cSetup->{"workshopId"};
	if (!$nWorkshopId) {
		print "Workshop id is 0\n";
		return;
	}
	
	my $szIp = $cSetup->{"adminIP"};
	my @cDevices = getDevices();
	my $szCurrentIp = ipOfDevice($cDevices[0]);
	
	#my $szUrl = "http://81.88.19.252/script/config_update.php?f=workshop&id=$nWorkshopId&me=$szCurrentIp&role=partner";
	my $szLogFile = getLogRoot()."wget.txt";
	#$szUrl = urlencode($szUrl);
	
	use URI;
	use LWP::Simple 'get';
	my $url = URI->new('http://'.$cSetup->{'globalDb1'}.'/script/config_update.php');
	$url->query_form(
		f => "workshop",
    		id => $nWorkshopId,
    		me   => $szCurrentIp,
    		role => "partner"
	);

	print "URL: $url\n";
	my $content = get($url) or die "Failed to access URL: $url\n";
	$content = trim($content);
	
	#system("wget $szUrl > $szLogFile");
	#my $szLog = getFileContents($szLogFile);
	print "Returned: $content\n";
	
	my @cPartners = split(/\|/, $content);

	my $conn = getConnection();

	foreach (@cPartners) {
		my $szPartner = $_;
		my @cFlds = split(/\^/, $szPartner);
		if ($cFlds[0] && $cFlds[1] && $cFlds[0] ne "" && $cFlds[0] ne $szCurrentIp) {
			print "IP: ".$cFlds[0].", role: ".$cFlds[1]."\n";
			my $szSQL = "select routerId from partnerRouter where ip = inet_aton(?)";
			my $sth = $conn->prepare($szSQL) or die "prepare statement failed: $conn->errstr()";
			$sth->execute($cFlds[0]) or die "execution failed: $sth->errstr()";
			
			if (my $row = $sth->fetchrow_hashref()) {
	        		#my $szMsg = "Setting up port ".$row->{"publicPort"}." to point to ".$row->{"ip"}.":".$row->{"port"};
	        		my $szSQL = "update partnerRouter set workshopVerified = now() where routerId = ?";
				my $sth = $conn->prepare($szSQL) or die "prepare statement failed: $conn->errstr()";
				$sth->execute($row->{"routerId"}) or die "execution failed: $sth->errstr()";
	        	} else {
				my $szSQL = "insert into partnerRouter(partnerId, ip, nettmask) values (1,inet_aton(?),inet_aton('255.255.255.255'))";
				my $sth = $conn->prepare($szSQL) or die "prepare statement failed: $conn->errstr()";
				$sth->execute($cFlds[0]) or die "execution failed: $sth->errstr()";
			}
		}
	}
	my $szSQL = "delete from partnerRouter where workshopVerified < date_sub(now(), INTERVAL 5 minute)";
	my $sth = $conn->prepare($szSQL) or die "prepare statement failed: $conn->errstr()";
	print "$szSQL\n";
	$sth->execute() or die "execution failed: $sth->errstr()";
}

sub checkRequests {
	my $cSetup = getSetup();
	
	if ($cSetup->{"requestReboot"}) {
		setSetupField(0, "requestReboot", 0);
		system("reboot");
		exit 0;
		#print "Reboot cancelled...\n";
	}
	
	if ($cSetup->{"requestShutdown"}) {
		setSetupField(0, "requestShutdown", 0);
		system("shutdown");
		exit 0;
		#print "Shutdown cancelled...\n";
	}
}

1;



