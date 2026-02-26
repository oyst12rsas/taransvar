#!/usr/bin/perl
# - Parses new ipfm usage log files and moves them to archive
# - Checking the database who should have access and updates the IPTABLES rules

use strict;
use warnings;
use autodie;
use DBI;

my $szSysRoot = "/root/wifi/";

my $database = "taransvar";
my $hostname = "localhost";
my $port = "3306";
my $user = "perl";
my $password = "RevSjoko731";

my $directory = '/var/log/ipfm/individual';

my $dsn = "DBI:mysql:database=$database;host=$hostname;port=$port";
my $dbh = DBI->connect($dsn, $user, $password);


opendir (DIR, $directory) or die $!;
print "Files found: \n";
my $nFound = 0;

while (my $szFile = readdir(DIR)) {

	if ($szFile ne "." && $szFile ne "..") {

		#Get internal ip (last byte) from file name.
		
		my $nInternalIp = 0;
		if ($szFile =~ /(\d+)\^(\d+)\-(\d+)\-(\d+)\^(\d+)\-(\d+)/) {
			$nInternalIp = $1;
			print "Fant $2-$3-$4 $5:$6\n";
		} else {
			print "Error: $szFile: Unable to retrieve internal ip from start of file name! Aborting!\n";
			exit;
		}
	}
}
