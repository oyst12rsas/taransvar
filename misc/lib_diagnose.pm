#lib_diagnose.pl
#!/usr/bin/perl
package lib_diagnose;
use strict;
use warnings;
use Exporter;

our @ISA= qw( Exporter );

# these CAN be exported.
our @EXPORT_OK = qw();

# these are exported by default.
our @EXPORT = qw( mysqlUserExist createUsersOk checkHotspot checkDhcpServer);

use autodie;
use DBI;

use func;
use lib_dhcp;
use lib_cron;

sub mysqlUserExist {
	my ($szUser) = @_;
	my $szSQL = "select User FROM mysql.user where User = '$szUser';";
	my $szLog = getLogRoot()."sql.txt";
	my $szCmd = "sudo mysql taransvar -e \"$szSQL\" > $szLog";
	system($szCmd);
	print "Cmd: $szCmd\n";
	my $szResult = getFileContents($szLog);
	print "$szResult\n";
	if (index($szResult, $szUser) > -1) {
		return 1;
	}
	#my $dbh = getConnection();
	#my $sth = $dbh->prepare($szSQL);
	#$sth->execute($szUser) or die "execution failed: $sth->errstr()";
	#my $szFound = "";
	
#	if (my $row = $sth->fetchrow_hashref()) {
#		my $szFound = $row->{'User'};
#		return 1;
#	}
	return 0;
}

sub getSqlCmdResult {
	my ($szSQL) = @_;

	my $szLog = getLogRoot()."sql.txt";
	my $szCmd = "sudo mysql taransvar -e \"$szSQL\" > $szLog";
	system($szCmd);
	print "Cmd: $szCmd\n";
	my $szResult = getFileContents($szLog);
	print "Result: $szResult\n";
	return $szResult;
}


sub runSqlCmdLineOk {
	my ($szSQL) = @_;

	my $szLog = getLogRoot()."sql.txt";
	my $szCmd = "sudo mysql taransvar -e \"$szSQL\" > $szLog";
	system($szCmd);
	print "Cmd: $szCmd\n";
	my $szResult = getFileContents($szLog);
	print "Result: $szResult\n(***** Find a ways to check if ok...)\n";
	return 0;
}

sub createUsersOk {
    # Compatibility entry point: no SQL strings containing passwords reach logs.
    return system('python3', '/usr/local/lib/tarasec/provision_local_database.py') == 0;
}

sub checkHotspotIpfm {
	my $nErrors = 0;
	my $nWarnings = 0;
	#check ipfm
	my $szNewestFile = getNewestFile("/var/log/ipfm/subnet/minute/archived");
	
	if ($szNewestFile eq "") {
		print "****** ERROR! No data usage files found.\n";
		$nErrors++;
		my $szLogFile = getLogRoot()."ipfm.txt";
		system("sudo cat /var/log/syslog | grep ipfm > $szLogFile");
		my @cLines = getFileLines($szLogFile);
		my $nErr = 0;
		foreach (@cLines) {
			if (index($_, "error") > 0 || index($_, "unable") > 0) {
				if (!$nErr) {
					print "\nSuspicious line(s) found in syslog:\n"; 
				}
				print "$_\n";
				$nErr++;
			}
		}
		if ($nErrors) {
			print "\n$nErrors lines found. Check yourself with: sudo cat /var/log/syslog | grep ipfm\n";
		}
		
	} else {
		print "Last usage file: $szNewestFile\n"; 
	}
	
	if (!programRunning("ipfm")) {
		print "**** WARNING ipfm is not running.\n";
		$nWarnings++;
	}
	return $nErrors;
}


sub checkHotspot {
	my $cSetup = getSetup();
	if (!$cSetup->{"hotspot"}+0) {
		print "Not set up as hotspot. Skipping checking.\n";
		return;
	}
	
	my $nErrors = checkHotspotIpfm();
	return $nErrors;
}

sub checkDhcpServer {
	my $nErrors = 0;
	print "Check DHCP server (in case you're supposed to have one...)";

#        my @pids = `systemctl status isc-dhcp-server.service | grep Active`;
        my $status = `systemctl status isc-dhcp-server.service | grep Active`;
        #chomp @pids;
		print "isc-dhcp-server status:\n $status\n";

	if ($status =~ /failed/) {
    	print "****** ERROR dhcp server is not running. Connected clients will not receive IP adress\n";
		print "\nTo check status, run:\nsudo systemctl status isc-dhcp-server.service\nAnd:\nsudo journalctl -u isc-dhcp-server -b --no-pager -n 200\n\n";
		$nErrors++;
	}

	return $nErrors;
}


1;

