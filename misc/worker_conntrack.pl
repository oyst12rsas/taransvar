#!/usr/bin/perl
#NOTE! Room for improvement: Could run this multiple times on the same file and only import the new ones... E.g Save the last time read and fast forward there before starting importing.. Then maybe once day move the file to "handled" folder (the current functionality).
#There's also a probable problem with because it takes several messages to assemble the total picture.. Meaning maybe we should re-read files processed before saving.
#Processing DHCP IP assignments.
#Should be scheduled for running maybe once an hour or so by:
#sudo crontab -u root -e
#* * * * * sudo perl <insert correct path>/conntrack.pl
use lib ('/root/taransvar/perl');
#use lib ('.');
		
use strict;
use warnings;
use autodie;
use DBI;
use Socket;
use func;	#NOTE! See comment above regarding lib..
#use lib_dhcp;
use lib_cron;

our $lastDbUse = time();

sub ensureDbConnection
{
    if (time() - $lastDbUse > 60) {

        if (!$dbh->ping()) {
            print "DB connection stale. Reconnecting...\n";

            eval { $dbh->disconnect() if $dbh };

            $dbh = getConnection()
                or die "Unable to reconnect to database\n";
        }
    }

    $lastDbUse = time();
}

sub findWhatUnitHasIp {
	my ($dbh, $szInternalIp) = @_;

	if (!is_valid_ipv4($szInternalIp)) {
		return undef;
	}

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
                                   
our $dbh = getConnection();
#my $nice_timestamp = getNiceTimestamp();
#my $szGrabFile = getLogRoot()."conntrack/conntrack".$nice_timestamp.".txt";

#Change here if testing on specific file (NB!In current directory!) (otherwise generates new file - if filename > 35 char)
#$szGrabFile = $szSysRoot."/log/conntrack.txt";

#In case of no NAT or subclient in other secment than public IP, use this when checki if traffic is from internal unit (or NAT handled)
my $sthSetup = $dbh->prepare("select adminIP, inet_ntoa(adminIP) as aAdminIP, inet_ntoa(internalIP) as aInternalIP, internalIP, nettmask from setup") or die "prepare statement failed: $dbh->errstr()";
$sthSetup->execute() or die "execution failed: $sthSetup->errstr()";
our $cSetup = $sthSetup->fetchrow_hashref();
$sthSetup->finish;

if (!$cSetup->{"internalIP"}) {
	print "Intern lan IP not set. It's not a gateway. Hipernating (Skipping conntrack)\n";
  	$dbh->disconnect();	
    while (1) {
        sleep 3600;
    }	
}
	
our $nMyIp = $cSetup->{"internalIP"}+0;	
our $nMyNettmask = $cSetup->{"nettmask"}+0;

print "Me: ".$cSetup->{"adminIP"}.", ".$cSetup->{"nettmask"}."\n";


#if (-d getLogRoot()."conntrack") {
#    # directory called cgi-bin exists
#    print "Setup log/conntrack directory already exists...\n";
#}
#else {
#	system("mkdir ".getLogRoot()."conntrack");
#}

#if (length($szGrabFile) > 35) {
#	my $szCmd = "conntrack -L > $szGrabFile";	
#	#my $szCmd = "conntrack -L -n > $szGrabFile";	-n means NAT-connections only, so didn't list any when no NAT
#	print "\n\n************** RUNNING:\n$szCmd\n\n";
#      system($szCmd);
#} else {
#	print "**************** Short file name.. assuming test fine.. Dropping getting new file..\n";
#}

print "\n************* HANDLING PORT ASSIGNMENTS **********************\n\n";

#open my $info, $szGrabFile or die "Could not open $szGrabFile: $!";

our $nExists = 0;
our $nNewOnes = 0;
our $nNoMatch = 0;
our $nReturnPortDiffers = 0;
our $szGatewayIp = "";


sub	handleLine {
	my ($szLine) = @_;
    $szLine =~ s/^\s*\[(?:NEW|UPDATE|DESTROY)\]\s*//;	
	#print "Handling: $szLine\n";
	# Matching [ASSURED] - records
	#tcp      6 48 CLOSE_WAIT src=192.168.50.100 dst=172.217.170.163 sport=40968 dport=443 src=172.217.170.163 dst=192.168.100.10 sport=443 dport=40968 [ASSURED] mark=0 use=1

	#udp      17 52 src=192.168.50.100 dst=8.8.8.8 sport=48886 dport=443 src=8.8.8.8 dst=192.168.100.10 sport=443 dport=48886 [ASSURED] mark=0 use=1
        
    my $bMatchFound = 0;
    my $nSourcePort, my $nDestPort, my $szSourceIp, my $szDestIp, my $szRetSourceIp, my $szRetDestIp, my $nRetSourcePort, my $nRetDestPort;        

    if ($szLine =~ /tcp\s*(\d+)\s(\d+)\s(\w*)\ssrc\=(\S*)\sdst=(\S*)\ssport=(\d*)\sdport=(\d*)\ssrc\=(\S*)\sdst=(\S*)\ssport\=(\d*)\sdport=(\d*)(.+)/)        	
	{
        $bMatchFound = 1;
        #print "$1|$2|$3|$4|$5|$6|$7|$8|$9|$10|$11|$12\n"; 
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
			#print "$1|$2|$3|$4|$5|$6|$7|$8|$9|$10|$11|$12\n"; 
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
		#print "Interpreted: $szLine\n";
		if ($szGatewayIp eq "")
	   	{
            $szGatewayIp = $szRetDestIp; 
	   	} 
		# Created lots of warnings on VPS.. Should be checked.. disabling for now
		#else {
	    #	if ($szGatewayIp ne $szRetDestIp) {
	    #    	print "Return destination IP should always be the gateway IP ($szGatewayIp <> $szRetDestIp)....\n"; 
		#		saveWarning("Return destination IP should always be the gateway IP ($szGatewayIp <> $szRetDestIp)....");
	    #    }
		#}

		my $szInternalIp;
		my $nInternalPort;

		if ($szSourceIp ne $szRetDestIp)
		{
			$szInternalIp = $szSourceIp;
			$nInternalPort = $nSourcePort;
			print "NAT for $szSourceIp:$nInternalPort\n";
		}
		else 
		{
			if ($szDestIp ne $szRetSourceIp)
			{
				print "********* WARNING **** port assignment based on dest ip... Check this. Assuming $szDestIp:$nDestPort is internal NAT'ed unit.\n";
				$szInternalIp = $szDestIp;
				$nInternalPort = $nDestPort
			}
			else
			{
				print "No NAT, next record, please\n";
				return;
			}
		}

	    print "$szSourceIp:$nSourcePort -> $szDestIp:$nDestPort | $szRetSourceIp:$nRetSourcePort -> $szRetDestIp:$nRetDestPort\n"; 
	 	#print "$szLine\n";

#		my $nMyIp = $cSetup->{"internalIP"}+0;	
#		my $nMyNettmask = $cSetup->{"nettmask"}+0;
#		my $source_int   = unpack("N", inet_aton($szSourceIp));
		my $nMyNet = $nMyIp & $nMyNettmask;
#		my $nSourceNet = $source_int & $nMyNettmask;
#		my $bInternal = ($nMyNet == $nSourceNet);

#		my $bIsNATnet = $bInternal; #isInternal($szSourceIp);	#isInternal checks on 50 or 60 sub net... no longer in use....

#		if ($bIsNATnet) {
#			$szInternalIp = $szSourceIp;
#			$nInternalPort = $nSourcePort;
#		} else {
			#This may simply mean there's no NAT... 

#			print "My net: $nMyNet, source net: $nSourceNet\n";

#				if ($bInternal) {
#					print "Internal source without NAT found... $szSourceIp\n";
#					$szInternalIp = $szSourceIp;
#					$nInternalPort = $nSourcePort;
#				} else {
#					#Generated warnings on VPS (not NAT). Should be checked.
#					#print "**** ERROR **** Source IP is not internal when processing conntrack..";
#					#saveWarning("**** ERROR **** Source IP is not internal when processing conntrack..");
#					if (isInternal($szRetDestIp)) {
#						$szInternalIp = $szRetDestIp;
#						$nInternalPort = $nRetDestPort;
#					} else {
#						if (isInternal($szRetSourceIp)) {
#							$szInternalIp = $szRetSourceIp;
#							$nInternalPort = $nRetSourcePort;
#						} else {
#							if (isInternal($szDestIp)) {
#								$szInternalIp = $szDestIp;
#								$nInternalPort = $nDestPort;
#							}
#							 else {
								#Gave warning on VPS (Not NAT)
								#	saveWarning("****** ERROR ***** None are internal when saving NAT port assignment. Aborting.");
#								print "No NAT or forward?? Get next one??\n";
								#return;
								#Will skip to next record..
#							}
#						}
#					}
#				}
#			}

		my $nFound = 0;

		if (1)#$bIsNATnet) 
		{
			#NOTE! Can only do this if NAT... 
			#Skip saving if last use of same port was same IP.. NOTE! Should also check if it's same mac or other ID...
            my $szSQL = "select portAssignmentId, inet_ntoa(ipAddress) as ip, unitId from unitPort where port = $nInternalPort order by portAssignmentId desc limit 1";

			my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
			print "$szSQL\n";
			$sth->execute() or die "execution failed: $sth->errstr()";
			my $row;
			my $szUnitId = "NULL";
			my $nPortAssignmentId = 0;

			if ($row = $sth->fetchrow_hashref()) {
               	if ($row->{'ip'} eq $szInternalIp) {
               		#Last use of this port was same IP... 
                    print "Port $nInternalPort recently used by same IP ($szInternalIp). Skipping saving duplicate record.\n";
					$nFound = 1;
					$szUnitId = $row->{'unitId'}                                
					$nPortAssignmentId = $row->{'portAssignmentId'};
        	    } else {
    				print "Other ip (".$row->{'ip'}.") last used this port. Save new for ".$szInternalIp."\n"; 
            	}
        	}

	        if (!$nFound)
	        {
    	    	#NOTE! This means that this port is not registered before or registered on other unit...
        	    #Find the unitId
            	#NOTE - ***** This may be wrong... maybe other unit had that IP....(there should still be a session....)
	            my $szSQL = "select clientId from dhcpSession where ip = inet_aton('$szInternalIp') order by sessionId desc limit 1";  
	            my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
    	        $sth->execute() or die "execution failed: $sth->errstr()";
        	    if (my $cRec = $sth->fetchrow_hashref()) {
            		$szUnitId = $cRec->{'clientId'};
                	print "******** Found the unitId: $szUnitId\n"; 
	            } else {
					#NOTE!!!                 	#NOTE! Don't just create new UNIT here... Get new session from 
					#NOTE!!!				$szUnitId = getNewUnknownUnitId($dbh, $szInternalIp);
					$szUnitId = findWhatUnitHasIp($dbh, $szInternalIp);
					my $szMsg = "******** WARNING - Port assignment found for unknown unit. New created with id $szUnitId. Due to static IP?";
            	    print "$szMsg\n";
                	addWarningRecord($dbh, $szMsg); 
	            }

	        	$szSQL = "insert into unitPort (unitId, ipAddress, port) values ($szUnitId, inet_aton('".$szInternalIp."'), ".$nInternalPort.")";
    	    	doExecute($dbh, $szSQL); 
				$nPortAssignmentId = getLastInsertId($dbh);
            	#$sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
	            print "Saving external port assignment: $szInternalIp - $nInternalPort.\n";
	            #print "$szSQL\n";
    	        #$sth->execute() or die "execution failed: $sth->errstr()";
        	} else {
	    		#NOTE! This means that this port was last used by the same unit....
	            #port assignment found but unitId may still be blank.....
				#             	addWarningRecord($dbh, "**** WARNING *** Port assignment without unit. This shouldn't happen."); 
				
				#ØT 250130 - this is very wrong...	$szUnitId = getNewUnknownUnitId($dbh, $szInternalIp);

	        }
               
	        #Update the unit table lastSeen
	        if ($szUnitId ne "NULL") {
    	    	my $szSQL = "update unit set lastSeen = now() where unitId = ?";
        		#doExecute($dbh, $szSQL); 
				my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
	            $sth->execute($szUnitId) or die "execution failed: $sth->errstr()";

				$szSQL = "update unitPort set lastSeen = now() where portAssignmentId = ?";
    	    	#doExecute($dbh, $szSQL); 
				$sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
            	$sth->execute($nPortAssignmentId) or die "execution failed: $sth->errstr()";
	        } else {
				my $szMsg = "****** ERROR - unit not found.. (this shouldn't happen anymore)...."; 
    	        print "$szMsg\n";
           	    addWarningRecord($dbh, $szMsg); 
        	}

	        if (($nSourcePort ne $nRetDestPort) || ($szDestIp ne $szRetSourceIp) || ($nDestPort ne $nRetSourcePort))
	        {
    			my $szWarn = "********* WARNING! Traffic not returned back to same port: $szLine";
        	    print "$szWarn\n\n";
               	addWarningRecord($dbh, $szWarn); 
	            $nReturnPortDiffers++;
	        }
    	    print "\n"; 
		}
		else
		{
			#Not NAT - storing ports make no sense if there's no NAT......
			print "********** Skipping storing ports when there's no NAT\n";
			my $szUnitId = findWhatUnitHasIp($dbh, $szInternalIp);
			if (defined $szUnitId) {
   	    		my $szSQL = "update unit set lastSeen = now() where unitId = ?";
       			#doExecute($dbh, $szSQL); 
				my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
            	$sth->execute($szUnitId) or die "execution failed: $sth->errstr()";
			}
		}
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

open(my $fh, "-|", "conntrack", "-L")
    or die "Cannot start conntrack: $!";

my $line;

while ($line = <$fh>) {
	handleLine($line);
}

close $fh;

open($fh, "-|", "conntrack", "-E", "-e", "NEW")
    or die "Cannot start conntrack: $!";

while ($line = <$fh>) {
	$szLine =~ s/^\s*\[NEW\]\s*//;
 	ensureDbConnection();
 	handleLine($line);
}

close $fh;

print "$nNewOnes records inserted. $nExists were already stored.. No match: $nNoMatch\n";
if ($nReturnPortDiffers)
{
    print "************** WARNING ********* One packet not sent back to origin.... Check for warnings\n\n";
}



