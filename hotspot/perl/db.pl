#!/usr/bin/perl
# - Parses new ipfm usage log files and moves them to archive
# - Checking the database who should have access and updates the IPTABLES rules

use strict;
use warnings;
use autodie;
use DBI;

use lib ('/root/wifi/perl');
use func;	#NOTE! See comment above regarding lib..


#my $szSysRoot = "/home/setup/";
my $szSysRoot = "/root/wifi/";

#my $szServerExternalIp = "192.168.100.15";
my $szServerInternalNic = "INTERNAL_NIC";
#$szServerInternalNic = "enp1s0";
my $szServerExternalNic = "EXTERNAL_NIC"; #"enp2s0";
my $szServerInternalIp;# = "192.168.0.1";
my $szLogFile = $szSysRoot."log/db.log";
my $szDatestring = gmtime();

#my $database = "taransvar";
#my $hostname = "localhost";
#my $port = "3306";
#my $user = "perl";
#my $password = "RevSjoko731";

#my $dsn = "DBI:mysql:database=$database;host=$hostname;port=$port";
#my $dbh = DBI->connect($dsn, $user, $password) or die "Unable to connect!";#: $dbh->errstr()";
my $dbh = getConnection();

open (my $fLog, '>>', $szLogFile) or die "Could not open logfile: $szLogFile $!";

my $szLog = "\nStarting iptable update and usage log checking $szDatestring\n";
say $fLog $szLog;

#Read setup...

#my $sth = $dbh->prepare('select WAN, LAN, internalIP from hotspotSetup') or die "prepare statement failed: $dbh->errstr()";
my $sth = $dbh->prepare('select externalNic as WAN, internalNic as LAN, inet_ntoa(internalIP) as internalIP from setup') or die "prepare statement failed: $dbh->errstr()";
$sth->execute() or die "execution failed: $dbh->errstr()";
if (my $cSetup = $sth->fetchrow_hashref()) 
{
	$szServerInternalNic = $cSetup->{'LAN'};
	$szServerExternalNic = $cSetup->{'WAN'};
    $szServerInternalIp = $cSetup->{'internalIP'};
} else {
	print "Unable to read network setup from database!\n";
    	$szServerInternalIp = "192.168.50.1"; #Never gets here
}

if (!length($szServerInternalNic) || !length($szServerExternalNic)) {
	my $cTaraSetup = getSetup();	
	$szServerInternalNic = $cTaraSetup->{'internalNic'};
	$szServerExternalNic = $cTaraSetup->{'externalNic'};

	if (!length($szServerInternalNic) || !length($szServerExternalNic)) {
		print 'Network setup is not fully registered. Can\'t assemble the fw setup!';
	}
}


my $szLoginPage = $szServerInternalIp.":80";

my @cParts = split /\./, $szServerInternalIp;
my $szBroadcast = $cParts[0].".".$cParts[1].".".$cParts[2].".255";
#my $szBroadcast = "192.168.0.255";


## Process ipfm log files
my $directory = '/var/log/ipfm/subnet/minute';
opendir (DIR, $directory) or die $!;
my @files;

while (my $file = readdir(DIR)) {
	push @files, $file;
}
closedir(DIR);

my $nProcessed = 0;

foreach (@files)
{
	my $file = $_;

	if ($file ne "." && $file ne ".." && $file ne "archived" && $file ne "resolved"){		
        
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
				    #Didn't consider expirytime (didn't find subscription if there's no more quota): my $szSQL = "select sessionid, s.username, mbusage, mbquota from session s join radcheck r on r.username = s.username where ip = '$szIp' and s.active = 1 and logouttime is null and lastrequest > DATE_SUB(NOW(), INTERVAL 1 HOUR) and coalesce(mbusage,0) < mbquota order by logintime desc limit 1";
				    my $szSQL = "select ip, sessionid, s.username, mbusage, mbquota from session s join radcheck r on r.username = s.username where ip = '$szIp' order by active desc, logouttime, sessionid desc limit 1";

				    my $sth = $dbh->prepare($szSQL)
					    or die "prepare statement failed: $dbh->errstr()";
				    #    $sth->execute('Eggers') or die "execution failed: $dbh->errstr()";
				    $sth->execute() or die "execution failed: $dbh->errstr()";
				    my $szUser = "";
				    if (my $ref = $sth->fetchrow_hashref()) {
					    $szUser = $ref->{'username'};
					    print "Username: $szUser\n";
					    #if ($ref->{'mbquota'} > $ref->{'mbusage'}) {
						    my $sth = $dbh->prepare(
							    'update session set lastrequest = now() where sessionid = '.$ref->{'sessionid'})
							    or die "prepare statement failed: $dbh->errstr()";
						    $sth->execute() or die "execution failed: $dbh->errstr()";
					    #}

				    }
				    else {
					    print "User name not found!\n";
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
		rename "$directory/$file","$directory/archived/$file";	
	    $nProcessed = $nProcessed+1;
	}
}

print "\nFiles found: $nProcessed\n";

# Update usage
#my $dbUpdate = DBI->connect($dsn, $user, $password);
my $dbUpdate = getConnection();

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
#$sth = $dbUpdate->prepare("delete from access")
#				or die "prepare statement failed: $dbh->errstr()";
#$sth->execute() or die "execution failed: $dbh->errstr()";

#Fill the access database with those found... 
#my $szSQL = "insert into access (ip, hasaccess) select distinct ip, 1 from session s join radcheck r on s.username = r.username where active = 1 and logouttime is null and lastrequest > DATE_SUB(NOW(), INTERVAL 1 HOUR) and coalesce(mbusage,0) < mbquota";


#my $szSQL = "insert ignore into access (ip, hasaccess) select distinct ip, 1 from session s join radcheck r on s.username = r.username 
#			where active = 1 and logouttime is null and lastrequest > DATE_SUB(NOW(), INTERVAL 1 HOUR) and if(subscriptionType = 'quota',coalesce(mbusage,0) < #mbquota,
#						if(subscriptionType = 'expiry',expirytime > now(),coalesce(mbusage,0) < mbquota AND expirytime > now())
#						) ";


my $szSQL = "insert into access (ip, hasaccess) select distinct ip, 1 from session s join radcheck r on s.username = r.username 
			where active = 1 and logouttime is null and lastrequest > DATE_SUB(NOW(), INTERVAL 1 HOUR) and if(subscriptionType = 'quota',coalesce(mbusage,0) < mbquota,
						if(subscriptionType = 'expiry',expirytime > now(),coalesce(mbusage,0) < mbquota AND expirytime > now())
						) 
		on duplicate key update hasAccess = 1, updated = Now()";
#print "$szSQL\n";	Not printing anyway.. probably gets redirected to log file...



#For debugging:
#select distinct ip, 1 from session s join radcheck r on s.username = r.username  where active = 1 and logouttime is null and lastrequest > DATE_SUB(NOW(), INTERVAL 1 HOUR) and  	if(subscriptionType = 'quota',coalesce(mbusage,0) < mbquota,  			if(subscriptionType = 'expiry',expirytime > now(), 					coalesce(mbusage,0) < mbquota AND expirytime > now()));
#select distinct ip, 1, subscriptionType, mbusage, mbquota, expirytime  from session s join radcheck r on s.username = r.username where active = 1 and logouttime is null and lastrequest > DATE_SUB(NOW(), INTERVAL 1 HOUR);
						
$sth = $dbUpdate->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
$sth->execute() or die "$szSQL: execution failed: $dbh->errstr()";

#Now delete the rows that are not updated...
$szSQL = "delete from access where updated < date_sub(now(), INTERVAL 2 SECOND)";
$sth = $dbUpdate->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
$sth->execute() or die "$szSQL: execution failed: $dbh->errstr()";

my @NewCommentsFile;                  # make an array to store new file lines

#1) Reset (optional but recommended while testing)
push(@NewCommentsFile, "/sbin/iptables -F\n");
push(@NewCommentsFile, "/sbin/iptables -t nat -F\n");
push(@NewCommentsFile, "/sbin/iptables -P FORWARD DROP\n");
push(@NewCommentsFile, "/sbin/iptables -P INPUT DROP\n");
push(@NewCommentsFile, "/sbin/iptables -P OUTPUT DROP\n");	#OT 250226

#2) Always allow loopback + established traffic (this prevents random breakage)
push(@NewCommentsFile, "/sbin/iptables -A INPUT  -i lo -j ACCEPT\n");
push(@NewCommentsFile, "/sbin/iptables -A OUTPUT -o lo -j ACCEPT\n");
push(@NewCommentsFile, "/sbin/iptables -A INPUT  -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT\n");
push(@NewCommentsFile, "/sbin/iptables -A OUTPUT -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT\n");

#3) Allow DHCP on Wi-Fi (ISC DHCP server)
#Client → server: udp 68 → 67, server → client: udp 67 → 68
push(@NewCommentsFile, "/sbin/iptables -A INPUT  -i $szServerInternalNic -p udp --sport 68 --dport 67 -j ACCEPT\n");
push(@NewCommentsFile, "/sbin/iptables -A OUTPUT -o $szServerInternalNic -p udp --sport 67 --dport 68 -j ACCEPT\n");

#4) Allow clients to reach the portal on TCP/80 (local INPUT)
push(@NewCommentsFile, "/sbin/iptables -A INPUT  -i $szServerInternalNic -p tcp --dport 80 -j ACCEPT\n");
push(@NewCommentsFile, "/sbin/iptables -A OUTPUT -o $szServerInternalNic -p tcp --sport 80 -j ACCEPT\n");

#5) Force HTTP to the portal (optional, but matches your “always end up on login” for HTTP)
push(@NewCommentsFile, "/sbin/iptables -t nat -A PREROUTING -i $szServerInternalNic -p tcp --dport 80 -j REDIRECT --to-ports 80\n");

#6) NAT for later (internet after login)
push(@NewCommentsFile, "/sbin/iptables -t nat -A POSTROUTING -o $szServerExternalNic -j MASQUERADE\n");

#7) After login, whitelist the client (add rules dynamically)
#When a client logs in and you want to allow them out:







$sth = $dbh->prepare(
        'select nasname from nas')
        or die "prepare statement failed: $dbh->errstr()";
$sth->execute() or die "execution failed: $dbh->errstr()";


while (my $ref = $sth->fetchrow_hashref()) 
{
	my $szRadiusServerIP = $ref->{'nasname'};
    print "Radius server found: $szRadiusServerIP\n";

    #Allow spesific Radius server 

    push(@NewCommentsFile, "/sbin/iptables -A INPUT  -s $szRadiusServerIP -p udp --dport 1812 -m state --state NEW,ESTABLISHED,RELATED -j ACCEPT\n");
    push(@NewCommentsFile, "/sbin/iptables -A OUTPUT -d $szRadiusServerIP -p udp --sport 1812 -m state --state ESTABLISHED,RELATED -j ACCEPT\n");
}


$sth = $dbh->prepare('SELECT ip, hasaccess from access')
        or die "prepare statement failed: $dbh->errstr()";
	
# select ip, 1 from session s join userusage u on u.user = s.username
#	alter table radcheck set mbusage = ( select sum(mb) from userusage where 
# select user, sum(mb) as mbusage from userusage group by user;
	
#    $sth->execute('Eggers') or die "execution failed: $dbh->errstr()";
 $sth->execute() or die "execution failed: $dbh->errstr()";

print $sth->rows . " rows found:\n";
my $szAccessText = "dummy";

while (my $ref = $sth->fetchrow_hashref()) 
{
	my $szIp = $ref->{'ip'};
	my $nAccess = $ref->{'hasaccess'};
	if ($nAccess) {
		$szAccessText = "Has access";
		#my $line = "/sbin/iptables -t nat -A PREROUTING -i $szServerInternalNic -s $szIp -j ACCEPT\n"; 
		#push(@NewCommentsFile, $line);    

		push(@NewCommentsFile, "/sbin/iptables -I FORWARD 1 -i $szServerInternalNic -s $szIp -o $szServerExternalNic -m conntrack --ctstate NEW,ESTABLISHED,RELATED -j ACCEPT\n");
		push(@NewCommentsFile, "/sbin/iptables -I FORWARD 2 -i $szServerExternalNic -d $szIp -o $szServerInternalNic -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT\n");

		#push(@NewCommentsFile, $line);    
		#$line = "/sbin/iptables -A FORWARD -i $szServerInternalNic -s $szIp -j ACCEPT\n"; 
		#push(@NewCommentsFile, $line);    
		#$line = "/sbin/iptables -A FORWARD -o $szServerInternalNic -d $szIp -j ACCEPT\n"; 
		#push(@NewCommentsFile, $line);    
	}
	else {
		$szAccessText = "NO ACCESS";
	}

	print "$szIp, $szAccessText\n";
}
$sth->finish;
 
#Add iptables rules from web interface
#my $szIpTablesFromWebFile = "/var/www/html/temp/iptablesTemplates.txt";
my $szIpTablesFromWebFile = $szSysRoot."temp/wwwRulesByPerl.txt";	#/root/wifi/temp/wwwRulesByPerl.txt

 if (not -e $szIpTablesFromWebFile) {
	 #print $szIpTablesFromWebFile." not found!\n";
	$szIpTablesFromWebFile = "/var/www/html/tara/temp/iptablesTemplates.txt";
} 

my $bTemplatesFound = 1;

 if (-e $szIpTablesFromWebFile) {
	print "Adding ".$szIpTablesFromWebFile."!\n";
	open my $handle, '<', $szIpTablesFromWebFile;
	chomp(my @lines = <$handle>);
	close $handle;
	my $nLines = 0;

	foreach (@lines) {
		push(@NewCommentsFile, $_."\n");
		$nLines++;
 	}
	
	#if (0+@lines >= 1)
	#my $nLines = scalar @lines;
	print "$nLines templates found.\n";
	if ($nLines >= 1)	#Changed 180504
	{
		if ($lines[0] eq "#Dummy") {
			$bTemplatesFound = 0;
		}
	}
	
} else {
	print $szIpTablesFromWebFile." not found!\n";
	$bTemplatesFound = 0;
}

if (!$bTemplatesFound)
{
	#Allow HTTP by default - from internal computers
	push(@NewCommentsFile, "/sbin/iptables -I INPUT -p tcp --dport 80 -j ACCEPT\n");
	push(@NewCommentsFile, "/sbin/iptables -I OUTPUT -p tcp --sport 80 -j ACCEPT\n");
	push(@NewCommentsFile, "/sbin/iptables -I INPUT -p tcp --dport 443 -j ACCEPT\n");
	push(@NewCommentsFile, "/sbin/iptables -I OUTPUT -p tcp --sport 443 -j ACCEPT\n");
}


#Write iptables file.
my $szShFileName = $szSysRoot."temp/grantaccess.sh";	#/root/wifi/temp/grantaccess.sh
open my $fh, '>', $szShFileName or die "Cannot open output.txt: $!";
print $fh getNiceTimestamp()."\n\n";

# Loop over the array
foreach (@NewCommentsFile) {
	print $fh "$_";    # Print each entry in our new array to the file
}
close $fh;

system("sh $szShFileName");
close $fLog;

print getNiceTimestamp().": iptables rules written to $szShFileName\n";


