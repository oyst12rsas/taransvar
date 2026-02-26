#!/usr/bin/perl
#NOTE! Room for improvement: Could run this multiple times on the same file and only import the new ones... E.g Save the last time read and fast forward there before starting importing.. Then maybe once day move the file to "handled" folder (the current functionality).
#There's also a probable problem with because it takes several messages to assemble the total picture.. Meaning maybe we should re-read files processed before saving.
#Processing DHCP IP assignments.
#Should be scheduled for running maybe once an hour or so by:
#sudo crontab -u root -e
#* * * * * sudo perl <insert correct path>/conntrack.pl
package lib_dhcp;
use strict;
use warnings;
use Exporter;

our @ISA= qw( Exporter );

# these CAN be exported.
our @EXPORT_OK = qw();

# these are exported by default.
our @EXPORT = qw( process_dhcpdump process_dhcpdump_testing checkArchiveDhcpFiles dhcpServerStatusOk );

use autodie;
use DBI;
use Data::Dumper qw(Dumper);
use File::Copy;

use func;
#TO DO (fix this)!

#NOTE! Need to change this for new implementation... Better save the nic name in the database (which is unaffected by source upgrades)
#my $szInternalNic = "enp3s0";


#my ($sec,$min,$hour,$mday,$mon,$year,$wday,$yday,$isdst)=localtime(time);
#my $nice_timestamp = sprintf ( "%04d%02d%02d-%02d:%02d:%02d", $year+1900,$mon+1,$mday,$hour,$min,$sec);

my $szInternalNic = ""; #Will contain the name of internal network interface


sub startDhcpdump {
        my $szLogTxt = "/root/setup/log/log.txt";
        system ("ps -aux | grep dhcpdump > $szLogTxt");
#root      130423  0.0  0.0  28724  7552 ?        S    17:47   0:00 sudo dhcpdump -i enp3s0
#root      130424  0.0  0.0   9788  6144 ?        S    17:47   0:00 dhcpdump -i enp3s0
#root      137891  0.0  0.0  17812  2176 pts/1    S+   18:13   0:00 grep --color=auto dhcpdump

        open my $info, $szLogTxt or die "Could not open $szLogTxt: $!";
        my $bFound=0;
        while( my $szLine = <$info>)  {
                #grep --color=auto dhcpdump
                if ($szLine =~ /^.*(grep\s--color=auto).*/ ) {
                        #This is not the dhcpdump running...
                } else {
                        if ($szLine =~ /^.*(dhcpdump.pl).*/ ) {
                                #This is not the dhcpdump running...
                        } else {
                                if ($szLine =~ /^.*(grep).*/ ) {
                                        #This is not the dhcpdump running...
                                } else {
                                        if ($szLine =~ /^.*(sudo\sdhcpdump).*/ ) {
                                                #This is the sudo command starting dhcpdump...
                                        } else {
                                                if ($szLine =~ /^root\s*(\w*).*(dhcpdump).*/ ) {
                                                        $bFound=1; #NOTE! Find better way to identify
                                                        print "dhcpdump is already running (kill it): $szLine\n";
                                                        my $szKillCmd = "kill $1";
                                                        print "About to run: $szKillCmd\n";
                                                        system ($szKillCmd);
                                                        print "dhcpdump killed: $szKillCmd\n"; 
                                                }
                                        }
                                }
                        }
                }
        }
        close($info);

        #Start new dhcpdump
#       my $szDumpFileName = $szSysRoot."log/dhcp/dhcpdump".$nice_timestamp.".txt";                                     
        #my $szDumpFileName = $szSysRoot."log/dhcp/dhcpdump.txt";                                     
        my $szDhcpDumpName = getLogRoot()."dhcp/dhcpdump.txt";
        print "Starting dhcpdump background task. Writing to $szDhcpDumpName\n";
        my $szCmd = "sudo dhcpdump -i $szInternalNic > $szDhcpDumpName &";
        print "cmd: $szCmd\n";
        system($szCmd);                      
}

sub updateCheckedTimeInSetup {
        my $dbh = $_[0];
        my $nRecordsInserted = $_[1];

	my $szInserted = ($nRecordsInserted > 0 ? ", dhcpAdded = now()":"");
        my $szSQL = "update setup set dhcpChecked = now() $szInserted";
        my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
        $sth->execute() or die "execution failed: $sth->errstr()";
}


sub storeNewClient {
	my ($szHexID, $szMac, $szVendorClass, $szHostname, $szIP, $dbh) = @_;
        
        #my $szSQL = "insert into dhcpClient (dhcpClientId, mac, vci, hostname) values (X'$szHexID', X'$szMac', '$szVendorClass', '$szHostname')";
        my $szSQL = "insert into unit (dhcpClientId, mac, vci, hostname, ipAddress, lastSeen) values (X'$szHexID', X'$szMac', '$szVendorClass', '$szHostname', inet_aton('$szIP'), now())";
        print "Storing unit: $szSQL\n";
        my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
        $sth->execute() or die "execution failed: $sth->errstr()";
        #$sth->finish;
        my $nNewId = getLastInsertId($dbh);
        if ($nNewId) 
        {
                print "New client registered with ID $nNewId\n"; 
                return $nNewId;
       	}
      	else {
      	        print "*********** ERROR! Unable to fetch id of new record\n";
      	}
      	return 0;       
}


sub logDhcpFile {
	my ($dbh, $szFileName) = @_;
        my $szSQL = "insert into dhcpDumpFile (fileName) values (?)";
        my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
        $sth->execute($szFileName) or die "execution failed: $sth->errstr()";
	return getLastInsertId($dbh);
}

sub logDhcpDump {
	my ($dbh, $szIP, $szDummy, $szHexID, $szLongMac, $szVendorClass, $szHostname, $szDate, $szTime, $nFileId) = @_;
        my $szSQL = "insert into dhcpDumpLog (dhcpDumpFileId, logTime, macAddress, ipAddress, dhcpClientId, mac, vci, hostname, comment) values (?,?,?,inet_aton(?),?,NULL,?,?,NULL)";
        if (!defined($dbh)) {
                print "\$dbh was not defined in logDhcpDump\n";
                exit;
        }
        #asdfasdf
        my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
        my $szLogTime = (length($szTime.$szDate)>10? $szDate." ".$szTime : getDbNow());	#NOTE! Not sure if able to save NULL this way...
        
        if (!defined($szIP) || length($szIP) <5) {
        	$szIP = "0.0.0.0";
        }
        
        $sth->execute($nFileId, $szLogTime, $szLongMac, $szIP, $szHexID, $szVendorClass, $szHostname) or die "execution failed: $sth->errstr()";
}


sub doSave {
	my ($dbh, $szIP, $szDummy, $szHexID, $szLongMac, $szVendorClass, $szHostname, $szDate, $szTime) = @_;

        my $szMsg = "In doSave(): clientId: hexId: $szHexID, hostname: $szHostname, mac: $szLongMac"; 
        print "$szMsg\n";
        #addWarningRecord($dbh, $szMsg);

        if ($szLongMac =~ /^(\w*):(\w*):(\w*):(\w*):(\w*):(\w*):(\w*):(\w*):(\w*):(\w*):(\w*):(\w*):(\w*):(\w*):(\w*):(\w*)/)
        {
                my $szMac = $1.$2.$3.$4.$5.$6.$7.$8.$9.$10.$11.$12.$13.$14.$15.$16;
        
#                my $szSQL = "select s.clientId, sessionId, discovered, inet_ntoa(ip) as ip from dhcpSession s join unit u on unitId = clientId where mac = X'$szMac' and dhcpClientId = X'$szHexID' order by sessionId desc limit 1;";
                my $szSQL = "select unitId from unit where mac = X'$szMac' and dhcpClientId = X'$szHexID' order by unitId desc limit 1;";
                print "$szSQL\n";
                
                my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
                $sth->execute() or die "execution failed: $sth->errstr()";
 #               my $nClientId = 0;
                my $nUnitId = 0;
                if (my $cSetup = $sth->fetchrow_hashref()) 
                {
                	#$nClientId = $cSetup->{'clientId'};
                	$nUnitId = $cSetup->{'unitId'};
                	my $szMsg = "Unit $szHexID already exists with id ".$cSetup->{'unitId'}."\n";
                        print "print $szMsg\n";
		        #addWarningRecord($dbh, $szMsg);
		        
		        my $szSQL = "update unit set lastSeen = '$szDate $szTime', ipAddress = inet_aton('$szIP') where unitId = $nUnitId";
	                my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
	                $sth->execute() or die "execution failed: $sth->errstr()";
		        #addWarningRecord($dbh, $szSQL);
		        #print "$szSQL\n";
		        
                	#my $szClientIp = $cSetup->{'ip'};
                	
                	#if ($szClientIp eq $szIP) {
                	#        print "Session found with same IP as now... just leave it...\n";
                	#        $nClientId = 0;
                	#} else {
                	        #Save a new session... below... ($nClientId is set)
                	#        }
                } else {
                        print "This is a new client... Saving!\n";
               	        #$nClientId = storeNewClient($szHexID, $szMac, $szVendorClass, $szHostname, $szIP, $dbh);
               	        $nUnitId = storeNewClient($szHexID, $szMac, $szVendorClass, $szHostname, $szIP, $dbh);
               	        if (!defined($szHostname) || $szHostname eq "") {
               	        	$szHostname = $szHexID;
               	        }
                        my $szMsg = "New unit $szHostname saved with id $nUnitId";
                        print "print $szMsg\n";
		        addWarningRecord($dbh, $szMsg);
                }
                $sth->finish;
                
      	        #if ($nClientId > 0) {
      	        if ($nUnitId > 0) {
      	        	#******* First check if this is the active session...
      	        	$szSQL = "select sessionId, inet_ntoa(ip) as ip, discovered from dhcpSession where clientId = $nUnitId order by sessionId desc limit 1";
      	        	#asdfasdf
	                my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
	                $sth->execute() or die "execution failed: $sth->errstr()";
	 #               my $nClientId = 0;
	                my $nSessionId = 0;
	                if (my $cSession = $sth->fetchrow_hashref()) 
	                {
	                	my $szMsg;
	                	if ($cSession->{"ip"} eq $szIP) {
	                		$nSessionId = $cSession->{"sessionId"};
	                		$szMsg = "Existing session found: ".$nSessionId.", ip: ".$cSession->{"ip"}.", last discovered ".$cSession->{"discovered"};
				} else {
					$szMsg = "Existing session found but IP address differs (so making new session).. New: ".$szIP.", pervious: ".$cSession->{"ip"};
				}
				#addWarningRecord($dbh, $szMsg);
                		print "$szMsg\n";
      	        	}
      	        	if (!$nSessionId) {
	      	                #Store a new session...
      	        
	      	                #$szSQL = "insert into dhcpSession (clientId, ip, discovered) values ($nClientId, inet_aton('$szIP'), '$szDate $szTime')";
	      	                $szSQL = "insert into dhcpSession (clientId, ip, discovered) values ($nUnitId, inet_aton('$szIP'), '$szDate $szTime')";
	      	                print "$szSQL\n";
	                        my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
	                        $sth->execute() or die "execution failed: $sth->errstr()";
	                        $sth->finish;
	                        my $nSessionId = getLastInsertId($dbh);
	                        my $szMsg = "New session saved with id $nSessionId..!";
	                        print "$szMsg\n";
			        #addWarningRecord($dbh, $szMsg);
	                }
                } else {
		        addWarningRecord($dbh, "***** ERROR *** while saving...");
                }
                
        }
        else
        {
                print "********* Unable to interpret mac address...\n";
        } 
}

sub process_dhcpdump_dummy {
	#This is now the one run from crontasks.pl as cron job
}

sub process_dhcpdump_testing {
}
sub process_dhcpdump {
	my ($dbh) = @_;
	if (!$dbh || !defined($dbh))
	{
		print "Error connecting (maybe database is not yet installed!\n";
		exit;
	}

	my $szSysRoot = "/root/setup/";

	print "\n\n *********** process_dhcpdump.pl **********************\n\n";

	#No need to check this every time..... 
	if (-d $szSysRoot."log/dhcp/handled") {
		# directory called cgi-bin exists
		#print "Setup log/dhcp/handled directory already exists...\n";
	} else {
	        if (-d $szSysRoot."log/dhcp") {
		        # directory called cgi-bin exists
		        #print "Setup log/dhcp directory already exists...\n";
	        	system("mkdir ".$szSysRoot."log/dhcp/handled");
                } else {
        	        system("mkdir ".$szSysRoot."log/dhcp");
	        	system("mkdir ".$szSysRoot."log/dhcp/handled");
		}
	}

	my $nRecordsInserted = 0;


	#Read the name of the internal NIC from the database (need it for calling dhcpdump). 
	my $szSQL = "select internalNic from setup";
	my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
	$sth->execute() or die "execution failed: $sth->errstr()";
	if (my $cRow = $sth->fetchrow_hashref()) 
	{
		if (defined($cRow->{'internalNic'})) {
			$szInternalNic = $cRow->{'internalNic'};
			$szInternalNic =~ s/^\s+|\s+$//g ;     # remove both leading and trailing whitespace
		        print "Internal NIC read from DB: $szInternalNic\n";
		} else {
			my $szMsg = "No internal network device registered in setup.";
			addWarningRecord($dbh,$szMsg);
			print "$szMsg\n";
			$szInternalNic = "";
		}
		
	}
	else {
		print "*********** ERROR! This computer may not be set up with two network devices\nOr the name of internal network device is not put in the database.\nYou may run \"sudo perl misc/crontask.pl\" to set it up.\n";
		exit;
	}

	if ($szInternalNic eq "") {
		print "************** ERROR - could not find internal network device...\ndhcpdump will not run without it!\n";
	}

	#Kill dhcpdump and start new one... (if just moving the old dump file, 
	#dhcpdump will continue printing to the old moved file)
	
	my $szDhcpDir = $szSysRoot."log/dhcp"; 
	
	my @files = grep {!/\.xh$/} <$szDhcpDir/dhcpdump*>;

	if (scalar @files == 0) {
	        print "No files to process.. Make sure dhcpdump is running in background.\n(Run: sudo service isc-dhcp-server status)\n";
	        startDhcpdump();
	        updateCheckedTimeInSetup($dbh, $nRecordsInserted);
	        return;
	}

	#NOTE - may have this in a function.....
	my ($sec,$min,$hour,$mday,$mon,$year,$wday,$yday,$isdst)=localtime(time);
	my $nice_timestamp = sprintf ( "%04d%02d%02d-%02d:%02d:%02d",
                                   $year+1900,$mon+1,$mday,$hour,$min,$sec);

	my $szMovedFileName = $szSysRoot."log/dhcp/handled/dhcpdump".$nice_timestamp.".txt";                                     

	my $szDhcpDumpName = $files[0]; #$szSysRoot."log/dhcp/dhcpdump.txt";

        #move the file to handled...
	system ("sudo mv $szDhcpDumpName $szMovedFileName");
	print "Moving file $szDhcpDumpName -> $szMovedFileName\n";

	#$szDhcpDumpName = $files[0];
	#my $szMovedFileName = $szDhcpDumpName =~ s/dhcpdump/handled\/dhcpdump/r;
	#print "About to process $szDhcpDumpName and move to $szMovedFileName\n\n";

	#Generates file: 
	#default via 192.168.100.1 dev eno1  proto static  metric 100 
	#169.254.0.0/16 dev eno1  scope link  metric 1000 
	#192.168.100.0/24 dev eno1  proto kernel  scope link  src 192.168.100.17  metric 100 

	open my $info, $szMovedFileName or die "Could not open $szMovedFileName: $!";
	print "About to process moved file: $szMovedFileName\n\n";

	my $szProcessingNow = "";
	my $szDate = "";
	my $szTime = "";
	my $szIP = "";
	my $szHexID = "";
	my $szLongMac = "";
	my $szVendorClass = "";
	my $szHostname = "";
	my $nCount = 0;
	my $szDummy = "testing testing";

        my $nFileId = 0;

	while( my $szLine = <$info>)  {
		$nCount++;
        	if (!$nFileId) {
        	        #Don't create the file until we see there's data in it.
                        $nFileId = logDhcpFile($dbh, $files[0]);
                }

		if ($szLine =~ /^\s*TIME:\s(\S+)\s(\S+)/)
		{
	                #TIME: 2024-11-17 12:41:04.742
	                if ($szTime ne "") {
		                #print "New record: $1 $2, $szIP, $szHexID, $szLongMac, VC:$szVendorClass\n";
	                        logDhcpDump($dbh, $szIP, $szDummy, $szHexID, $szLongMac, $szVendorClass, $szHostname, $szDate, $szTime, $nFileId);
		                if (($szIP ne "") && ($szHexID ne "") && ($szIP ne "0.0.0.0")) {

		                        print "About to save....(Hex id: $szHexID)\n";
		                        doSave($dbh, $szIP, $szDummy, $szHexID, $szLongMac, $szVendorClass, $szHostname, $szDate, $szTime);
		                        
		                        $szIP = "";
		                        $szHexID = "";
		                        $szLongMac = "";
		                        $szVendorClass = "";
		                        $nRecordsInserted++;
		                } else {
		                        print "Required data not yet gathered... IP: $szIP, Vendor ID: $szHexID\n";
		                }
		        }

	                $szDate = $1;
	                $szTime = $2;
		} else { 
	      	        #CHADDR: 0e:c7:9c:be:27:27:00:00:00:00:00:00:00:00:00:00
	                if ($szLine =~ /^CHADDR:\s(\S*)/)
	                {
	                         $szLongMac = $1;
	              	        #print "Mac: $szLongMac\n"; 
	               	}                	
	                if ($szLine =~ /^YIADDR:\s(\S+)/)
	                {
	        	        $szIP = $1;
	        	        #print "IP: $szIP\n"; 
	                } else {
	                        #OPTION:  61 (  7) Client-identifier         010ec79cbe2727
	        	        if ($szLine =~ /^OPTION:\s*61.*Client-identifier\s+(\S*)/)
	        	        {
	        	                $szHexID = $1;
                                        $szHexID =~ s/://g;        
	        	                
	                	        print "HEX ID: $szHexID\n"; 
	                	} 
                	
	                        #OPTION:  60 ( 15) Vendor class identifier   616e64726f69642d android-                	
	        	        if ($szLine =~ /^OPTION:\s*60.*Vendor\sclass\sidentifier\s+(\S*).*(\S*)/)
	        	        {
	        	                my $szVendorClass1 = $2;
	                	        print "VendorClass1: $szVendorClass1\n";
                	        
	                	        #Read next line and add....
	                	        my $szLine = <$info>;
	                	        #             646863702d3134   dhcp-14
	                	        if ($szLine =~ /^\s*(\S*)\s*(\S*)/ )
	                	        {
	                	                $szVendorClass = $szVendorClass1 . $2;
	                	        }
	                	        else
	                	        {
	                	                print "********************* ERROR - Vendor class identifier is supposed to be 2 lines\n";
	                	        }
	                	} 
	                	
	        	        if ($szLine =~ /^OPTION:\s*12.*Hostname\s+(\S*)/)
	        	        {
	                        	#OPTION:  12 ( 10) Hostname                  familie-PC
	                                $szHostname = $1;
				}	                	
                	}
                }
        }

	close($info);

        if (!$nCount) {
        
        	my $nVerSize = -s $szMovedFileName;

        	my $szMsg = "File is empty.. Tried to delete it: $szMovedFileName"; 
		if (!$nVerSize) {
	        	print "$szMsg\n";
			#addWarningRecord($dbh, $szMsg);
        		system("rm $szMovedFileName");
        	}
        	else {
        		my $szSaving = "**** WARNING ****** (BUT CONTAINS $nVerSize bytes, so kept) $szMsg";
			addWarningRecord($dbh, $szSaving);
	        	print "$szSaving\n";
		}
        } else {
                logDhcpDump($dbh, $szIP, $szDummy, $szHexID, $szLongMac, $szVendorClass, $szHostname, $szDate, $szTime, $nFileId);
		if ($szIP ne "0.0.0.0") {
	                print "About to save (****LAST RECORD****)....(Hex id: $szHexID)\n";
			doSave($dbh, $szIP, $szDummy, $szHexID, $szLongMac, $szVendorClass, $szHostname, $szDate, $szTime);       #Save the last record...
		} else {
			if ($szHexID ne "") {
				my $szMsg = "HexId was set but others lacking while saving dhcp session.\n";
				addWarningRecord($dbh, $szMsg);
			}
		
		}

		#Update status fields in setup table to make it easier to check if this routine is running.
		updateCheckedTimeInSetup($dbh, $nRecordsInserted);
	}
} #sub process_dhcpdump()
	
sub checkArchiveDhcpFiles {
	if (!-d getLogRoot()."dhcp/handled") {
		my $szMsg = "****** ERROR **** Archive directory for dhcp doesn't exist. cron task is probably not running properly.";
        	addWarningRecord(0,$szMsg);
		print "$szMsg\n";
		return;
	}

	#If there's DHCP files from last months, put them in zip file and delete the files. 
	my ($sec,$min,$hour,$mday,$mon,$year,$wday,$yday,$isdst)=localtime(time);
 	my $szDhcpDir = getLogRoot()."dhcp/handled";
	#Find last year and month.. NOTE! january == 0
	if ($mon == 0) {
		$mon = "12";
		$year--;
	} else {
		if ($mon < 10)
		{
			$mon = "0".($mon);
		} else {
			#$mon;
		}
	}
	my $szFilesFromLastMonth = "dhcpdump".($year+1900).$mon;
	#my $szPath = "$szDhcpDir/$szFilesFromLastMonth";
	print "Path dir: $szDhcpDir, files: $szFilesFromLastMonth\n";

	#opendir ( DIR, $szDhcpDir ) || die "Error in opening dir $szDhcpDir\n";
	#my @files = grep(/$szFilesFromLastMonth/,readdir(DIR));
	#closedir(DIR);
	opendir my $dh, $szDhcpDir or die "Could not open '$szDhcpDir' for reading: $!\n";	
	my @files = grep(/$szFilesFromLastMonth/,readdir($dh));
	closedir($dh);

	#my @files = grep {!/\.xh$/} <$szDhcpDir/dhcpdump*>;

	if (scalar @files == 0) {
	        print "No files to process..\n";
	} else {
	        print "There's ".(scalar @files)." files from last month.. Should be archived\n";
		#my $szCmd = "tar cvzf ../taradump_archive_202411.tar.gz dhcpdump202412*";
		my $szTarFileName = getLogRoot()."dhcp/taradump_archive_".($year+1900).$mon.".tar.gz";
		my $szTargetFiles = $szDhcpDir."/dhcpdump".($year+1900).$mon."*";
		my $szCmd = "tar cvzf ".$szTarFileName." ".$szTargetFiles;
		print "$szCmd\n";
		system($szCmd);
		$szCmd = "rm $szTargetFiles";
		system($szCmd);
		my $szLog = (scalar @files)." DHCP files zipped and moved to ".$szTarFileName.". (should be checked)"; 
        	addWarningRecord(0,$szLog);
	}
}

sub checkDhcpServerStatusLogOk {
	my $szLogFile = getLogRoot()."dhcpServer.txt";
	system ("service isc-dhcp-server status > $szLogFile");
	my $szContents = getFileContents($szLogFile);
	#print "**** Dhcp server status ****:\n$szContents\n\n";
	if (index($szContents, "Active: failed") > 0 ||
		index($szContents, "FAILURE") > 0) {
		return 0;
	}
	return 1;
}

sub checkSysLogDhcpServerOk {
	my $szLogFile = getLogRoot()."dhcpServer.txt";
	system("cat /var/log/syslog | grep dhcp > $szLogFile"); 
	my $szContents = getFileContents($szLogFile);
	my @cFound = split("\n",$szContents);
	my @cMatches1 = grep(/Not\sconfigured\sto\slisten/, @cFound);	
	my @cMatches2 = grep(/Failed\swith\sresult/, @cFound);
	my @cMatches = (@cMatches1, @cMatches2); 

	my $nLines = @cMatches;
	if ($nLines) {
		my $szTxt = join("<br>", @cMatches);
		my $szTxtPrint = join("\n", @cMatches);
        	addWarningRecord(0,"Suspicious lines in syslog:<br>$szTxt");
        	print "Suspicious lines in syslog:\n$szTxtPrint\n";
	} else {
		my $szMsg = "Nothing suspicious found in syslog. If your subnet has problems connecting, you could still try: cat /var/log/syslog | grep dhcp";
		print "$szMsg\n";
        	addWarningRecord(0,"Suspicious lines in syslog:<br>$szMsg");
	}
	
	#2025-02-14T23:34:57.341002+03:00 taras systemd[1]: isc-dhcp-server6.service: Failed with result 'exit-code'.
	#2025-02-14T23:34:57.341032+03:00 taras dhcpd[1636]: Not configured to listen on any interfaces!

	#if (index($szContents, "Not configured to listen on any interfaces!") > 0 ||
	#	index($szContents, "Failed with result") > 0) {
	return ($nLines == 0);
}

sub checkDeviceNameInFile {
	my ($szInternalDevice, $szSetupFile) = @_; 
	my $szContent = getFileContents($szSetupFile);
	if (index($szContent, $szInternalDevice) == -1) {
		print "************* ERROR Device $szInternalDevice not found in DHCP setup file $szSetupFile\n";   
	}
}

sub checkDhcpServerSetupFiles {
	my @cDevices = getDevices();
	my $szInternalDevice = $cDevices[1];
	checkDeviceNameInFile($szInternalDevice, "/etc/default/isc-dhcp-server"); 
	checkDeviceNameInFile($szInternalDevice, "/etc/dhcp/dhcpd.conf"); 
}


sub dhcpServerStatusOk {
	my $bStatusOk = 1;
	
	my $nDnsmasq = getProcessId("dnsmasq");
	
	if ($nDnsmasq) {
		kill 'KILL', $nDnsmasq;
		my $szMsg;
		if (getProcessId("dnsmasq")) {
        		$szMsg = "Was unable to stop is, so the system will NOT work as router.";
        		$bStatusOk = 0;
        	} else {
        		$szMsg = "Stopping it for now. So the system should work as router.";
        	}
        	my $doMsg = "**** ERROR: dnsmasq found running on the system. isc-dhcp-server can't co-exist with dnsmasq. $szMsg";
        	system("killall dnsmasq");
        	addWarningRecord(0, $doMsg);
        	print "$doMsg\n";
	}
	
	if (!checkDhcpServerStatusLogOk()) {
		my $szMsg = "";
        	system("service isc-dhcp-server restart");
		if (!checkDhcpServerStatusLogOk()) {
			$szMsg = "***** Error ***** dhcp server is still not ok after restarting.";
			$bStatusOk = 0;
			checkSysLogDhcpServerOk();
			checkDhcpServerSetupFiles();
		} else {
			$szMsg = "dhcp server ok etter restart.";
		}
		
		my $szMsg2 = "$szMsg (service isc-dhcp-server status)"; 
        	addWarningRecord(0,$szMsg2);
        	print "$szMsg2\n";
	} else {
		print "Dhcp server seems operating. (service isc-dhcp-server status)\n";
	}
	return $bStatusOk;
}

1;



