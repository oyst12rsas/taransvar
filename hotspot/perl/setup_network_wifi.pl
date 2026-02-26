#!/usr/bin/perl
use strict;
use warnings;
use autodie;
use DBI;
use Data::Dumper qw(Dumper);
use File::Copy;
#TO DO!
# /etc/default/dhcpd.conf
# INTERFACES="eth1"	#NOTE replace with LAN nic

#my $szSysRoot = "/home/setup/";
my $szSysRoot = "/root/wifi/";


print "\n\n *********** setup_network.pl **********************\n\n";

my $szTmpIpLink = $szSysRoot."log/iplinkdmp.txt";
my $szPingTestTempFile = $szSysRoot."log/pingtest.txt";

my $database = "taransvar";
my $hostname = "localhost";
my $port = "3306";
my $user = "scriptUsrAces3f3";
my $password = "rErte8Oi98e-2_#";

my $dsn = "DBI:mysql:database=$database;host=$hostname;port=$port";
my $dbh = DBI->connect($dsn, $user, $password);# or die "Unable to connect: $dbh->errstr()";

if (!$dbh)
{
	print "Error connecting (maybe database is not yet installed!\n";
	exit;
}

system("/sbin/iptables -P INPUT ACCEPT");
system("/sbin/iptables -P OUTPUT ACCEPT");

my $szTmpIpRoute = $szSysRoot."log/iproute.txt";
system("ip route > $szTmpIpRoute");

#Generates file: 
#default via 192.168.100.1 dev eno1  proto static  metric 100 
#169.254.0.0/16 dev eno1  scope link  metric 1000 
#192.168.100.0/24 dev eno1  proto kernel  scope link  src 192.168.100.17  metric 100 

open my $handle, '<', $szTmpIpRoute;
chomp(my @lines = <$handle>);
close $handle;
my $szActiveLink = "";

foreach (@lines) {
	my $szLine = $_;
	#print "Line read:->$szLine<-\n";
	
	if ($szLine =~ /^(.+)\sdev\s(\w+)(\.*)/)
	{
		print "Found: $2\n";
		#push(@devices, $1)

		my $szLogText = "Device found: $2";
			
		#Check if marked as linkdown
		if ($szLine =~ /linkdown/)
		{
			$szLogText .= ", but skipped: Line is down\n";
		}
		else
		{
			$szLogText .= ", and does not seem to be down.. So saved.\n";
			$szActiveLink = $2;
		}

		open(my $fIproute, '>>', $szTmpIpRoute) or die "Could not open file '$szTmpIpRoute' $!";
		say $fIproute "\n\n$szLogText\n";
		close $fIproute;

	}
}

if ($szActiveLink eq "") {
	print "WARNING! Active gateway NOT found through \"ip route\"\n";
}
else
{
	print "Active gateway found through \"ip route\": $szActiveLink\n";
}

my $szInternalIP;
my $sth = $dbh->prepare(
        'select internalIP from setup')
        or die "prepare statement failed: $dbh->errstr()";
$sth->execute() or die "execution failed: $dbh->errstr()";
if (my $cSetup = $sth->fetchrow_hashref() && $szInternalIP) 
{
	$szInternalIP = $cSetup->{'internalIP'};
} else {
    $szInternalIP = "192.168.0.1";
    print "****************** NOTE should save internal ip..";
}

my @cParts = split /\./, $szInternalIP;
my $szNetwork = $cParts[0].".".$cParts[1].".".$cParts[2].".0";
my $szBroadcast = $cParts[0].".".$cParts[1].".".$cParts[2].".255";
my $szDHCPminIP = $cParts[0].".".$cParts[1].".".$cParts[2].".100";
my $szDHCPmaxIP = $cParts[0].".".$cParts[1].".".$cParts[2].".253";

print "Network: $szNetwork\nBroadcast: $szBroadcast\nIP address: $szInternalIP\n";

#**************** Find network setup ************************
system("ip link > $szTmpIpLink");

open $handle, '<', $szTmpIpLink;
chomp(@lines = <$handle>);
close $handle;

#$> ip link
#1: lo: <LOOPBACK,UP,LOWER_UP> ... 
#   link/loopback 00:00:00:00:00:00 brd 00:00:00:00:00:00
#2: eth0: <BROADCAST,MULTICAST,UP,LOWER_UP> ...
#    link/ether 11:22:33:44:55:66 brd ff:ff:ff:ff:ff:ff
#3: enx0050b617c34f: <BROADCAST,MULTICAST,UP,LOWER_UP> ...
#    link/ether 11:22:33:44:55:66 brd ff:ff:ff:ff:ff:ff

my $szSetupHome = $szSysRoot;
my $szFullPath;
$szFullPath = $szSetupHome."temp"; mkdir $szFullPath unless -d $szFullPath;
$szFullPath = $szSetupHome."log"; mkdir $szFullPath unless -d $szFullPath;

my @devices = ();

foreach (@lines) {
	my $szLine = $_;
	#print "Line read:->$szLine<-\n";
	
	if ($szLine =~ /^\d:\s(\w+).+/)
	{
		print "Found: $1\n";
		push(@devices, $1)
	}
}

print "Finished reading!\n";
	
#Collect all network devices and check which setup gives access..
my $szExternal = $szActiveLink;
my $szInternal = "";

foreach (@devices) {
	my $szDevice = $_;

	if ($szDevice ne "lo") {
		print "Checking $szDevice\n";
		if ($szExternal eq "") {
			$szExternal = $szDevice;
		} else {
			if ($szInternal eq "" && $szDevice ne $szExternal) {
				if (substr($szDevice, 0, 1) ne "w") {
					$szInternal = $szDevice;
				} else {
					print "NOTE! Wireless device ($szDevice) found and WAN is already set! This could be because your Linux box is connected through bluetooth or another wireless device (which is ok). It could also be because because your Linux box is connected to Internet through cable. This NOT ok, because the cable connection should be reseverd for your wifi router that your users will connect to. Please fix this and run this script again.\n";
				}
			} else {
				print "Skipping $szDevice (probably default route that is already identified as WAN\n";
			}
		}
	}
}

print "Testing:\nWAN: $szExternal\n";
print "LAN: $szInternal\n";

my @cTestArr;
my $nLoops;
if ($szActiveLink eq "")
{
	$cTestArr[0][0] = $szExternal;
	$cTestArr[0][1] = $szInternal;
	$cTestArr[1][0] = $szInternal;
	$cTestArr[1][1] = $szExternal;
	$nLoops = 2;

	print "Active route not found, so testing both $szExternal and $szInternal as WAN (and the other as lan)\n"
}
else
{
	$cTestArr[0][0] = $szActiveLink;	#Set this but don't use it anymore......
	if ($szActiveLink ne $szInternal) {
		$cTestArr[0][1] = $szInternal;
	} else {
		$cTestArr[0][1] = $szExternal;
	}

	print "Active route found, so testing ".$szActiveLink." as WAN and ".$cTestArr[0][1]." as LAN\n";
	$nLoops = 1;
}

my $bPingSuccess = 0;

for (my $i=0; $i <= $nLoops; $i++) {

 	$szExternal = $cTestArr[$i][0];
	$szInternal = $cTestArr[$i][1];

my $szWrite = "# loopback
auto lo
iface lo inet loopback

# WAN interface (Removed from here not to disrupt the installed connection)
#auto $szExternal
#iface $szExternal inet dhcp

# LAN interface
auto $szInternal
iface $szInternal inet static
  address $szInternalIP
  network $szNetwork
  netmask 255.255.255.0
  broadcast $szBroadcast

";


	print $szWrite;
	my $filename = '/etc/network/interfaces';
	open(my $fh, '>', $filename) or die "Could not open file '$filename' $!";
	print $fh $szWrite;
	close $fh;
	print "\nNew contents written to $filename\n\n";
	
	system("/bin/systemctl restart networking >> ".$szSysRoot."log/install.log");
	
	#Test if has internet access
	
	my $szWorkingDir = $szSysRoot."log";	#Required for wget to save index.html
	chdir $szWorkingDir; 

	if ( -e $szPingTestTempFile ) {
		unlink($szPingTestTempFile) or die "$szPingTestTempFile: $!"
	}
	system("/bin/ping -c 3 vg.no > $szPingTestTempFile");
	my $size = -s $szPingTestTempFile;	#Check the size of the saved file..
	print "\nIndex.html file size: $size\n\n";

	#Check if able to ping
	open my $pPingTestHandle, '<', $szPingTestTempFile;
	chomp(my @pingLines = <$pPingTestHandle>);
	close $pPingTestHandle;

	foreach (@pingLines) {
		my $szLine = $_;
		#print "Line read:->$szLine<-\n";
		
		if ($szLine =~ /bytes from/)
		{
			print "Able to ping: $szLine\n";
			push(@devices, $szLine);
			$bPingSuccess = 1;
			$i = $nLoops+1;	#To ensure that quits the loop.
		}
		else {
			print "No success line: $szLine\n";
		}
	}
	
	if ($bPingSuccess) {
		$i = 100;	#Makes the for loop break while the network is working. 
		print "\nNetworking working with:\nWAN: $szExternal\n";
		print "LAN: $szInternal\nWill be written to DB\n";
		
		my $szSQL = "update setup set WAN = '$szExternal', LAN = '$szInternal'";
		my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
		$sth->execute() or die "execution failed: $dbh->errstr()";
	} 
}

if ($bPingSuccess)
{
	#Make sure DHCP server works on internal NIC
	my $szCmd = '/bin/sed -i "s/INTERFACES=\"\w*\"/INTERFACES=\"'.$szInternal.'\"/" /etc/dhcp/dhcpd.conf';	
	system($szCmd);

	$szCmd = '/bin/sed -i "s/\S*\snetmask 255.255.255.0/'.$szNetwork.' netmask 255.255.255.0/" /etc/dhcp/dhcpd.conf';	
	system($szCmd);

	$szCmd = '/bin/sed -i "s/range \S*\s\S*;/range '.$szDHCPminIP.' '.$szDHCPmaxIP.';/" /etc/dhcp/dhcpd.conf';	
	system($szCmd);

	$szCmd = '/bin/sed -i "s/option broadcast-address \S*;/option broadcast-address '.$szBroadcast.';/" /etc/dhcp/dhcpd.conf';	
	system($szCmd);

	$szCmd = '/bin/sed -i "s/option routers \S*;/option routers '.$szInternalIP.';/" /etc/dhcp/dhcpd.conf';	
	system($szCmd);

	system("service isc-dhcp-server restart");


	$szCmd = '/bin/sed -i "s/DEVICE \w*/DEVICE '.$szInternal.'/" /etc/ipfm.conf';
	system($szCmd);

	#restart ipfm since probably had problems with wrong network
	system ('/etc/init.d/ipfm stop >> '.$szSysRoot.'log/install.log');	
	system ('/etc/init.d/ipfm start >> '.$szSysRoot.'log/install.log');
} else {
		print "*** WARNING! Unable to ping. Setup not written to database!\n";
}

print "--------------- Network setup ended ----------------\n";
