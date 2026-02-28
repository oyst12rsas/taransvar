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
our @EXPORT = qw( mysqlUserExist createUsersOk checkHotspot );

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

sub createSpecificUser {
	my ($szUser, $szHost, $szPass) = @_;
	
	my $szCreateUser = ($szHost eq ""?$szUser:"\'$szUser\'@\'$szHost\'");
	my $szSQL = "create user $szCreateUser identified by \'$szPass\'";
	if (runSqlCmdLineOk($szSQL)) {
		#return 1;
		#print "Run sql seemd to succeed..\n"; 
	}

	if (mysqlUserExist($szUser)) {
		return 1;
	}
}

sub grantAccessToSpecificUser {
	my ($szUser) = @_;
	my $szSQL = "GRANT insert, update, delete, select ON taransvar.* TO $szUser;";
	runSqlCmdLineOk($szSQL);

	$szSQL = "show grants for $szUser";
	my $szResults = getSqlCmdResult($szSQL);
	
	print "Sql result from $szSQL\n";
	if (index($szResults, "There is no") > -1) {
		print "No grants for this user...\n";
		return 0;
	} else {
		print "Grant(s) found for this user...\n";
		return 1;
	}
}

sub createUser {
	#Called during installation to create user .. because diffent Maria DB versions have different requirements on how to create user.
	my ($szUser, $szPass) = @_;
	if (createSpecificUser($szUser, "localhost", $szPass)) {
		my $szFullUser = "$szUser\@localhost"; # "\'$szUser\'@\'localhost\'";
		if (grantAccessToSpecificUser($szFullUser)) {
			print "User $szFullUser exists (and grants assigned) after first attempt..\n"; 
			return 1;
		}
	}
	
	if (createSpecificUser($szUser, "", $szPass)) {
		if (grantAccessToSpecificUser($szUser)) {
			print "User $szUser exists (and grants assigned) after first attempt..\n"; 
			return 1;
		}
	}
	
	print "Unable to create user $szUser\n"; 
	return 0;
}


sub createUsersOk {
	#Challenge is that some installastions only offer #1 while others #2 where:
	#1 - create user username identified by 'password';
	#2 - create user 'username'@'localhost' identified by 'password';
	#So trying first 1 then 2

	my @users = (
		['perl','RevSjoko731'],	#used by hotspot system
		['scriptUsrAces3f3','rErte8Oi98e-2_#']
		);

	#my $dbh = getConnection();
	my $bOk = 1;

	for (my $n = 0; $n < @users; $n++) {
		my $szUser = $users[$n][0];
		my $bUserOk = 1;
		if (!mysqlUserExist($szUser)) {
	#		#my $szSQL = "create user ?"."@"."'localhost' identified by ?";
	#		my $szSQL = "create user ? identified by ?";
	#		my $sth = $dbh->prepare($szSQL);
			my $szPass = $users[$n][1];
			print "Creating $szUser, $szPass\n"; 
			createUser($szUser, $szPass);			
			if (!mysqlUserExist($szUser)) {
				print "**** ERROR Unable to create user $szUser..\n";
				$bUserOk = 0;
			} else {
				print "User exists after attempt to create..\n";
			}
		}
		else {
			print "$szUser already exists.\n";
		}
		
		if ($bUserOk) {

			my $szSQL = "GRANT insert, update, delete, select ON taransvar.* TO '$szUser'@'localhost';";
			if (runSqlCmdLineOk($szSQL)) {
				print "Seems like able to grant privileges for user all DBs..\n";
			} else {
				print "Seems like unable to grant privileges for user all DBs....\n";
			}

			#my $sth = $dbh->prepare($szSQL);
			#$sth->execute($szUser) or die "execution failed: $sth->errstr()";
			print "Able to create user $szUser..\n";
		} else {
			$bOk = 0;	
			print "Unable to create user $szUser..\n";
		}
	}
	
	#$dbh->disconnect;
	return $bOk;
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


1;

