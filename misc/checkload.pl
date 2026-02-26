#!/usr/bin/perl
#Checking server load and if too high, reports to the network that doesn't want infected traffic
#sudo crontab -u root -e
#* * * * * sudo perl <insert correct path>/checkload.pl

my $n1minWarningThreshold = 10;
my $n5minWarningThreshold = 5;
my $n15minWarningThreshold = 2;

my $sServerIpAddress = "";	#if not specified, use router IP address from setup
my $nServerPort = 0;		#port = 0 means all ports on this IP

use lib ('.');

use strict;
use warnings;
use autodie;
use DBI;
use func;

my $szLoadAvgFile = "/proc/loadavg";
#cat /proc/loadavg
#0.44 0.50 0.61 2/910 117293

#This script will initiate sending request to all partner routers if the load exceeds the below mentioned limits. 
#For now, you can play with this, but when the system is implemented, you should know what you're doing.
#Request to drop packages from presumed infected systems will remain until canceled through the dashboard.

$n1minWarningThreshold = 0.2;
$n5minWarningThreshold = 0.2;
$n15minWarningThreshold = 0.1;

my $szSysRoot = "/root/setup";

if (-d $szSysRoot) {
    # directory called cgi-bin exists
    print "Setup directory already exists...\n";
}
else {
       # system("mkdir ".$szSysRoot);
}
if (-d $szSysRoot."log") {
    # directory called cgi-bin exists
    print "Setup log directory already exists...\n";
}
else {
       # system("mkdir ".$szSysRoot."log");
}

my $dbh = getConnection();

if (!$dbh)
{
	print "Error connecting (maybe database is not yet installed!\n";
	exit;
}

open my $info, $szLoadAvgFile or die "Could not open $szLoadAvgFile: $!";
my $szLine = <$info>;
close $info;

if(!$szLine) {

	print "*********** ERROR ******* Unknown error logging server load...\n";
	exit;
}

if ($szLine =~ /([0-9]*\.?[0-9]*)\s([0-9]*\.?[0-9]*)\s([0-9]*\.?[0-9]*)\s(\d+)\/(\d+)\s(\d+)/ )
{
        #print "Server load: $1 $2 $3 $4/$5 $6\n"; 
        
        my $n1minAvg = $1;
        my $n5minAvg = $2;
        my $n15minAvg = $3;
        
        my $nCurrentlyExecuting = $4; #Less than or equal to number of prosessors
        my $nTotalEntitiesRunning = $6; #Processes, threads ++
        
        my $nLastStartedProcessId = $7;

	my $szComment = "Server load is $n1minAvg $n5minAvg $n15minAvg. Thresholds are $n1minWarningThreshold $n5minWarningThreshold $n15minWarningThreshold";
  
	if ($n1minAvg > $n1minWarningThreshold || 
		$n5minAvg > $n5minWarningThreshold ||
		$n15minAvg > $n15minWarningThreshold) {

		my $szIP;
		my $szPort;
		
		if ($sServerIpAddress eq "") {
			$szIP = "NULL";
		} else {
			$szIP = "'$sServerIpAddress'";
		}
		
		if (!$nServerPort) {		
			$szPort = $nServerPort;
		} else {
			$szPort = "NULL";
		}

		my $szSQL = "insert into assistanceRequest (purpose, ip, port, category, requestQuality, wantSpoofed, comment) values ('internalRequest', $szIP, $szPort, 'bruteForce', 0, b'0', '$szComment')";

		my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
		print "Saving request for assistance due to load exceeding thresholds.\nPosted comment: $szComment.\n";
		#print "\n$szSQL\n\n";
		$sth->execute() or die "execution failed: $sth->errstr()";
		print "If it works, then this will be processed by taralink and send to up to 3 Global DB servers as specified in setup.\nAnd then send to all partner routers as specified on the db servers.\n";
	}
	else {
		print "No request for assistance from sending ISPs on blocking attacks..\n$szComment\n";
	}
}
else {
	print "**************** ERROR interpreting server load...\n";
}



