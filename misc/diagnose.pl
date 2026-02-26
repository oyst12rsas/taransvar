#!/usr/bin/perl
#NOTE! Check the path to crontasks.pl in cron (line 113++) 
#NOTE! There's "Abscurity" still in tarakernel "Absecurity: Not infected unit......"
use strict;
use warnings;
use autodie;
use DBI;
use Data::Dumper qw(Dumper);
use File::Copy;

use lib ('.');	#Don't change this to allow opening from elsewhere because trying to hardcode full path below...
use func;
use lib_cron;
use lib_net;
use lib_diagnose;

my $szDeveloperName = "oystein";	#Used to change the name of directory for function libraries in crontasks.pl 

my $nErrors = 0;
my $nWarnings = 0;

my $dbh = getConnection();

my $szLogDir = "/root/setup/log/";

my $szThisFile = "diagnose.pl";
if (! -e $szThisFile) {
	#Never gets here because 
	print "\n*** ERROR *** $szThisFile not found in current directory.\nYou should cd to the misc folder before running the script. Aborting.\n\n";
	exit;
}

print "\n\n******* Taransvar cyber security system diagnostics ******\n(run misc/setup_network.pl for setting up and diagnosing networks setup)\n";

#***** fix the crontasks.pl if still contains the developers user name in path...
use Cwd 'abs_path';
my $abs_path = abs_path($szThisFile);
my $szCronTasksSource = getFileContents("crontasks.pl");
if (index($szCronTasksSource, $szDeveloperName) >= 0 && index($abs_path, $szDeveloperName) == -1) {
	#print "\$abs_path: $abs_path\n";
	my $szPath = substr($abs_path, 0, length($abs_path)-length($szThisFile)-1);
	$szPath =~ s/\//\\\//g;
	#print "libpath: $szPath\n";
	my $szOysteinPath = "/home/$szDeveloperName/programming/misc";
	$szOysteinPath =~ s/\//\\\//g;
	my $szCmdLine = "sudo sed -i 's/".$szOysteinPath."/".$szPath."/g' crontasks.pl";
	#print "cmd: $szCmdLine\n";
	system($szCmdLine);
	print "\n********* WARNING ******** $szDeveloperName removed from crontasks.pl\n\n";
	$nWarnings++;
} else {
	#print "*** Lib dir was ok, not changed...\n";
}

#***************** Check network devices ************************
my @devices = getDevices();

if (@devices < 2) {
	print "******* WARNING ******* This computer seems to have only one network device and can as of now not be used at router.\nTo find available network devices: \nsudo ip link show\nsudo nmcli device status\nsudo nmcli connection show\nsudo netstat -i\ntcpdump --list-interfaces\nNOTE! Normally only devices starting with w (wireless) and e (ethernet, cabled) count.\n**** NOTE **** You can also try to run misc/setup_network.pl to see if it finds orther device.\n";
	$nWarnings++;
}

if (!ableToPing("google.com")) {
	print "****** ERROR ******* You seem not connected (unable to ping google.com). This may also be caused by DNS setup.\n\n";
	$nErrors++;
}

#*********************** Check if DHCPDUMP is running *************************

my $bFound = programRunning("dhcpdump");
if ($bFound) {
	print "DHCPDUMP is running..\n";
} else {
	print "\n****** WARNING ********* DHCPDUMP is not running..\nIt's only required if you want this computer as a router (hosting a subnet)\nRun crontasks.pl manually and look for errors.\n\n";
	$nWarnings++;
}

#**************************Check cron jobs...

my $szLogTxt = "/var/spool/cron/crontabs/root";
if (-e $szLogTxt) {
	# directory exists
	open my $info, $szLogTxt or die "Could not open $szLogTxt: $!";
	my $bCronFound = 0;
	while(my $szLine = <$info>)  {
		if ($szLine =~ /^#.*/ ) {
		#	print "Comment: $szLine\n";
		} else {
			if ($szLine =~ /crontasks.pl/ ) {
				#print "crontasks.pl found in cron tab\n";
				$bCronFound++;
			}

			#$szLine =~ s/^\s+|\s+$//g ;     # remove both leading and trailing whitespace

			#if ($szLine ne "") { 
			#	print "Cron job: $szLine\n";
			#	$bCronFound++;
			#}
		}
	}
	close($info);

	if (!$bCronFound) {
		print "******* ERROR! Not all cron jobs set up. crontasks.pl should be there. \nOpen misc/crontasks.pl to see how.\n";
		$nErrors++;
	}
} else {
	print "******* ERROR! cron directory doesn't exist. Not yet set up.\n\nSetup it up with: sudo crontab -u root -e\n\n";
	$nErrors++;
}

checkDbVersion($dbh);	#Defined in lib_cron.pm

sub getMac {
	my ($szMac) = @_;
	if (defined($szMac)) {
		if (substr($szMac,length($szMac)-20) eq "00000000000000000000") {
			$szMac = substr($szMac, 0, length($szMac)-20);
		}
		return $szMac;
	}
	return "<no mac>";
}

sub isUpWithIP {
	my ($szNic, $szIP) = @_;
	my $result = `/sbin/ip a | grep wlp2s`;
	print "Result: $result;\n";
	if (index($result, "state DOWN") > -1) {
		print "****** ERROR! Internal network device ($szNic) is DOWN and will not work.\nThe setup should have fixed this."; 
		return 0;
	}
	if (index($result, "state UP") == -1) {
		print "****** ERROR! Looking for \"state UP\" but can't find it... \n"; 
		return 0;
	}
	if (index($result, $szIP) == -1) {
		print "****** ERROR! Internal device ($szNic) is supposed to have IP address $szIP\n"; 
		return 0;
	}
	
	return 1;
}

sub checkRouterComputer {
	print "\nChecking computer A (the one with a wifi router and sub net)\n";
	
	my $szSQL = "select inet_ntoa(internalIP) as IP, inet_ntoa(nettmask) as netmask, internalNic from setup";
	my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
	my $nSumWarnings = $nErrors+$nWarnings;	#Used to check if new warnings or errors have been issues by this sub.
	#print "$szSQL\n";
	$sth->execute() or die "execution failed: $sth->errstr()";
	my $row;
	if ($row = $sth->fetchrow_hashref()) {
		my $szInternalIP = $row->{'IP'};
	
		#ØT 250313
		if (!isUpWithIP($row->{"internalNic"}, $szInternalIP)) {
			print "Error in network setup.. Aborting...\n";
			$nErrors++;
			return;
		}
		
		if (!validIp($szInternalIP))
		#if (!defined($szInternalIP) || length($szInternalIP) < 10) 
		{
			print "\"".(defined($szInternalIP)?$szInternalIP:"[blank]")."\" is not a valid IP while checking internal IP address.\n";
		} else {
			my $szSubnet = substr($szInternalIP, 0, 11);
			if ($szSubnet eq "192.168.50." || $szSubnet eq "192.168.60.") {
				print "Great! Found internal IP (at least registered in DB) with correct ip: $szInternalIP\n"; 
			} else {
				print "******* WARNING ****** You said this is the computer with connected wifi.. But DB inducates that it's not properly set up... Internal ip: $szInternalIP\n";
				$nWarnings++;
			}
				
			print "#***** Now check DHCP server ******\n"; 
			my $szLogTxt = $szLogDir."dhcpstatus.txt";
			system("service isc-dhcp-server status > $szLogTxt");
			my @lines = getFileLines($szLogTxt);
			my $szIp = "";
			my $szStatus = "";
			my @arr = ();
			foreach (@lines)
			{
				if ($_ =~ /^.*DHCPACK\son\s(\S*).*/ ) {
					#print "**** DHCPACK found in: $_\n";
				
					$szIp = $1;
					#if (!$szIp ~~ @arr) {
					if (grep { $_ eq $szIp } @arr ){
						#print "$szIp was already in array...\n";
					} else {
						if (!@arr) {
							print "DHCP server seems to work. Assigned IP $szIp";
						} else {
							#Not the first IP found.. print separator.
							print ", $szIp";
						}
						push (@arr, $szIp);
					}
				}
				if ($_ =~ /^\s*Active:\s(\S*).*/ ) {
					#Active: inactive (dead)
					$szStatus = $1;
					if ($szStatus eq "inactive" || $szStatus eq "failed") {
						print "\n***** ERROR! DHCP server is status is: $szStatus\nTo check status yourself: service isc-dhcp-server status\nOr: sudo cat /var/log/syslog | grep dhcp
\nTo try to start it: service isc-dhcp-server start\n\n";
						$nErrors++;
					} else {
						print "**** DHCP status: $szStatus\n";
					}
				}
				#else {
				#	#print "DHCPACK not found in: $_\n";
				#}
			}
			if ($szIp eq "") {
					print "Seems like DHCP server is not issuing IP addresses (or has not yet or it's been a while since).\nYou should try to connect or disconnect and reconnect a unit to the wifi router and then try again.\nTo check dhcp server status: service isc-dhcp-server status\nOr you can check dhcp request by:\n- Stopping crontasks.pl (sudo crontab -u root -e)\n- sudo dhcpdump -i ".$devices[1]."\n\n";
			} else {
				print "\n";
			}
			

			if (dnsmasqRunning()) {
				$nErrors++;
			}
			
			#*************** Check netmask **************
			my $szNetmask = $row->{'netmask'};
			if (index($szNetmask, "255.255.") != 0) {
				print "******* ERROR ****** Netmask (localhost/index.php?f=setup to change) is used to identify computers that are in subnet. It should always start with \"255.255\"\n";
				$nErrors++;
			}
			my @cNetMask = split (/\./, $szNetmask); 
			#my $nLastElement = rindex($szNetmask, ".");
			
			if (@cNetMask != 4) {
				print "******* ERROR - never getting here... handling netmask.\n";
				$nErrors++;
			} else {
				if (($cNetMask[2]+0 == 255 && $cNetMask[3]+0 != 255) || ($cNetMask[2]+0 != 255 && $cNetMask[3]+0 == 255)) {
					print "Netmask looks fine...\n";					
				} else {
					print "****** ERROR ***** Netmask looks wrong ($szNetmask).\nThe normal netmask for small networks is 255.255.255.0\n";					
					$nErrors++;
				}
			}				
		}
		
		#************* Check dhcp setup
		#asdfasfd
		my $szDhcdIfSetup = getFileContents("/etc/default/isc-dhcp-server");
		if (index($szDhcdIfSetup,'INTERFACESv4="'.$row->{"internalNic"}.'"') == -1) {
        		print '****** ERROR ***** /etc/default/isc-dhcp-server seems not to contain INTERFACESv4="'.$row->{"internalNic"}.'".\nThis may cause problem for the dhcp server.';					
			$nErrors++;
		}
	} else {
		print "****** ERROR! **** Error reading internal IP++ from setup!\n";
		$nErrors++;
	}
	
	if ($nSumWarnings != $nErrors+$nWarnings) { 
		#New errors or warnings have been issued.
		#Maybe no need for special warning: print "******** WARNING ********
	}

	#Check connected units... 
	$szSQL = "select unitId, hex(mac) as mac, ipAddress, inet_ntoa(ipAddress) as ip, description, hostname, lastSeen, created, unix_timestamp(now())- unix_timestamp(lastSeen) as secondsSince from unit order by lastSeen desc limit 5";
	$sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
	$sth->execute() or die "execution failed: $sth->errstr()";
	my $nUnits = 0;
	my $nActiveUnits = 0;
	while ($row = $sth->fetchrow_hashref()) {
		if (!$nUnits) {
			print "\nLast registered units in subnet:\n";
		}
		#my $szLastSeen = (defined($row->{"lastSeen"})?$row->{"lastSeen"}:"<no date>");
		my $szLastSeen = $row->{"lastSeen"}; #$row->{"created"}; #($row->{"lastSeen"}?$row->{"lastSeen"}:"<no date>");
		print $szLastSeen."\t".(defined($row->{"ipAddress"})?$row->{"ip"}:"<no ip>").
					"\t".getMac($row->{"mac"}).
					"\t".(defined($row->{"hostname"})?$row->{"hostname"}:"<no hostname>")."\n";
		if ($row->{"secondsSince"} < 60*60) {	#less than an hour ago
			$nActiveUnits++;
		}
		$nUnits++;
	}
	if (!$nUnits) {
		print "******* WARNING ******** No connected units found. Errors or other warnings here should give you a clue.\n";
		$nWarnings++;
	}
	if ($nActiveUnits < 3) {
		print "******* WARNING ******** No units on subnet are registered as active.. Is that correct? (T002)\n";
		$nWarnings++;
	}
	print "\n";

	#Check port assignments....
	$szSQL = "select portAssignmentId, ifnull(unitId,-1) as unitId, unix_timestamp(now())- unix_timestamp(created) as secondsSince from unitPort order by portAssignmentId desc limit 10;";
	$sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
	$sth->execute() or die "execution failed: $sth->errstr()";
	my $nNoUnitAssignment = 0;
	my $nFirstRecord = 1;
	while ($row = $sth->fetchrow_hashref()) {
		if ($nFirstRecord && $row->{"secondsSince"} > 0){#60*10) {
			print "****** WARNING ********* No units in subnet seem to be active. Is this correct? (T003)\n";
			$nWarnings++;
		}
		$nFirstRecord = 0;
		if ($row->{"unitId"}+0 == -1) {
			$nNoUnitAssignment++;
		}
	}
	if ($nNoUnitAssignment) {
		my $szWarning = "******** ERROR ********* One or more units are active in the subnet that are not properly registered.. (T004)"; 
		print "$szWarning\n";  
		addWarningRecord($dbh, $szWarning);
		$nErrors++;
	}


} #sub checkRouterComputer()

sub checkClientComputer {
	print "\n\n******************** Checking computer C ******************\n\n";
	if (ableToPing()) {
		print "The computer is online!\n";
	} else {
		print "The computer seem not online (unable to ping google.com - may also be DNS setup)\n";
	}

	my $szExternalDevice = $devices[0];
	my $szExternalIp = ipOfDevice($szExternalDevice);
	my $szNet = substr($szExternalIp, 0, 11);
		
	if ($szNet ne "192.168.50." && $szNet ne "192.168.60.") {
		print "\n****** WARNING ****** Computers connected to switch/wifi router should have IP like 192.168.50.nn.\n\nYours is $szExternalIp\n\nThat means you're probably not connected to the the switch/wifi which is connecte to router with our system..\n";   				
		$nWarnings++;
	}
			
	#print "IP: $szExternalIp\n******* WARNING IF NOT 192.168.50.nn\n";
} #sub checkClientComputer

sub checkDhcp {
	#find the last dhcpfile. 
	my $szDir = "/root/setup/log/dhcp/handled";
	opendir my $dh, $szDir or die "Could not open dhcp log directory for reading: $!\n";	
	my @files = grep(/dhcpdump20/,readdir($dh));
	#my @files = readdir($dh);
	closedir($dh);
	my $szLastFile = "";
	foreach my $szFile (@files) {
		if ($szFile gt $szLastFile) {
			$szLastFile = $szFile;
		}
	}
	my $nFiles = scalar @files; 
	print $nFiles." files in log directory. Last : $szLastFile\nTo see: sudo cat $szDir/$szLastFile\n";
	@files = ();
	
	$szDir = "/root/setup/log/conntrack";
	opendir $dh, $szDir or die "Could not open dhcp log directory for reading: $!\n";	
	#my @files = grep(/dhcpdump20/,readdir($dh));
	@files = readdir($dh);
	closedir($dh);
	$szLastFile = "";
	foreach my $szFile (@files) {
		if ($szFile gt $szLastFile) {
			$szLastFile = $szFile;
		}
	}
	$nFiles = scalar @files; 
	print $nFiles." files in conntrack log directory. Last : $szLastFile\nTo see: sudo cat $szDir/$szLastFile\n";
	@files = ();
	
	
}

my $bIsRouterComputer = (defined($ARGV[0]) ? uc($ARGV[0]) eq "A" : 0); 
if ($ARGV[0]) {
	#print "Arg 1: ".$ARGV[0];
	if ($bIsRouterComputer) {
		checkRouterComputer();
	} else {
		if (uc($ARGV[0]) eq "C") {
			checkClientComputer();
		} else {
			if (uc($ARGV[0]) eq "B") {
				print "\n\n******************** Checking computer B (but nothing put here yet..) ******************\n\n";
			} else {
				if (uc($ARGV[0]) eq "DHCP") {
					checkDhcp();
				} else {
				
					print "\n\n*********** ERROR **** Unknown computer or parameter: ".$ARGV[0]."\n"; 
					$nErrors++;
				}
			}
		}
	}
} else {
	#Testing if (!$ARGV[0]) at the end if no errors not to make commotion...
}

#******************** Check IP setup in database *****************
#use Net::Address::IP::Local;

# Get the local system's IP address that is "en route" to "the internet":
#my $address      = Net::Address::IP::Local->public;
#print "My address: $address\n"; 

my $szSQL = "select inet_ntoa(adminIp) as ip, inet_ntoa(internalIP) as internalIP, dmesg, ifnull(unix_timestamp(now())-unix_timestamp(dmesgUpdated),1000) as secsAgo, CAST(hotspot AS UNSIGNED) as hotspot from setup";

my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
#print "$szSQL\n";
$sth->execute() or die "execution failed: $sth->errstr()";
my $dbVersion = "";
my $szInternalIP = "";
my $szAdminIP = "";
my $cSetup;
if ($cSetup = $sth->fetchrow_hashref()) {
	$szAdminIP = $cSetup->{'ip'}; 
	if (!validIp($szAdminIP)) {
		print "******* ERROR **** Invalid or missing IP in database (".$szAdminIP."). http://localhost?f=setup to fix.\n";
		$nErrors++;
	}
	if (!validIp($cSetup->{'internalIP'})) {
		print "******* WARNING **** Internal IP is not set up in database. It should be set to 192.168.50.1 in case you want to connect a subnet. http://localhost?f=setup to fix.\n";
		$nWarnings++;
	} else {
		$szInternalIP = $cSetup->{'internalIP'}; 

	}
} else {
	print "********* ERROR *** Unable to read ip addresses and dmesg from setup.. You should check if the database is properly installed.\n";
	$nErrors++;
	#exit; no need to exit
}

$nErrors += checkHotspot();

#************************ Check dmesg for specific texts that indicate that something is wrong...
#************ NOTE! Using record from above so don't put other functions between *******************
my $szDmesg = "";
my $nSecsAgo = "";

if ($cSetup) {
	$szDmesg = $cSetup->{'dmesg'};
	$nSecsAgo = $cSetup->{'secsAgo'};
	
	if ($nSecsAgo > 20) {
		#$abs_path holds the full path to crontasks.pl... get rid of the file name so it's only the directory. 
		my $nLastPos = rindex($abs_path, "/");
		my $szCrontasksScript = substr($abs_path, 0, $nLastPos)."/crontasks.pl"; 
		print "****** ERROR dmesg content is not saved to DB ($nSecsAgo > 20 sek).\nThis probably means that crontasks.pl is not cunning as cron job.\nTo check: crontab -u root -e (choose nano if asked). Put line there with:\n* * * * * perl $szCrontasksScript\n(The asterisks there mean run every minute of every hour, every hour of the day, every day of month, every day of week, every month of year - in short, every minute)\nWithout crontasks.pl running, the system will not work properly.\n";
		$nErrors++;
	}
	else {
		#print "dmesg stored $nSecsAgo seconds ago.\n"; 
	}
	
} else {
	print "****** NOT YET LEARNED TO get my own dmesg .....\n";
}

#*************** CHECK iptables **********************
my $szIptables = getDumpTxt("iptables -t nat -L");

my @cRequired = ("Chain PREROUTING (policy ACCEPT)", "Chain INPUT (policy ACCEPT)", "Chain OUTPUT (policy ACCEPT)", "Chain POSTROUTING (policy ACCEPT)", "MASQUERADE  all  --  anywhere             anywhere   ");

#my @cIpTablesArray = split("\n", $szIptables);
my $nIpTablesNotFound = 0;

foreach (@cRequired) {
	#my @matches = grep { /$_/ } @cIpTablesArray;
	
#	if (@matches) {

	if (index($szIptables, $_) >= 0) {
		#print $_." found..\n";
	} else {
		print "**** $_ NOT found..\n";
		$nIpTablesNotFound++;
	}
}

if ($nIpTablesNotFound) {
	print "\n**** WARNING **** Probably missing iptables rules. If this computer is used as router,\nthen iptables should be set up correctly to allow traffic to flow through it.\nCheck Gatekeeper document on how to do that. You can also check the misc/iptables.sh on how to set it up.\nThis has to be run every time you start the server unless you set it up to run automatically.\n\n";  
}


#NOTE! The below part is not finished so keep it at the end...
print "******************** ADD MORE FUNCTIONALITY TO INTERPRET the dmesg log\n";

my @DmsgLines = split("\n",$szDmesg); 
my $nLines = 0;
foreach (@DmsgLines)
{
	#if ($_ =~ /^.*DHCPACK\son\s(\S*).*/ ) {
	#print "Line: $_ \n";
	$nLines++;
}

#print "$nLines lines in stored dmesg log:\n$szDmesg\n";

if ($nLines < 100) {
	print "********* WARNING ****** dmesg log only contains $nLines lines.\nIt probably means that tarakernel has never been running or that crontask.pl is not set up as a cron job. Please see top of script for how.\n"; 
}

if (!moduleRunning("tarakernel")) {
	print "****** ERROR ****** tarakernel is not running.\nYou can run sudo perl compile.pl or start is manually with sudo modprobe tarakernel.\nPlease inform us if you get any error message. And also if tarakernel keeps stopping.\n"; 
	$nErrors++;
}

if (!$nErrors) {
	#Don't disturb with this if there's errors
	if (!programRunning("dmesg -w", "sudo dmesg -w")) {
		print "\n***** WARNING ***** There is no running window showing the log messages from tarakernel.\nYou can start one from terminal (Ctrl-Alt-T):\nsudo dmesg -w | grep -v \"^[[:space:]]*\$\"\nIf you're not bothered with blank lines, you can just:\nsudo dmesg -w\n\n";
		$nWarnings++;

		
		#my $szDmsg = "sudo dmesg -w | grep -v \"^[[:space:]]*\$\"";
		#system 'xterm', '-hold', '-e', $szDmsg;
		#system('gnome-terminal -x sh -c "ls|less"');
		#***** DON'T DO THIS BECAUSE IT'S OPENING IT IN SAME WINDOW...
		#system($szDmsg);
	} else {
		print "dmesg is already running so not starting one...\n";
	}
}

#print "************************************** EXITING *********";
#exit;

use 5.010;
my $filename = "../html/index.php";
my $nVerSize = -s $filename;
if (defined($nVerSize)) {	#False if running the script for temporary edit folder
	my $nLocalhostSize = -s "/var/www/html/index.php";

	if (!defined($nLocalhostSize)) {
		$nLocalhostSize = -s "/var/www/html/dashboard/index.php";
	}

	if (!defined($nLocalhostSize)) {
		print "***** ERROR ***** Unable to find index.php on /var/www/html\n";
		$nErrors++;
	}

	if ($nVerSize > $nLocalhostSize) {
		my $szWarning = "****** WARNING ******* It seems like latest version of the dashboard is not copied from www to /var/www/html\nWhen you install new version of the system, you should also:\ncd ~/programming/www (or wherever you choose to put it)\nsudo cp *.* /var/www/html\nVersion file size (programming/www): $nVerSize, localhost (/var/www/html) file size: $nLocalhostSize";
		print "$szWarning\n";
		addWarningRecord($dbh, $szWarning);
		$nWarnings++;
	}
}

#******************* Maybe should also check number of records in various tables ***********
$szSQL = "select count(*) as records from traffic";
$sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
#print "$szSQL\n";
$sth->execute() or die "execution failed: $sth->errstr()";
my $row;
if ($row = $sth->fetchrow_hashref()) {
	my $nRecords = $row->{'records'}+0;
	my $nRecordWarningLimit = 100000; 
	if ($nRecords > $nRecordWarningLimit) {
		print "\n****** WARNING **** There's $nRecords records in the traffic table.\nYou may want to delete, dump the data or increse the warning threshold by changing the \$nRecordWarningLimit variable in diagnose.pl. You may also be able to change the threshold or turn of traffic logging in the dashboard (in future version?)\n";
		$nWarnings++;
		
	}
} else {
	print "***** ERROR fetching number of records in traffic table. Probably something wrong with your database.\n";
	$nErrors++;
}

sub sameNet {
	my @cIp1 = @{$_[0]};
	my @cIp2 = @{$_[1]};
	
	#if (!defined($cIp1[0])) { print "cIp1[0] not defined\n";}
	#if (!defined($cIp2[0])) { print "cIp2[0] not defined\n";}
	#if (!defined($cIp1[1])) { print "cIp1[1] not defined\n";}
	#if (!defined($cIp2[1])) { print "cIp2[1] not defined\n";}
	#if (!defined($cIp1[2])) { print "cIp1[2] not defined\n";}
	#if (!defined($cIp2[2])) { print "cIp2[2] not defined\n";}

	if ($cIp1[0] ne $cIp2[0]) {return 0;};
	if ($cIp1[1] ne $cIp2[1]) {return 0;};
	if ($cIp1[2] ne $cIp2[2]) {return 0;};
	return 1;
	
	
	#return $cIp1[0] == $cIp2[0] && $cIp1[1] == $cIp2[1] && $cIp1[2] == $cIp2[2]; 
}

#****************** Partner setup ***********************
if (!$nErrors)	#This may be a lot so don't put it on the screen if there's errors...
{
	my $szInternal = (!defined($szInternalIP) || $szInternalIP eq ""?"[INTERNAL IP NOT SET!]":$szInternalIP);
	
	print "\nIP setup:\nMain IP (the one giving you internet): $szAdminIP\nInternal IP (for managing subnet): $szInternal\n\n";
	my $szPartners = "";
	$szSQL = "select inet_ntoa(ip) as ip, unix_timestamp(now()) - unix_timestamp(partnerStatusReceived) as statusReceived, unix_timestamp(now()) - unix_timestamp(partnerStatusReplied) as statusReplied from partnerRouter order by ip";
	my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
	$sth->execute() or die "execution failed: $sth->errstr()";
	my $nRouters = 0;
	my @cMain = split(/\./, $szAdminIP);
	#print "This is it $szAdminIP: The split:\n";
	#foreach (@cMain) {
	#	print $_."-";
	#}
	#print "\nAfter printing\n";
	my $bMainIsLan = isLanAddress($szAdminIP);  

	
	my @cInternal = split(/\./, $szInternalIP);
	
	while ($row = $sth->fetchrow_hashref()) {
		my $szPartner = $row->{'ip'};
		my @cPartner = split(/\./,$szPartner);
		$szPartners .= $szPartner."\t\t";
		
		if (@cMain == 4) {
			if ($szPartner eq $szAdminIP || $szPartner eq $szInternalIP) {
				$szPartners .= "***** ERROR ********* Same as main IP address or internal IP address.. Should be removed as partner."; 
			} else {
				if ($bMainIsLan)
				{
					if (sameNet(\@cMain, \@cPartner)) {
						$szPartners.= "Great... partners in same net.";
					} else {
						if (sameNet(\@cInternal, \@cPartner)) {
							$szPartners.= "****** WARNING **** Partner is in your subnet... That should mean they'll receive tagged traffi but not sure if that's handled yet.";
							$nWarnings++;
						} else
						{
							if (isLanAddress($szPartner))  
							{
								$szPartners.= "****** WARNING **** Partner is in another LAN. This is most likely an error (unless you have a network with multiple LANs)";
								$nWarnings++;
							} else {
								$szPartners.= "****** WARNING **** Partner is outside your lan. Meaning they probably can't reach you. You share one public IP with multiple other networs.";
								$nWarnings++;
							}
						}
					}
				} else
				{
					# Main IP address is not on lan.... 
					if (isLanAddress($szPartner)) {
						if (!sameNet(\@cInternal, \@cPartner)) {
							$szPartners.= "****** WARNING **** Your server is on WAN, but you have partner on different LAN. Sure about this?\n";
						} else {
							$szPartners.= "****** WARNING **** You have partner on LAN. Not sure if that works yet....\n";
						
						}
						$nWarnings++;
					}
				}
			}
		}
		else {
				$szPartners .= "********** ERROR ***** Wrong IP address.: ".@cMain;
		}
		
		#Add 
		my $szWarningTxt = "";
		if ($row->{'statusReceived'} < 6*60 && $row->{'statusReplied'} < 6*60) {
			$szWarningTxt = "Both receiving and sending status updates - THAT'S GREAT!";
		} else {
			if ($row->{'statusReceived'} < 6*60 || $row->{'statusReplied'} < 6*60) {
				$szWarningTxt = "Receiving or sending status from/to partner (but not both). crontasks.pl not running on both?";
			}
			else {
				$szWarningTxt = "********** WARNING ******** Status is not being exchanged. At least one seems not to be properly set up.";
				$nWarnings++;
			}
		}
		#$szPartners .= " status received: ".$row->{'statusReceived'}.", replied: ".$row->{'statusReplied'};
		$szPartners .= "\t".$szWarningTxt."\n";
		$nRouters++;
	}
	if (!$nRouters) {
		print "No partner routers are registered. The system will only tag traffic going to,\nand know how to handle incoming tragged traffic from registered partner routers.\nYou define partners in the setup meny on the dashboard (http://localhost/index.php?f=partners).\nEach partner may have multiple registered routers.\n";
	} else {
		print  "Registered partner routers:\n$szPartners";
	}
}

#***************** Check pending assistanceRequests ***********
#Note! There's also a "handled" field but it was set to b'1' while sentTime remained NULL.
$szSQL = "select count(*) as val from assistanceRequest where sentTime is null and created < DATE_SUB(NOW(), INTERVAL '2' MINUTE)";
my $nCount = getString($szSQL)+0;
if ($nCount > 0) {
	print "\n****** WARNING ******* $nCount requests for assistance are not sent by taralink!\n\n";
	$nWarnings++;
}

#******************* Sum it up ***************************
if ($nErrors) {
	print "\n******* ERRORS WERE FOUND. PLEASE SCROLL UP TO SEE ALL. THE SYSTEM WILL PROBABLY NOT RUN PROPERLY.\n";
} else {
	if ($nWarnings) {
		print "\n****** THERE ARE $nWarnings WARNINGS. PLEASE SCROLL UP TO SEE ALL\n\n";
	}

	if (!$ARGV[0]) {
		print "\nTo diagnose the system as specific unit in a demo setup, you should call as:\ndiagnose.pl A - meaning it's connected with a swtich/wifi router and sub network.\ndiagnose.pl B - meaning it's a partnering computer in the same wifi.\ndiagnose.pl C - meaning it's a sub unit conntect to the main \"A\" computer via switch/wifi.\n\n";
		print "********** WARNING ********** Especially when diagnosing a router computer,\nyou should run this: diagnose.pl A\n\n";
	}
}

print "If you're still having problem, try these:\n- sudo service isc-dhcp-server status\n";

