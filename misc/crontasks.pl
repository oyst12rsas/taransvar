#crontasks
#cron is the linux system for scheduled tasks. To schedule tasks, issue:  
#sudo crontab -u root -e
#To run it every 5 minutes, add this:
#* * * * * sudo perl <insert correct path>/crontasks.pl
#NOTE! This script needs to load programming/misc/func.pm. For that, use lib statement below should be set properly.. 
#Great if you test the generic ones.. If they're not working, you may have to hard code (like I had to).  
#NOTE! Since this script is run as cron task, and from other dir, the path has to be hardcoded. diagnose.pl will change <developer> to /<your user name>/

use lib ('/root/taransvar/perl');
#use lib ('.');
		
use strict;
use warnings;
use autodie;
use DBI;
use func;	#NOTE! See comment above regarding lib..
use lib_dhcp;
use lib_cron;
use lib_net;

use POSIX qw(setsid);
use Fcntl qw(:flock);

print "Usage:\nperl crontasks.pl\tRun only debugging tasks then quit.\nperl crontasks.pl cron\t\tTo run as by cron\nperl crontasks.pl force\t\tStart even if crontasks.pl already running\n";

#Prevent that multiple instances are running by using lock file
my $szCrontasksLockFileName = '/tmp/crontasks.lock';
my $lock_fh;   # must stay alive

if (!$ARGV[0] || $ARGV[0] ne "force") 
{
	open($lock_fh, '>', $szCrontasksLockFileName) or die "Cannot open lock file: $!";
	flock($lock_fh, LOCK_EX | LOCK_NB) or die "Already running. Aborting.\n";
}
# keep $fh open for whole script lifetime
print "Able to lock crontasks lock file.\n";

sub reportStatus {
	my ($dbh) = @_;
	use JSON;

	my %json;

	my $sthSetup = $dbh->prepare("select adminIP as nAdminIp, LPAD(HEX(adminIP), 8, '0') as adminIP, nettmask as aNettmask, LPAD(HEX(nettmask), 8, '0') as nettmask, secondsSinceBoot, TIMESTAMPDIFF(SECOND, dmesgUpdated, NOW()) AS dmesg, inet_ntoa(globalDb1ip) as Db1, inet_ntoa(globalDb2ip) as Db2, inet_ntoa(globalDb3ip) as Db3 from setup") or die "prepare statement failed: $dbh->errstr()";
	$sthSetup->execute() or die "execution failed: $sthSetup->errstr()";
	my $cSetup = $sthSetup->fetchrow_hashref();
	$sthSetup->finish();

	$json{"ip"} = (defined $cSetup->{"nAdminIP"}?$cSetup->{"nAdminIP"}+0:0);
	$json{"nett"} = (defined $cSetup->{"nNettmask"}?$cSetup->{"nNettmask"}:0);
	$json{"boot"} = $cSetup->{"secondsSinceBoot"}+0;
	$json{"msg"} = $cSetup->{"dmesg"};

	$json{"knl"} = (moduleRunning("tarakernel")?"1":0);
	if (!$json{"knl"}) {
		#tarakerlen is not running.. try to start it. 
		system ("modprobe tarakernel");
		saveWarning("Tarakernel was not running when reporting status. Trying to start it\n");
	}

	#$json{"lnk"} = (programRunning("taralink")?"1":0);	
	$json{"lnk"} = (programRunningLockFileHeld("/tmp/taralink.lock")?1:0);
	if (!$json{"lnk"}) {
		system("nohup /root/taransvar/taralink >>/tmp/taralink.log 2>&1 &");		
		sleep(7);	#2 sec seemed not enoug (got "still not running after trying to start it")
		if (programRunningLockFileHeld("/tmp/taralink.lock")) {
			saveWarning("Taralink was not running when reporting status. Seems like managed to start it\n");
		} else {
			saveWarning("*** WARNING *** Taralink is still not running after trying to start it.\n");
		}
	}

#	$json{"cron"} = (programRunning("crontasks.pl")?"1":0);

	my $bLockFileHeld = (programRunningLockFileHeld($szCrontasksLockFileName)?1:0);
	print "Crontab lock file is ".($bLockFileHeld?"held (crontasks.pl is running)\n":"NOT HELD. (crontasks.pl is NOT running)")."\n";

	$json{"cron"} = ($bLockFileHeld?1:0);	#Doesn't make sense... Not getting here unless the script is running. Put it in separate checking script

	chomp(my $line = `df -h / | tail -1`);
	my @f = split(/\s+/, $line);
	$json{"df"} = "$f[1] $f[2] $f[3]";

	$json{"ld"} = `cut -d' ' -f1-3 /proc/loadavg`;
	chomp($json{"ld"});

	$json{"mem"} = `free -h | awk '/Mem:/ {print \$3 "/" \$2}'`;
	chomp($json{"mem"});

	#my $szSQL = "select inet_ntoa(ip) from traffic where coalesce(lastSeen, created) > NOW() - INTERVAL 1 MINUTE";
	my $szSQL = "SELECT COUNT(DISTINCT ipFrom) AS unique_ips FROM traffic WHERE COALESCE(lastSeen, created) > NOW() - INTERVAL 5 MINUTE AND ipFrom <> INET_ATON('10.100.0.1') and ipFrom BETWEEN INET_ATON('10.100.0.0') AND INET_ATON('10.100.255.255')";

	#To see the user names: 
	#SELECT DISTINCT INET_NTOA(ipFrom) FROM traffic WHERE COALESCE(lastSeen, created) > NOW() - INTERVAL 5 MINUTE   AND (ipFrom & INET_ATON('255.255.0.0')) = INET_ATON('10.100.0.0'); 

	my $sthCount = $dbh->prepare($szSQL);
	$sthCount->execute() or die "execution failed: $sthSetup->errstr()";
	my $cCount = $sthCount->fetchrow_hashref();
	$json{"usr"} = $cCount->{"unique_ips"};
	$sthCount->finish();

	#Find seconds since the last dmesg was logged.
	$szSQL = "select TIMESTAMPDIFF(SECOND, created, NOW()) AS seconds_since from dmesg order by dmesgId desc limit 1";
	my $sth = $dbh->prepare($szSQL);
	$sth->execute() or die "execution failed: $sthSetup->errstr()";
	my $cSeconds = $sth->fetchrow_hashref();
	$json{"dmesg"} = $cSeconds->{"seconds_since"};
	$sthCount->finish();

	#************* Assemble the json and send it to DB Servers and store it locally.

	my $cJson = encode_json(\%json);
	print "Status: $cJson\n";

	for my $i (1 .. 3)
	{
		my $fieldName = "Db$i";
		if (defined $cSetup->{$fieldName}) {
			my $ip = $cSetup->{$fieldName};
			my $szUrl = "http://$ip/script/statusReport.php?json=".urlencode($cJson);
    		print "$fieldName, $szUrl\n";
			
			my $szReply = `wget -q -O - "$szUrl" 2>&1`;
			#chomp $szReply;
			$szReply =~ s/^\s+|\s+$//g;
			print "Reply: $szReply\n";
			if ($szReply eq "ok")
			{
				print "Able to send status to DB server $ip!\n";
			} else {
				print "***** ERROR ***** Unable to send status to DB server $ip. Reply is $szReply!\n";
			}

		} else
		{
			print "global".$fieldName."ip not set. Skipping.\n";
		}
	}

	print "\n\n********************** About to store status in setup table *****************\n";
	$szSQL = "update setup set networkStatus = ?, networkStatusChecked = now()";
	$sthSetup = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
	$sthSetup->execute($cJson) or die "execution failed: $sthSetup->errstr()";
	print "\n\nStatus stored in setup table\n";
}

sub check_dhcpEvent {
	my ($conn) = @_;
	my $szSQL = "select dhcpEventId, seenAt, interfaceName, srcIp, inet_ntoa(srcIp) as src, dstIp, inet_ntoa(dstIp) as dst, clientMac, yourIp, inet_ntoa(yourIp) as aYourIp, coalesce(hostname,'') as hostname, vendorClass, dhcpMessageType from dhcpEvent where handled = 0 limit 10";

	my $sth = $conn->prepare($szSQL);
	print "Handling unhandled dhcpEvent records\n";
	$sth->execute() or die "execution failed: $sth->errstr()";
	my $lookup = $conn->prepare("select unitId, ipAddress, inet_ntoa(ipAddress) as ip, coalesce(description,'') as description, hostname from unit where left(mac,6) = UNHEX(?)");
	my $update = $conn->prepare("update dhcpEvent set unitId = ?, handled = b'1' where dhcpEventId = ?");

	#These statments will be initialized if needed...
	my $insert = 0;
	my $updateHostname = 0;
	my $updateIp = 0;
	my $updateUnit = 0;

	while (my $row = $sth->fetchrow_hashref()) {
		my $bUnitUpdated = 0;
		print "Found id: $row->{'dhcpEventId'}: $row->{'src'}, $row->{'dst'}, $row->{'aYourIp'}, $row->{'clientMac'}, $row->{'hostname'}, $row->{'clientMac'}\n";
		my $mac = $row->{clientMac}; 		
		$mac =~ s/://g; 
 		$lookup->execute(uc($mac)) or die "execution failed: $sth->errstr()";
		if (my $unit = $lookup->fetchrow_hashref()) {
			print "   Unit nr $unit->{'unitId'} found: $unit->{'ip'}, $unit->{'description'}, $unit->{'hostname'}\n";
			$update->execute($unit->{'unitId'}, $row->{'dhcpEventId'}) or die "execution failed: $sth->errstr()";

			if ((!defined $unit->{'hostname'} || $unit->{'hostname'} eq "") && $row->{'hostname'} ne "") {
				if (!$updateHostname) {
					$updateHostname = $conn->prepare("update unit set hostname = ?, lastSeen = now() where unitId = ?");
				}
				$updateHostname->execute($row->{'hostname'}, $unit->{'unitId'}) or die "execution failed: $sth->errstr()";
				$bUnitUpdated = 1;
				print "Hostname changed from $unit->{'hostname'} to $row->{'hostname'}\n";
			} else {
				#print "Hostname was set: $unit->{'hostname'}\n";
			}

			if (!$unit->{'ipAddress'} && $row->{'yourIp'} > 0) {
				if (!$updateIp) {
					$updateIp = $conn->prepare("update unit set ipAddress = ?, lastSeen = now() where unitId = ?");
				}

				$updateIp->execute($row->{'yourIp'}, $unit->{'unitId'}) or die "execution failed: $sth->errstr()";
				$bUnitUpdated = 1;
				print "IP changed from $unit->{'ipAddress'} to $row->{'yourIp'}\n";
				
			}
			else {
					print "IP was set or not yet available: $unit->{'ip'}\n";
			}

			if (!$bUnitUpdated) {
				#unit->lastSeen is not yet updated... do so.
				if (!$updateUnit) {
					$updateUnit = $conn->prepare("update unit set lastSeen = now() where unitId = ?");
				}
				$updateUnit->execute($unit->{'unitId'}) or die "execution failed: $sth->errstr()";
			}

		} else {
			if (!$insert) {
				$insert = $conn->prepare("insert into unit (mac, ipAddress, hostname, lastSeen, dhcpClientId) value (UNHEX(?), inet_aton(?), ?, now(), b'0000000')");
			}
			$insert->execute(uc($mac), $row->{'yourIp'}, $row->{'hostname'}) or die "execution failed: $sth->errstr()";
		}
	}
	$lookup->finish;
	$update->finish;
	$sth->finish;

	if ($insert) {
		$insert->finish;
	}

	if ($updateHostname) {
		$updateHostname->finish;
	}

	if ($updateIp) {
		$updateIp->finish;
	}
}



sub register_internal_infection {
	#*** NOTE! taralink is doing similar for records in hackReport table... Should be coordinated. 
	my ($conn, $nUnitId, $nIp) = @_;

	my $szSQL = "select infectionId, unitId, handled, inserted, status from internalInfections where ip = inet_aton(?) and (unitId is null or unitId = ?) order by infectionId desc limit 1";

	my $sth = $conn->prepare($szSQL);
	print "Searching for infection: ip: $nIp, unit id: $nUnitId\n";
	$sth->execute($nIp, $nUnitId) or die "execution failed: $sth->errstr()";
	if (my $row = $sth->fetchrow_hashref()) {
		my $nInfectionId = $row->{'infectionId'};
		print "Infection already registered. ID: $nInfectionId\n";
		my $szSQL = "update internalInfections set unitId = ?, lastSeen = now() where infectionId = ?";
		my $updateSth = $conn->prepare($szSQL);

		if (!defined $updateSth) {
    		die "Prepare failed: " . $conn->errstr;
		}

		$updateSth->execute($nUnitId, $nInfectionId) or die "execution failed: $sth->errstr()";
		$updateSth->finish;
	}
	else
	{
		print "Registering new infection - ip: $nIp, unit id: $nUnitId.\n";
		my $szSQL = "insert into internalInfections (ip, nettmask, status, unitId) values (inet_aton(?), inet_aton('255.255.255.255'), 'firsttime', ?)";
		my $insertSth = $conn->prepare($szSQL);
		$insertSth->execute($nIp, $nUnitId) or die "execution failed: $sth->errstr()";
		$insertSth->finish;
	}	

	$sth->finish;
}

sub print_hashref {
	my ($row) = @_;
    for my $key (keys %$row) {
        my $val = defined $row->{$key} ? $row->{$key} : "NULL";
        print "$key = $val\n";
    }
    print "----\n";
}

sub get_unit_from_conntrack {
    my (%args) = @_;

	my $ct = find_conntrack_entry(
    		proto       => $args{proto},
    		target_ip   => $args{target_ip},
    		target_port => $args{target_port}
		);

	my $nUnitId = 0;

	if ($ct) {
		#NOTE **** MAY OR MAY NOT BE IN SUBNET (subnet if my units called honeypot reporting to me or external if external computer attacked my port that is forwarded to other honeypot) - or variations of the two?
	    print "Real attacker: $ct->{orig_src_ip}:$ct->{orig_src_port}\n";
		
		my $szSQL = "select unitId from unit where ipAddress = inet_aton(?) order by lastSeen desc limit 1";
		my $conn = $args{conn};
		my $sth = $conn->prepare($szSQL);
		$sth->execute($ct->{orig_src_ip}) or die "execution failed: $sth->errstr()";
		if (my $unit = $sth->fetchrow_hashref()) {
			$nUnitId = $unit->{'unitId'};
			print "Found unit in subnet. ID: $nUnitId\n";
		}
		$sth->finish;

		#update internalInfections
		register_internal_infection($conn, $nUnitId, $ct->{orig_src_ip});

	} else {
		print "Returning from get_unit_from_conntrack - none found for $args{target_ip}:$args{target_port}\n";
	}

	return $nUnitId;
}

sub getRouterIpOf
{
	my ($cSourceIp) = @_;
	my $conn = getConnection();
	my $szSQL = "SELECT name, ip, inet_ntoa(ip) as aIp, nettmask, inet_ntoa(nettmask) as aNettmask, partnerStatusReceived, BIT_COUNT(nettmask) AS mask_bits FROM partnerRouter R join partner P on P.partnerId = R.partnerId WHERE (inet_aton(?) & nettmask) = (ip & nettmask) ORDER BY mask_bits DESC LIMIT 1";
	my $sth = $conn->prepare($szSQL);
	$sth->execute($cSourceIp) or die "execution failed: $sth->errstr()";
	if (my $rec = $sth->fetchrow_hashref()) {
			return $rec->{'aIp'};
	}

	return "";
}

sub trySendWarningToRouter
{
	#asdf
	my ($row) = @_;
	print "About to look for the router of $row->{'src'}\n";
	my $szRouterIp = getRouterIpOf($row->{'src'});
	if ($szRouterIp ne "") {
		print "$row->{'src'} belongs to $szRouterIp. Send message.\n";
		#asdfasdf
#		my $url = "http://$szRouterIp/script/config_update.php?f=report&ip=".$row->{'src'}."&port=".(defined $row->{'src_port'}?$row->{'src_port'}:0)."&wt=".$row->{'description'};
		my $url = "http://$szRouterIp/script/config_update.php";

		my %params = (
		    f    => "report",
    		ip   => $row->{'src'},
    		port => defined $row->{'src_port'} ? $row->{'src_port'} : 0,
    		wt   => $row->{'description'},
		);

		#my $urlStr = $url."?f=".$params->{'f'}."&ip=".$params['ip']."&port=".$params['port']."&wt=".$params['wt'];
		#print "$urlStr\n";

		my $szReply = getUrl($url, %params);
		$szReply = trim($szReply);

		#my $urlStr = $url."?".join ", ", map { "$_=%params{$_}" } keys %params;
		
		if ($szReply eq "ok") {
			print "SUCCESS sending message!\n";
		} else {
			print "ERROR SENDING MESSAGE: $szReply\n";
		}

		#asdfasdf
	}
	else {
		print "Unable to find the router of $row->{'src'}\n";
	}
}

sub handle_syslogThreat_record {
	my ($conn, $row) = @_;

	if (! defined $row->{'protocol'} || (lc($row->{'protocol'}) ne "tcp" && lc($row->{'protocol'}) ne 'udp' && lc($row->{'protocol'}) ne 'icmp')) {
		print "Unknown or unspecified protocol (only tcp, udp and ICMP allowed). Skipping...\n";
		my $szSQL = "update syslogThreat set handling = 'Unhandled protocol', handled = b'1' where syslogThreatId = ?";
		my $sth = $conn->prepare($szSQL);
		$sth->execute($row->{syslogThreatId}) or die "execution failed: $sth->errstr()";
		$sth->finish;
		return;
	}

	my $lookup = "src=$row->{'src'} dst=$row->{'dst'} sport=$row->{'src_port'} dport=$row->{'dst_port'}";
	print "About to handle id $row->{'syslogThreatId'} - $lookup\n";

	my $nUnitId = get_unit_from_conntrack(
			conn 		=>$conn,
    		proto       => $row->{'protocol'},
    		target_ip   => $row->{'dst'},
    		target_port => $row->{'dst_port'},
		);

	print "Finishing for syslogThreat ID: $row->{syslogThreatId}\n";

	if ($nUnitId) {
		my $szSQL = "update syslogThreat set unit_id = ?, handled = b'1', handling = 'My unit. Unitid set' where syslogThreatId = ?";
		my $sth = $conn->prepare($szSQL);
		$sth->execute($nUnitId, $row->{syslogThreatId}) or die "execution failed: $sth->errstr()";
		$sth->finish;
		print "Unit found with id $nUnitId. Setting.\n";

		#Mark the unit as seen...

	} else {
		#print "***** WARNING! $row{target_ip}:$args{target_port} not found by conntrack! Because it's external attacking honeypot I'm port forwarding to? (if so, report to global DB server)\n";
		trySendWarningToRouter($row);

    	print "No conntrack match found.\n";
		my $szSQL = "update syslogThreat set handled = b'1', handling = 'Alien unit: $row->{'dst'}:$row->{'dst_port'}' where syslogThreatId = ?";
		my $sth = $conn->prepare($szSQL);
		$sth->execute($row->{syslogThreatId}) or die "execution failed: $sth->errstr()";
		$sth->finish;
	}

}

sub handle_syslogThreat_table
{
	#finding unitId for records in syslogThreat by calling conntrack... Should probably also put in 
	my ($dbh) = @_;

	my $szSQL = "select syslogId, syslogThreatId, src_ip, inet_ntoa(src_ip) as src, src_port, dst_ip, inet_ntoa(dst_ip) as dst, dst_port, protocol, service, description from syslogThreat where handled is null limit 100";
	my $sth = $dbh->prepare($szSQL);
	print "\n\nFinding unhandled syslogThreat records.\n";
	$sth->execute() or die "execution failed: $sth->errstr()";
	my @cIDs;
	my $nFound = 0;
	my $nSkipped = 0;

	my @cSkipTraffic = (
    	["10.0.0.255", "10.0.0.4"],
    	["224.0.0.251", "10.0.0.138"],
    	["224.0.0.251", "10.0.0.4"],
		);

	while (my $row = $sth->fetchrow_hashref()) {
		$nFound++;

		my $szLookupIp = $row->{'ipFromA'};

		my $bSkip = 0;

		foreach my $skip (@cSkipTraffic) {
		    my ($a, $b) = @$skip;

		    if (
		        ($a eq $row->{'src'} && $b eq $row->{'dst'}) ||
		        ($b eq $row->{'src'} && $a eq $row->{'dst'})
    				) {
        		$bSkip = 1;
        		last;
    		}
		}

		if (!$bSkip) {
			print_hashref($row);
		}

		if ($bSkip) {
			push @cIDs, $row->{'syslogThreatId'};
			#print "Skipping $row->{'src'} -> $row->{'dst'}\n";
			$nSkipped++;
		} else {
			if (! defined $row->{'service'}) {
				#print "Service field not set for $row->{'syslogThreatId'} - setting to handled.\n";
				push @cIDs, $row->{'syslogThreatId'};
			} else {
				if ($row->{'service'} eq 'cowrie') {
					print ("$row->{'src'}:$row->{'src_port'} -> $row->{'dst'}:$row->{'dst_port'}\n");
					handle_syslogThreat_record($dbh, $row);	#Used for both cowrie and iptables for now
				} else {
					if ($row->{'service'} eq 'iptable' || $row->{'service'} eq 'iptables') {
						print ("iptables found $row->{'src'}:$row->{'src_port'} -> $row->{'dst'}:$row->{'dst_port'} (set to handled)\n");
						handle_syslogThreat_record($dbh, $row);	#Used for both cowrie and iptables for now
						#asdfasdf
						push @cIDs, $row->{'syslogThreatId'};
					} else {
						print "Unknown record found: $row->{'service'}. Setting to handled.\n";
						push @cIDs, $row->{'syslogThreatId'};
					}
				}
			}
		}
	}

	$sth->finish();	
	$sth = $dbh->prepare("update syslogThreat set handled = b'1' where syslogThreatId = ?");

	foreach my $nId (@cIDs) {
		#print "Handle id $nId\n";
		$sth->execute($nId) or die "execution failed: $sth->errstr()";
	}
	$sth->finish();	
	print "$nFound records handled. $nSkipped records skipped.\n";
}


sub check_start_perl_bg_script
{
	my ($script, $pidfile, $logfile) = @_;

    # Check if already running
    if (-f $pidfile)
    {
        open(my $pf, "<", $pidfile) or die "Cannot read pidfile: $!";
        my $oldpid = <$pf>;
        chomp $oldpid;
        close($pf);

        if ($oldpid && kill(0, $oldpid))
        {
            print "script already running (PID $oldpid)\n";
            return;
        }
        else
        {
            print "Stale pidfile found, removing\n";
            unlink $pidfile;
        }
    }
	else
	{
		print "pidfile not found.\n";
	}

    my $pid = fork();
    die "fork failed: $!" unless defined $pid;

    if ($pid == 0)
    {
        # --- child (daemon) ---
        chdir "/" or die "chdir failed: $!";
        setsid() or die "setsid failed: $!";

        open(STDIN,  "<", "/dev/null") or die $!;
        open(STDOUT, ">>", $logfile) or die $!;
        open(STDERR, ">>", $logfile) or die $!;

        # Write PID file
        open(my $pf, ">", $pidfile) or die "Cannot write pidfile: $!";
        print $pf $$;
        close($pf);

        exec("/usr/bin/perl", $script) or die "exec failed: $!";
    }

    print "Script started (PID $pid)\n";
}

sub start_iptables_monitor
{
	my $script     = "/root/taransvar/perl/iptables_log_monitor.pl";
	my $pidfile    = "/root/setup/log/iptables_monitor.pid";
	my $logfile    = "/root/setup/log/iptables_monitor.log";

	check_start_perl_bg_script($script, $pidfile, $logfile);
}

sub start_local_iptables_monitor
{
	my $script     = "/root/taransvar/perl/log_iptables_drops.pl";
	my $pidfile    = "/root/setup/log/local_iptables_monitor.pid";
	my $logfile    = "/root/setup/log/local_iptables_monitor.log";

	check_start_perl_bg_script($script, $pidfile, $logfile);
}



my $nSecondsToSleepBetweenIterations = 5;
my $nNumberOfWhoIsLookupsPerIteration = 5;	#Increase if too few have owner name in traffic list in http://localhost/index.php?f=traffic

my $nice_timestamp = getNiceTimestamp();
print "Started: $nice_timestamp\n\n";

my $pSetup = getSetup();

my $dbh = getConnection();
setCronLibDbh($dbh);

#if (!$ARGV[0]) {
if (!runningAsCron() && !runningBootCheck())	#Run "sudo perl crontasks.pl whatever_except_cron_and_boot" to run this section. 
{
	#To debug crontasks.pl, best way is to put your code here.... 
	saveWarning("Debugging crontasks.pl or crontab is not set to run crontasks.pl with cron as parameter.");
	#TO DEBUG crontasks.pl, do as follows:
	#- Remove the "#" in front of the saveWarning() and the exit call below (this line + 5?)
	#  That will make crontasks.pl run as cron job exit at this point and place a warning in your dashboard so you don't forget to enable it again
	# - Run crontasks.pl manually with: sudo crontasks.pl sometext
	#  That way you can check any debug code without the cron job distrubing the process.
	#Displays a warning in dashboard so don't forget to disable this code...
	print "********* Running debug tasks...\n";
	#reportStatus($dbh);
	#handle_syslogThreat_table($dbh);
	logDmesg();		#lib_cron.pm

	#check_dhcpEvent($dbh);	

	#print (networkSetupOk()?"Network set up properly":"Failed to set up network!");
	#checkRequests();
	#startTaraLinkOk();
	#handleConntrack($dbh);
	#start_process_dhcpdump($pSetup->{"internalNic"});	#NOTE! Just making sure dhcp_capture.pl is running..
	#checkDbVersion($dbh);
	
	#workshopSetup();
	#dhcpServerStatusOk();
	#doKill("taralink");
	#checkWhoIs($dbh, $nNumberOfWhoIsLookupsPerIteration);
	#sendPendingWgets();
	#checkNetworkSetup();
	#startFirewall();
	#dhcpServerStatusOk();
	#setupPortForwarding();
	#print "***** System ".(systemBooted()?"booted since last run.":"did NOT boot since last run.")."\n";
	
	#if (!networkSetupOk()) {
	#	print "************ NETWORK SETUP NOT OK. ABORTING ************\n";
	#} else {
	#	print "******* Network setup ok *************\n";
	#}
	
	#startTaraSystemsOk();	
	print "Finishing debugging code.. To run as crontasks.pl would, add \"cron\" as parameter\n";
	exit;
}

my $nTimeStarted = time();
#my $szSysRoot = "/root/setup";
#my $szLogRoot = $szSysRoot."/log/";
#my $szLogFile = $szSysRoot."/log/log.txt"; 

my $nWaitNMinutesBeforeDoingAnything = 1;

if (uptime() < $nWaitNMinutesBeforeDoingAnything * 60) {
	exit;
}

if (0) #NO LONGER DO THIS HERE... Handled by by startup.pl 
#if (systemBootedMinutesAgo(1) || runningBootCheck())	#Run "sudo perl crontasks.pl boot" to run this section.
{
	addWarningRecord($dbh, "System boot discovered. Running diagnostics.");

	if (!networkSetupOk()) {	#lib_net.pl
		my $szMsg = "************ NETWORK SETUP NOT OK ************"; 
		saveWarning($szMsg);
		print "$szMsg\n";
	} else {
		print "******* Network setup ok *************\n";
	}
	
	print "Finished boot taks.... Exiting to wait for next run to do other tasks.\n";
	exit;
}

 #my ($sec,$min,$hour,$mday,$mon,$year,$wday,$yday,$isdst)=localtime(time);
 #   my $nice_timestamp = sprintf ( "%04d%02d%02d-%02d:%02d:%02d", $year+1900,$mon+1,$mday,$hour,$min,$sec);

checkRequests();	#See lib_cron.pm 	check setup.requestReboot  (Set from hotspot setup menu choice)	
createDirectories();
#fixDevicesOldWay(); - 260311 - Don't do this... it messed up good setup when there's multiple NICs
updateGlobalDemo(); #NOTE! Not reflecting the new code where each user may have individual demo setup (not yet working properly)
print "Starting workshopSetup()\n";
workshopSetup();	#If workshopId is set in dashboard setup, it will register other computers with same workshopId as partners.
start_iptables_monitor();	#Check if iptables_log_monitor.pl is already running. If not, starts it
start_local_iptables_monitor();
logDmesg();		#Just ensuring that worker_read_dmesg.pl is running
print "Starting start_process_dhcpdump()\n";
if (defined $pSetup->{"internalNic"})		#NOTE! This is not tested!!!
{
	start_process_dhcpdump($pSetup->{"internalNic"});	#NOTE! Just making sure dhcp_capture.pl is running..
} else
{
	print "No internal nic. Dropping dhcp_dump.\n";
}
reportStatus($dbh);

#handleRequestsForDmsg();


#NOTE! Bots need log from targetHost relayed by botHost because it's the one that keeps track on port assignments....
#else {
#	if ($szIam && $szIam eq "bot") {
#		#Should request my ip and port from targetHost and maybe request status from botHost but already getting that...... what else?
#	} else {
#		print "***** WARNING **** iAm field is not set... should be fixed in localhost...\n";
#	}
#}

$| = 1; # Disable output buffering

#Uncomment to debug checkWhoIs()
#checkWhoIs();
#exit;

#Now check if gets here after running approximately 10 seconds..
my $nCount = 0;

while (time() - $nTimeStarted < 52)
{
	#Call script with some parameter do do debugging
	#Enable some warnings here so you remember to enable again...
	#saveWarning("handleConntrack() removed from cron job");

	check_dhcpEvent($dbh);	
	handleConntrack($dbh);	#NOTE! Import port assignments. Import dhcp leases before this..
	checkWhoIs($dbh, $nNumberOfWhoIsLookupsPerIteration);
	sendPendingWgets();
	handle_syslogThreat_table($dbh);	#iptables drops ++ are handled here.

	print "\nWaiting to do repetitive tasks (dmesg capture, whois lookups, ++?). Ctrl-C to break\n";
	sleep $nSecondsToSleepBetweenIterations;
	my $nSecondsSinceStart = time() - $nTimeStarted;
	print "$nSecondsSinceStart seconds.\n";
	$nCount++;
}

$dbh->disconnect;

if ($nCount < 5 && $nSecondsToSleepBetweenIterations > 0) {
	print "****** WARNING crontasks.pl only managed to make $nCount iterations.\nYou may consider to reduce \$nSecondsToSleepBetweenIterations from ".$nSecondsToSleepBetweenIterations."\n";  
} else {
	print "\nFinished! Managed $nCount iterations.\n";
}

