#!/usr/bin/perl
# - Not finalized... current log files don't log what IPs there are traffic with...

use strict;
use warnings;
use autodie;
use DBI;

#my $szSysRoot = "/home/setup/";
my $szSysRoot = "/root/wifi/";


my $database = "taransvar";
my $hostname = "localhost";
my $port = "3306";
my $user = "perl";
my $password = "RevSjoko731";

#check the load
open my $hLoad, '<', '/proc/loadavg';
if ($line (<$hLoad>) ) {   
    print "Load: $line\n";    
} else {
	print "ERROR! Load file not found!\n";
}
close($hLoad);

my $szLogFile = $szSysRoot."log/ipStatProcess";
open (my $fLog, '>>', $szLogFile) or die "Could not open logfile: $szLogFile $!";

my $szDatestring = gmtime();
my $szLog = "\nStarting processing IP traffic statistics $szDatestring\n";
say $fLog $szLog;
#print $szLog;

my $dsn = "DBI:mysql:database=$database;host=$hostname;port=$port";
my $dbh = DBI->connect($dsn, $user, $password);

#Read setup...

my $sth = $dbh->prepare(
        'select CAST(ananlyzeAll as UNSIGNED) as ananlyzeAll from setup')
        or die "prepare statement failed: $dbh->errstr()";
$sth->execute() or die "execution failed: $dbh->errstr()";
if (my $cSetup = $sth->fetchrow_hashref()) 
{
	if (!$cSetup->{'ananlyzeAll'})
	{
		say $fLog "Not set to anyalyze.. Aborting\n";
		close($hLoad);
		exit;
	}
}


## Get files from directory
my $directory = '/var/log/ipfm/subnet/minute/archive';

opendir (DIR, $directory) or die $!;
print "Files found: \n";
my @files;

while (my $file = readdir(DIR)) {
	push @files, $file;
}

foreach (@files)
{
	my $file = $_;

	print "\nFile contents of: $file\n";

	my $path_to_file = "$directory/$file";
	open my $handle, '<', $path_to_file;
	chomp(my @lines = <$handle>);
	close $handle;

	foreach (@lines) {
		my $szLine = $_;
		print "From file: $szLine\n";    # Print each entry in our new array to the file
		if ($szLine =~ /(\S+)(\s+)(\d+)(\s+)(\d+)(\s+)(\d+)$/)
		{
			my $szIp = $1;
			if ($szIp ne $szBroadcast && $szIp ne $szServerInternalIp) {
				my $nBytes = $7;
				my $szYyyymmdd = "error"; 

				if ($file =~ /(\d+)-(\d+)-(\d+)-(\d+)-(\d+)$/){
					$szYyyymmdd = "$1-$2-$3 $4:$5";
					print "===== DATE FOUND: $szYyyymmdd\n";
				}
				
				print "********* Ip: $szIp, bytes $nBytes\n";
				
				#Find user based on IP
				my $szSQL = "select sessionid, s.username, mbusage, mbquota from session s join radcheck r on r.username = s.username where ip = '$szIp' and s.active = 1 and logouttime is null and lastrequest > DATE_SUB(NOW(), INTERVAL 1 HOUR) and coalesce(mbusage,0) < mbquota order by logintime desc limit 1";

				my $sth = $dbh->prepare($szSQL)
					or die "prepare statement failed: $dbh->errstr()";
				#    $sth->execute('Eggers') or die "execution failed: $dbh->errstr()";
				$sth->execute() or die "execution failed: $dbh->errstr()";
				my $szUser = "";
				if (my $ref = $sth->fetchrow_hashref()) {
					$szUser = $ref->{'username'};
					print "Username: $szUser - SQL: $szSQL\n";
					if ($ref->{'mbquota'} > $ref->{'mbusage'}) {
						my $sth = $dbh->prepare(
							'update session set lastrequest = now() where sessionid = '.$ref->{'sessionid'})
							or die "prepare statement failed: $dbh->errstr()";
						$sth->execute() or die "execution failed: $dbh->errstr()";
					}

				}
				else {
					print "User name not found!  - SQL: $szSQL\n";
					$szUser = "???";
				}
				
				my $fMb =  $nBytes / (1024*1024);
				$szSQL = "insert into userusage (user,ip,yyyymmddhh,mb) values ('$szUser', '$szIp', '$szYyyymmdd', $fMb) on duplicate key update mb = $fMb";
				
				$sth = $dbh->prepare($szSQL)
					or die "prepare statement failed: $dbh->errstr()";
				$sth->execute() or die "execution failed: $dbh->errstr()";
				
				
			} #else {
			#	print "***** Skipping reserved IP: $szIp\n";
			#}
		}

	}

	#Rename if legal file name (not . and ..)
	if ($file ne "." && $file ne ".." && $file ne "archived" && $file ne "resolved"){		
		rename "$directory/$file","$directory/archived/$file";	
	}

}
closedir(DIR);

# Update usage
my $dbUpdate = DBI->connect($dsn, $user, $password);

$sth = $dbh->prepare(
        'select user, sum(mb) as mbusage from userusage group by user')
        or die "prepare statement failed: $dbh->errstr()";
$sth->execute() or die "execution failed: $dbh->errstr()";
while (my $ref = $sth->fetchrow_hashref()) 
{
	my $szUser = $ref->{'user'};
	my $nUsage = $ref->{'mbusage'};
	
	my $sth = $dbUpdate->prepare(
			"update radcheck set mbusage = $nUsage where username = '$szUser'")
				or die "prepare statement failed: $dbh->errstr()";
	$sth->execute() or die "execution failed: $dbh->errstr()";
}

#Empty the old access database
$sth = $dbUpdate->prepare("delete from access")
				or die "prepare statement failed: $dbh->errstr()";
$sth->execute() or die "execution failed: $dbh->errstr()";

#Fill the access database with those found... 
$sth = $dbUpdate->prepare("insert into access (ip, hasaccess) select distinct ip, 1 from session s join radcheck r on s.username = r.username where active = 1 and logouttime is null and lastrequest > DATE_SUB(NOW(), INTERVAL 1 HOUR) and coalesce(mbusage,0) < mbquota")
				or die "prepare statement failed: $dbh->errstr()";
$sth->execute() or die "execution failed: $dbh->errstr()";


$sth = $dbh->prepare('SELECT ip, hasaccess from access')
        or die "prepare statement failed: $dbh->errstr()";
	
# select ip, 1 from session s join userusage u on u.user = s.username
#	alter table radcheck set mbusage = ( select sum(mb) from userusage where 
# select user, sum(mb) as mbusage from userusage group by user;
	
#    $sth->execute('Eggers') or die "execution failed: $dbh->errstr()";
 $sth->execute() or die "execution failed: $dbh->errstr()";

my @NewCommentsFile;                  # make an array to store new file lines
push(@NewCommentsFile, "/sbin/iptables -t nat -F PREROUTING\n");
push(@NewCommentsFile, "/sbin/iptables -F\n");
push(@NewCommentsFile, "/sbin/iptables -P FORWARD DROP\n");
push(@NewCommentsFile, "/sbin/iptables -P INPUT DROP\n");
push(@NewCommentsFile, "/sbin/iptables -P OUTPUT DROP\n");

push(@NewCommentsFile, "/sbin/iptables -t nat -A POSTROUTING -o $szServerExternalNic -j MASQUERADE\n");

#Allow all on localhost
push(@NewCommentsFile, "/sbin/iptables -A INPUT -i lo -j ACCEPT\n");
push(@NewCommentsFile, "/sbin/iptables -A OUTPUT -o lo -j ACCEPT\n");
#push(@NewCommentsFile, "/sbin/iptables -A -s lo -j ACCEPT\n");
#push(@NewCommentsFile, "/sbin/iptables -A -o lo -j ACCEPT\n");

push(@NewCommentsFile, "/sbin/iptables -A FORWARD -p udp --dport 53 -m state --state NEW,ESTABLISHED -j ACCEPT\n");

#Allow dhcp
push(@NewCommentsFile, "/sbin/iptables -A INPUT -i $szServerInternalNic -p udp --dport 67:68 --sport 67:68 -j ACCEPT\n");
push(@NewCommentsFile, "/sbin/iptables -A OUTPUT -o $szServerInternalNic -p udp --sport 67:68 --dport 67:68 -j ACCEPT\n");

#Allow NTP
push(@NewCommentsFile, "/sbin/iptables -A OUTPUT -p udp --dport 123 -j ACCEPT\n");
push(@NewCommentsFile, "/sbin/iptables -A INPUT -p udp --sport 123 -j ACCEPT\n");

#Allow ping
push(@NewCommentsFile, "/sbin/iptables -A INPUT -p icmp --icmp-type 8 -s 0/0 -d $szServerInternalIp -m state --state NEW,ESTABLISHED,RELATED -j ACCEPT\n");
push(@NewCommentsFile, "/sbin/iptables -A OUTPUT -p icmp --icmp-type 0 -s $szServerInternalIp -d 0/0 -m state --state ESTABLISHED,RELATED -j ACCEPT\n");

print $sth->rows . " rows found:\n";
my $szAccessText = "dummy";
while (my $ref = $sth->fetchrow_hashref()) 
{
	my $szIp = $ref->{'ip'};
	my $nAccess = $ref->{'hasaccess'};
	if ($nAccess) {
		$szAccessText = "Has access";
		my $line = "/sbin/iptables -t nat -A PREROUTING -i $szServerInternalNic -s $szIp -j ACCEPT\n"; 
		push(@NewCommentsFile, $line);    
		$line = "/sbin/iptables -A FORWARD -i $szServerInternalNic -s $szIp -j ACCEPT\n"; 
		push(@NewCommentsFile, $line);    
		$line = "/sbin/iptables -A FORWARD -o $szServerInternalNic -d $szIp -j ACCEPT\n"; 
		push(@NewCommentsFile, $line);    
	}
	else {
		$szAccessText = "NO ACCESS";
	}

	print "$szIp, $szAccessText\n";
}
    $sth->finish;
 push(@NewCommentsFile, "/sbin/iptables -t nat -A PREROUTING -i $szServerInternalNic -p tcp -j DNAT --to $szLoginPage\n");
 push(@NewCommentsFile, "/sbin/iptables -A FORWARD -o $szServerInternalNic -j ACCEPT\n");
 
 #Add iptables rules from web interface
 my $szIpTablesFromWebFile = "/var/www/html/temp/iptablesTemplates.txt";

 if (not -e $szIpTablesFromWebFile) {
	 print $szIpTablesFromWebFile." not found!\n";
	$szIpTablesFromWebFile = "/var/www/html/tara/temp/iptablesTemplates.txt";
} else {
	#Allow HTTP - from internal computers
	push(@NewCommentsFile, "/sbin/iptables -I INPUT -p tcp --dport 80 -j ACCEPT\n");
	push(@NewCommentsFile, "/sbin/iptables -I OUTPUT -p tcp --sport 80 -j ACCEPT\n");
	push(@NewCommentsFile, "/sbin/iptables -I INPUT -p tcp --dport 443 -j ACCEPT\n");
	push(@NewCommentsFile, "/sbin/iptables -I OUTPUT -p tcp --sport 443 -j ACCEPT\n");
}

 if (-e $szIpTablesFromWebFile) {
	print "Adding ".$szIpTablesFromWebFile."!\n";
	open my $handle, '<', $szIpTablesFromWebFile;
	chomp(my @lines = <$handle>);
	close $handle;

	foreach (@lines) {
		push(@NewCommentsFile, $_."\n");
 	}
} else {
	print $szIpTablesFromWebFile." not found!\n";
}

#Write iptables file.
my $szShFileName = $szSysRoot."temp/grantaccess.sh";
open my $fh, '>', $szShFileName or die "Cannot open output.txt: $!";

# Loop over the array
foreach (@NewCommentsFile) {
	print $fh "$_";    # Print each entry in our new array to the file
}
close $fh;

system("sh $szShFileName");
close $fLog;

