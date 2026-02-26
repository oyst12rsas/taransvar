#!/usr/bin/perl
use lib ('/home/oystein/programming/misc');
		
use strict;
use warnings;
use autodie;
use DBI;
use func;	#NOTE! See comment above regarding lib..
use lib_dhcpdump;

my $nTimeStarted = time();
my $nSecondsToSleepBetweenIterations = 6;
my $nNumberOfWhoIsLookupsPerIteration = 5;	#Increase if too few have owner name in traffic list in http://localhost/index.php?f=traffic
my $szSysRoot = "/root/setup";
my $szLogRoot = $szSysRoot."/log/";
my $szLogFile = $szSysRoot."/log/log.txt"; 

 my ($sec,$min,$hour,$mday,$mon,$year,$wday,$yday,$isdst)=localtime(time);
    my $nice_timestamp = sprintf ( "%04d%02d%02d-%02d:%02d:%02d",
                                   $year+1900,$mon+1,$mday,$hour,$min,$sec);
my $szGrabFile = $szSysRoot."/log/crontasks.txt";

#Change here if testing on specific file (NB!In current directory!) (otherwise generates new file)
#$szGrabFile = "conntrack_check.txt";

my $dbh = getConnection();


startTaraSystemsOk();

#process_dhcpdump_testing($dbh);	#NOTE! Maybe it's risky to run it this often?

#select unitId, macAddress, ipAddress, description, hostname, lastSeen from unit order by unitId desc limit 5;






