#!/usr/bin/perl

use strict;
use warnings;
use autodie;
use DBI;
use File::Copy;

my $nMaxFound = 500; 	#Quit after this number of new IP addresses found (finishe the file, though)

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
		my $szFileDate = "";
		if ($szFile =~ /(\d+)\^(\d+)\-(\d+)\-(\d+)\^(\d+)\-(\d+)/) {
			$nInternalIp = $1;
			$szFileDate = "$2-$3-$4 $5:$6";
		} else {
			print "Error! Unable to retrieve internal ip from start of file name! Aborting!\n";
			exit;
		}

		print "\nHandling file: $szFile ($nInternalIp)\n";
		my $nFileName = $directory."/".$szFile;
		open(CURRENT_CONF, $nFileName) or die("Could not open file: $nFileName");

		foreach my $szLine (<CURRENT_CONF>)  {  

			if ($szLine =~ /(\S+)(\s+)(\d+)(\s+)(\d+)(\s+)(\d+)$/) {
			#if ($line =~ /(\d+).(\d+).(\d+).(\d+)(\s*)(\d*)(\s*)(\d*)(\s*)(\d*)/){
				#print "Found: $szLine\n";
				print "IP: $1, in: $3, out: $5, total: $7\n";
				$nFound++;

				my $szIP = $1;
				my $nMbIn = $3;
				my $nMbOut = $5;
				my $sth = $dbh->prepare(
					"select ip_id from log_resolv where ip = '$szIP'")
					or die "prepare statement failed: $dbh->errstr()";
				$sth->execute() or die "execution failed: $dbh->errstr()";
				my $nIpId = 0;

				if (my $cIpRec = $sth->fetchrow_hashref()) 
				{
					$nIpId = $cIpRec->{'ip_id'};
					print "IP found: $szIP ($nIpId)\n";
				} else {
					my $sth = $dbh->prepare(
						"insert into log_resolv (ip) values ('$szIP')")
						or die "prepare statement failed: $dbh->errstr()";
					$sth->execute() or die "execution failed: $dbh->errstr()";

					$sth = $dbh->prepare(
						"select LAST_INSERT_ID();")
						or die "prepare statement failed: $dbh->errstr()";
					$sth->execute() or die "execution failed: $dbh->errstr()";
					if (my $cIpRec = $sth->fetchrow_hashref()) 
					{
						$nIpId = $cIpRec->{'LAST_INSERT_ID()'};
						print "New IP registered: $szIP ($nIpId)\n";
					} else {
						print "Error! Couldn't register new id!.. aborting\n";
						exit;
					}
				}
				
				#ip_id retrieved... go on and register the usage/connection
				$sth = $dbh->prepare(
					"insert into log_contact (internal_ip, external_ip, reg_time, username, mb_in, mb_out) 
							values ($nInternalIp, $nIpId, '$szFileDate', '???', $nMbIn, $nMbOut)")
					or die "prepare statement failed: $dbh->errstr()";
				$sth->execute() or die "execution failed: $dbh->errstr()";

			}
		
		
		}
		
		my $szMoveTo = $directory."/archived/".$szFile;

		print "Moving file $nFileName to $szMoveTo...\n";
		move($nFileName, $szMoveTo) or die "The move operation failed: $!";
	}


	if ($nFound > $nMaxFound) {
		last;		#Only handle one...
	}
}

closedir(DIR);

