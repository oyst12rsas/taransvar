#func.pm
#perl module to be included in other scripts.
#To use:
#- use lib ('.');
#- use func;
#NOTE! This file is copied to /root/wifi/perl and should be updated for the hotsport system sometimes.

package func;
use strict;
use warnings;
use Exporter;

our @ISA= qw( Exporter );

# these CAN be exported.
our @EXPORT_OK = qw();

# these are exported by default.
our @EXPORT = qw( getSysRoot getLogRoot getIptablesLogFileName getDbNow getConnection getNiceTimestamp trim validIp getLastInsertId getString doExecute addWarningRecord getSourceRoot ableToPing ipOfDevice moduleRunning programRunning getFileContents getFileLines getDumpTxt uptime getDevices isLanAddress getSetup resetSetup getProcessId doKill addToLogFile getNewestFile );

my $cSetup = 0;

sub getSysRoot {
	return "/root/setup/";
}

sub getLogRoot {
	return "/root/setup/log/";
}

sub getIptablesLogFileName {
	#This file should contain iptables commands run since system boot discovered by crontasks.pl 
	return getLogRoot()."iptables.txt";
}

sub getFwRejectLogFileName {
	return getLogRoot()."fwRejects.txt";
}

sub getDbNow {
 my ($sec,$min,$hour,$mday,$mon,$year,$wday,$yday,$isdst)=localtime(time);
    my $nice_timestamp = sprintf ( "%04d-%02d-%02d %02d:%02d:%02d",
                                   $year+1900,$mon+1,$mday,$hour,$min,$sec);
}

sub getConnection {
	my $database = "taransvar";
	my $hostname = "localhost";
	my $port = "3306";
	my $user = "scriptUsrAces3f3";
	my $password = "rErte8Oi98e-2_#"; #"rErte8Oi98!%&e";

	my $dsn = "DBI:mysql:database=$database;host=$hostname;port=$port";
	my $dbh = DBI->connect($dsn, $user, $password) or die "Unable to connect!";#: $dbh->errstr()";
	return $dbh;
}

sub getNiceTimestamp {
	my ($sec,$min,$hour,$mday,$mon,$year,$wday,$yday,$isdst)=localtime(time);
	return sprintf ( "%04d%02d%02d-%02d:%02d:%02d",
                                   $year+1900,$mon+1,$mday,$hour,$min,$sec);
}

sub  trim { my $s = shift; $s =~ s/^\s+|\s+$//g; return $s };

sub validIp {
	my ($szIP) = @_;
	if (! defined $szIP) {
		return 0;
	}
	
	#Each element: \d{1,2}|[01]\d{2}|2[0-4]\d|25[0-5]
	return $szIP =~ qr/\d{1,2}|[01]\d{2}|2[0-4]\d|25[0-5]\.\d{1,2}|[01]\d{2}|2[0-4]\d|25[0-5]\.\d{1,2}|[01]\d{2}|2[0-4]\d|25[0-5]\.\d{1,2}|[01]\d{2}|2[0-4]\d|25[0-5]/;
}

sub getLastInsertId {
	my ($dbhLookup) = @_;
	my $sthLookup = $dbhLookup->prepare("select last_insert_id()");
	$sthLookup->execute() or die "execution failed: $sthLookup->errstr()";
	if (my $row = $sthLookup->fetchrow_hashref()) { 
		return $row->{"last_insert_id()"};
	} else {
		print "\n******** ERROR - unable to find new id (never happens)\n";
	}
	return 0;
}			

sub getString {
	my ($szSQL) = @_;
	my $dbh = getConnection();
	my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
	$sth->execute() or die "execution failed: $sth->errstr()";
	if (my $row = $sth->fetchrow_hashref()) {
		my $szVal = $row->{'val'}; 
		#$dbh->disconnect;	Gives error.. seems like $szVal is pointing to $row which is being destroyed by disconnect
		return $szVal;
	} else
	{
		$dbh->disconnect;
		return -1;
	}
}

sub doExecute {
	my ($dbh, $szSQL) = @_;
	
	my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
	$sth->execute() or die "execution failed: $sth->errstr()";
}

sub addWarningRecord {
	#NOTE! This function also exists in taralink (C)
	my ($dbh, $szWarning) = @_;
	my $bLocalConnection = 0;
	if (!$dbh) {
		$dbh = getConnection();
		$bLocalConnection = 1;
	}
	
	#First check if recently inserted.. 
	my $szSQL = "select warningId from warning where handled is null and lastWarned >= DATE_SUB(NOW(), INTERVAL 1 DAY) and warning = ?";
	my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
	$sth->execute($szWarning) or die "execution failed: $sth->errstr()";
	if (my $row = $sth->fetchrow_hashref()) { 
		$szSQL = "update warning set lastWarned = now(), count = count + 1 where warningId = ?";
		$sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
		$sth->execute($row->{"warningId"}) or die "execution failed: $sth->errstr()";
	} else {
		$szSQL = "insert into warning (warning) values (?)";
		$sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
		$sth->execute($szWarning) or die "execution failed: $sth->errstr()";
	}

	if ($bLocalConnection) {
		$dbh->disconnect;
	}
}



sub getSourceRoot {
	my $szScriptPath = $0;
	my $nLast = rindex($szScriptPath, "/");
	my $szSourceRoot;
	if ($nLast == -1) {
		#When run as cron, $0 will contain full path (if specified in crontab). When run from termina, use abs_path()
		use Cwd 'abs_path';
		$szScriptPath = abs_path("crontasks.pl");
		$nLast = rindex($szScriptPath, "/");	
		#my $nSourRootEnd = rindex($szScriptPath, "/", $nLast-1);
		#print "Abs_path: $abs_path\n";
		#$nLast = "(not cron)";
		#$szSourceRoot = $abs_path;
	} 
	my $nSourRootEnd = rindex($szScriptPath, "/", $nLast-1);
	$szSourceRoot = substr($szScriptPath, 0, $nSourRootEnd+1);
	print "***** Source root (nLlast: $nLast): $szSourceRoot\n";	#Normally ~/programming/
	
	return $szSourceRoot;
}

sub ableToPing {
	my ($szUrl) = @_;
	if (!$szUrl) {
		$szUrl = "google.com";
	}

	my $szPingTest2File = getSysRoot()."log/pingtest2.txt";
	system("/bin/ping -c 3 $szUrl > $szPingTest2File");
	#my $size = -s $szPingTest2File;	#Check the size of the saved file..
	#print "\nPing result file size: $size\n\n";

	#Check if able to ping
	open my $pPing2TestHandle, '<', $szPingTest2File;
	chomp(my @pingLines = <$pPing2TestHandle>);
	close $pPing2TestHandle;

        #print "****Checking ping result (cat ".$szPingTest2File.")..\n";
	foreach (@pingLines) {
		my $szLine = $_;
		#print "Line read:->$szLine<-\n";
		
		if ($szLine =~ /bytes from/)
		{
			#print "Able to ping $szUrl\n"; 
			return 1;
		}
		#else {
		#	print "No success line: $szLine\n";
		#}
	}
	print "*** Unable to ping $szUrl\n"; 
	return 0;
}

sub ipOfDevice {
 	my ($szDevice) = @_;
	my $szTmpFile = getSysRoot()."log/ip_a_show.txt";
	my $szCmd = "ip a show ".$szDevice." > ".$szTmpFile;
	system($szCmd);
	open my $handle, '<', $szTmpFile;
	chomp(my @lines = <$handle>);
	close $handle;

	foreach (@lines) {
		my $szLine = $_;
		#format: 
		#3: wlp2s0: <BROADCAST,MULTICAST,UP,LOWER_UP> mtu 1500 qdisc noqueue state UP group default qlen 1000
    		#link/ether 84:ef:18:74:e5:37 brd ff:ff:ff:ff:ff:ff
    		#inet 192.168.1.9/24 brd 192.168.1.255 scope global dynamic noprefixroute wlp2s0
       		#valid_lft 82395sec preferred_lft 82395sec
    		#inet6 fe80::27c9:488c:e44a:22d3/64 scope link noprefixroute 
       		#valid_lft forever preferred_lft forever

		if ($szLine =~ /^\s+inet\s(\d+)\.(\d+)\.(\d+)\.(\d+)\.*/ )
		{
			my $szIp = "$1.$2.$3.$4";
	        	#print "********* Found IP: $szIp\n";
	        	return $szIp;
		} else {
			#print "Not found: $szLine\n";
		}
	}
	return 0;
}


sub programRunning {
	my ($szProgramName, $szNotRunning) = @_;
	my $szPsLog = getDumpTxt("ps -aux | grep ".substr($szProgramName, 0, length($szProgramName)-1));
	
	#my $szLogFile = getSysRoot()."/log/ps.txt";
	#system("ps -aux | grep ".substr($szProgramName, 0, length($szProgramName)-1)." > ".$szLogFile);
	#open(FILE, $szLogFile) or die "Can't read file \"$szLogFile\" [$!]\n";  
	#my $szPsLog = <FILE>; 
	#close (FILE);
	
	#print "PS Log (to search:)\n$szPsLog\n(looking for $szProgramName)\n";
	if (defined($szNotRunning)) {
		#E.g. when dmesg -w is running, there will also be "sudo dmesg -W" running. if defined, have to run through the lines to see if there's one withoug the text to exclude (may not be necessary, though) 	
		my @lines = split("\n", $szPsLog);
		foreach (@lines) {
			if (index($_, $szNotRunning) == -1) {
			        my $nNdx = index($_, $szProgramName); 
				if ($nNdx != -1) {
				        #Check character after.. If it's / or \ then it's a file in taralink folder
                                        my $charAfter = substr($_, $nNdx+length($szProgramName), 1);
				        #print "************* char after: $charAfter, at: $_\n"; 
                                        if ($charAfter ne "/" && $charAfter ne "\\") { 
					        return 1;
					} else {
					 #       print "************* Would have said it's running: $_\n"; 
					}
				} else {
					#print "$szProgramName not found in $_\n"; 
				}
			}
		}
		
		return 0;	#Didn't find the program without also having the "not run" text
	}
	
	if (index($szPsLog, $szProgramName) != -1) {
		#print "$szProgramName is running!\n";
		return 1;
	} else {
		#print "$szProgramName is NOT running!\n";
		return 0;
	}
}


sub moduleRunning {
	my ($szModuleName) = @_;
	my $szLogFile = getSysRoot()."/log/lsmod.txt";
	system("lsmod | grep $szModuleName > $szLogFile");
	open(FILE, $szLogFile) or die "Can't read file \"$szLogFile\" [$!]\n";  
	my $szLog = <FILE>; 
	close (FILE);
	#print "PS Log (to search:)\n$szPsLog\n(looking for $szModuleName)\n";
	if (!defined($szLog)) {
		return 0;
	} else {
		if (index($szLog, $szModuleName) != -1) {
			#print "$szModuleName is running!\n";
			return 1;
		} else {
			#print "$szModuleName is NOT running!\n";
			return 0;
		}
	}
}

sub getFileContents {
	my ($szFilename) = @_;
	open my $fh, '<', $szFilename or die "Can't open file in getFileContents() $!";
	my $szDump = do { local $/; <$fh> };
	close ($fh);
	return $szDump;
}

sub getFileLines {
	my ($szFilename) = @_;
	open my $handle, '<', $szFilename;
	chomp(my @lines = <$handle>);
	close $handle;
	return @lines;
}

sub getDumpTxt {
	my ($szCmdLine) = @_;
	my $szLogFile = getSysRoot()."/log/getDumpTxt.txt";
	system("$szCmdLine > $szLogFile");
	my $szDump = getFileContents($szLogFile);
	#print "getDumpText returning:\n$szDump\n<end of dump>\n";
	return $szDump;
}

sub uptime {
	my $szLogFile = getLogRoot()."log.txt";
	system("cat /proc/uptime > $szLogFile");
	my $szUptime = getFileContents($szLogFile);
	my @cParts = split(/\s/, $szUptime);
	my $nUptime = $cParts[0]+0;
}

sub getDevices {
	my @devices = ();
	my $szTmpIpRoute = getSysRoot()."log/iproute.txt";
	system("ip route > $szTmpIpRoute");

	#NOTE! getIPs() is assuming the first device in the list is the default route (ip route) is used...

	#Generates file: 
	#default via 192.168.100.1 dev eno1  proto static  metric 100 
	#169.254.0.0/16 dev eno1  scope link  metric 1000 
	#192.168.100.0/24 dev eno1  proto kernel  scope link  src 192.168.100.17  metric 100 

	open my $handle, '<', $szTmpIpRoute;
	chomp(my @lines = <$handle>);
	close $handle;

	#print "Scanning through devices.. but this is omitted (none put in array and later emptied)\n";
	foreach (@lines) {
		my $szLine = $_;
		#print "Line read:->$szLine<-\n";

	        #Check if this is the default route giving internet (and grab device and IP address)
	        #Format: default via 192.168.100.1 dev wlp0s20f3 proto dhcp src 192.168.100.19 metric 600 
		if ($szLine =~ /^default\s.+\sdev\s(\w+)\sproto\sdhcp\ssrc\s(\S+)(\.*)/ && !@devices)
		{
		        #print "Found external connection: $1 - $2\n";
		        push (@devices, $1);
		} 
		else
        	{
        		if ($szLine =~ /^(.+)\sdev\s(\w+)(\.*)/)
		        {
			        if ($2 ne "lo" && index($szLine, "docker") == -1) {
			        	if (! grep( /^$2$/, @devices ) ) {
				        	#print "Found: $2\n";
				        	push(@devices, $2);

				        	my $szLogText = "Device found: $2";
		        		
				        	#Check if marked as linkdown
				        	#if ($szLine =~ /linkdown/)
				        	#{
				        	#	$szLogText .= ", but skipped: Line is down\n";
				        	#
				        }
		        	}
		        }
                }
	}
	
	#****** NOTE *** Also use ip a to find additional devices

	#1: lo: <LOOPBACK,UP,LOWER_UP> mtu 65536 qdisc noqueue state UNKNOWN group default qlen 1000
    	#link/loopback 00:00:00:00:00:00 brd 00:00:00:00:00:00
    	#inet 127.0.0.1/8 scope host lo
       #valid_lft forever preferred_lft forever
    	#inet6 ::1/128 scope host noprefixroute 
       #valid_lft forever preferred_lft forever
	#2: enp3s0: <BROADCAST,MULTICAST> mtu 1500 qdisc noop state DOWN group default qlen 1000
    	#link/ether 94:c6:91:f1:35:89 brd ff:ff:ff:ff:ff:ff
	#3: wlp0s20f3: <BROADCAST,MULTICAST,UP,LOWER_UP> mtu 1500 qdisc noqueue state UP group default qlen 1000
    	#link/ether 38:00:25:5d:90:71 brd ff:ff:ff:ff:ff:ff
    	#inet 192.168.100.10/24 brd 192.168.100.255 scope global noprefixroute wlp0s20f3


	system("ip a > $szTmpIpRoute");

	open $handle, '<', $szTmpIpRoute;
	chomp(@lines = <$handle>);
	close $handle;
	#print "Trying to add devices from ip a\n";	
	foreach (@lines) {
		my $szLine = $_;
	#	print "Checking $szLine\n"; 
		if ($szLine =~ /^\d+\S\s(\w+)/)
		{
			my $szPotentialDevice = $1;
			if (grep( /^$szPotentialDevice$/, @devices ) ) {
  	#			print "$1 is already registered.\n";
			} else {
				if ($szPotentialDevice ne "lo") {
			        	push(@devices, $1);
	 # 				print "Added $1.\n";
			        } else {
	 # 				print "Not adding: $1.\n";
			        }
			}
		}
	}

	return @devices;
}

sub isLanAddress {
	my ($szLookupIp) = @_;
	return index($szLookupIp,"192.168") == 0 ||
			index($szLookupIp,"10.0.") == 0 ||
			index($szLookupIp,"10.10.") == 0;
}

sub getSetup {

	if ($cSetup) {
		return $cSetup;
	}

	my $conn = getConnection();
	
	my $sthSetup = $conn->prepare("select inet_ntoa(adminIP) as adminIP, inet_ntoa(internalIP) as internalIP, externalNic, internalNic, doDemo, uptime, workshopId, inet_ntoa(globalDb1ip) as globalDb1, inet_ntoa(globalDb2ip) as globalDb2, inet_ntoa(globalDb3ip) as globalDb3 from setup") or die "prepare statement failed: $conn->errstr()";
	$sthSetup->execute() or die "execution failed: $sthSetup->errstr()";
	#my $bNicsUpdated = 0; #No need to update network devices (and IP address) for all demo records because all records are being updated first time.. (no "where demoId = n in where clause)
	$cSetup = $sthSetup->fetchrow_hashref();
	$sthSetup->finish;
	$conn->disconnect;
	return $cSetup;
}

sub resetSetup {
	#Force re-read from database next time
	$cSetup = ();	
	$cSetup = 0;
}

sub getProcessId {
	#NOTE! Should match getProcessId("taralink") here:
	#  3684 ?        Sl     0:00 /home/taransvar/programming/taralink/taralink
  	# 10762 pts/0    S+     0:00 grep --color=auto taralink
  	# - where taralink is last part of the first word after the time. (there may be parameter after...)

	my ($szName) = @_;

	my $szCmd = "sudo ps ax | grep $szName";	#ps ax gives process id as first field
	my $szLog = getLogRoot()."log.txt";
	system("$szCmd > $szLog");
	my @cLines = getFileLines($szLog);
	#print "ps log (check if taralink is here):\n$szPs\n\n";
	foreach (@cLines) {
		my $szLine = $_; 
		print "$szLine\n";

		#Find first word after time field..
                if ($szLine =~ /\s*(\d+).+\d+\:\d+\s(\S+)/ ) {
			#Check if the program name is part of that first word
	                if (index($2, $szName) >= 0) {
	                	my $nProcess = $1;
	               		my $nProgram = $2;
	               		print "Program found: $nProgram (process $nProcess)\n";
	               		return $nProcess;
	               	}
		}	
	}
	
	return 0;
}

sub doKill {
	my ($szName) = @_;
	#kill			(lets the process trminate properly)
	#kill -HUP pid		(stronger medicine)
	#kill -KILL pid		(kill immediately - no chance to clean up)
	my $nProcessId = getProcessId($szName);
	if ($nProcessId) {
		print "Trying to kill $nProcessId gently\n"; 
		kill $nProcessId;
		$nProcessId = getProcessId($szName);
		if ($nProcessId) {
			print "Trying to murder $nProcessId\n"; 
			kill 'HUP', $nProcessId;
			$nProcessId = getProcessId($szName);
			if ($nProcessId) {
				print "Trying to murder $nProcessId brutally\n"; 
				kill "KILL", $nProcessId;
				$nProcessId = getProcessId($szName);
				if ($nProcessId) {
					print "******* WARNING Unable to kill $szName.. Should try kill -HUP or -KILL\n";
				} else {
					print "Finally succeeded\n";
				}
			} else {
				print "Succeeded with excessive force\n";
			}
		} else {
			print "Succeeded on first attempt\n";
		}
		
	} else {
		print "**** Unable to find $szName.. No killing today\n";
	}
}

sub addToLogFile {
	my ($szLogFileName, $szTxt) = @_;
	open(FH, ">>", $szLogFileName) or die "Couldn't add to log file $szLogFileName"; 
	print FH "$szTxt\n"; 
	close FH or "couldn't close $szLogFileName"; 	
}

sub getNewestFile {
	my ($szDir) = @_;
	my @files = grep {!/\.xh$/} <$szDir/*>;
	my $nFiles = scalar @files; 
	if ($nFiles == 0) {
		return -1;
	}
	return $files[$nFiles-1];
}

1;

