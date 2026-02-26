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

my $dsn = "DBI:mysql:database=$database;host=$hostname;port=$port";
my $dbh = DBI->connect($dsn, $user, $password);

#Read setup...

my $sth = $dbh->prepare(
        "select max(ip) as maxIp from session where ip like '192.168.0%'")
        or die "prepare statement failed: $dbh->errstr()";
$sth->execute() or die "execution failed: $dbh->errstr()";
my $szMaxIp;

if (my $cSetup = $sth->fetchrow_hashref()) 
{
	$szMaxIp = $cSetup->{'maxIp'};
} else {
	exit;
}

my @words = split('\.', $szMaxIp);

my $nSize = 0+@words;
if ($nSize < 4)
{
	print "Max IP seems not to be an IP address: $szMaxIp ($nSize parts). Aborting. \n";
	exit;
}

my $szLastPart = $words[3];

print "Last assigned ip = $szLastPart\n";

if ($szLastPart < 100 ||$szLastPart > 110)
{
	print "Max ip is out of range (100-110), so aborting... \n";
	exit;
}

print "About to check ipfm setup..\n";

my $szIpfmFile = "/etc/ipfm.conf";
open(CURRENT_CONF, $szIpfmFile) or die("Could not open  file.");

my $szNewIpfmFile = $szSysRoot."temp/ipfm.new.conf";
open my $fh, '>', $szNewIpfmFile or die "Cannot open $szNewIpfmFile: $!";

#First loop through current conf file, and put in new until reach end of file or 
my $bFoundStart = 0;


foreach my $line (<CURRENT_CONF>)  {  

	if ($line =~ /here on\. Last IP (\d+)/){
	#if ($line eq $szStartOfIndividualBlock) {
		$bFoundStart = 1;
		print "Start of individual logging found.. Quitting reading more\n";
		#print $fh "$szStartOfIndividualBlock\n";
		
		my $nLastIpInCurrentConf = $1;
		print "Last current IP found: $nLastIpInCurrentConf\n";
		
		if ($szLastPart eq $nLastIpInCurrentConf) {
			print "Last IP in current file like new... Aborting (no need to change the conf file)\n";
			exit;
		}

		$bFoundStart = 1; #Don't copy more lines from old file... 
		last;
	}

	if (!$bFoundStart) {
		print $fh "$line";
	}
}

close (CURRENT_CONF);

my $szStartOfIndividualBlock = "#Individual logging from here on. Last IP $szLastPart";

print $fh $szStartOfIndividualBlock;

for (my $nLast = 100; $nLast <= $szLastPart+1; $nLast++)
{
	print $fh "\n\nNEWLOG\n";
	print $fh "LOG WITH 192.168.0.$nLast\n";
	#print $fh "LOG 192.168.0.$nLast NOT WITH 192.168.0.0/255.255.255.0\n";
	print $fh "FILENAME \"/var/log/ipfm/individual/$nLast^%Y-%m-%d^%H-%M\"\n";
	print $fh "DUMP EVERY 5 minutes\nCLEAR ALWAYS\nNORESOLVE\nREPLACE\n";
}

close $fh;

if (-e $szIpfmFile) {
	unlink $szIpfmFile;
}
system("mv $szNewIpfmFile $szIpfmFile");

system ('/etc/init.d/ipfm stop >> '.$szSysRoot.'log/install.log');	
system ('/etc/init.d/ipfm start >> '.$szSysRoot.'log/install.log');
