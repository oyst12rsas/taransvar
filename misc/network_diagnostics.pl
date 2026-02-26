#!/usr/bin/perl
use strict;
use warnings;
use autodie;
use DBI;
use Data::Dumper qw(Dumper);
use File::Copy;
#TO DO (fix this)!
   my $szDhcpConfFileName = "/home/thegurus/programming/setup/dhcpd.conf";

# /etc/default/dhcpd.conf
# INTERFACES="eth1"	#NOTE replace with LAN nic

#my $szSysRoot = "/home/setup/";
my $szSysRoot = "/root/setup/";


print "\n\n *********** network_diagnostics.pl **********************\n\n";

if (-d $szSysRoot) {
    # directory called cgi-bin exists
    print "Setup directory already exists...\n";
}
else {
        system("mkdir ".$szSysRoot);
}
if (-d $szSysRoot."log") {
    # directory called cgi-bin exists
    print "Setup log directory already exists...\n";
}
else {
        system("mkdir ".$szSysRoot."log");
}



my $szTmpFile = $szSysRoot."log/temp.txt";
my $szPingTestTempFile = $szSysRoot."log/pingtest.txt";
my $szTmpIpLink = $szSysRoot."log/iplink.txt";

my $szNetwork = "?";

system("ip route > $szTmpFile");

#Generates file: 
#default via 192.168.100.1 dev eno1  proto static  metric 100 
#169.254.0.0/16 dev eno1  scope link  metric 1000 
#192.168.100.0/24 dev eno1  proto kernel  scope link  src 192.168.100.17  metric 100 


my $szExternalIP = "";
my $szInternalIP = "?";
#Find my ip address..   "hostname -I" gives:  "192.168.1.111 172.17.0.1"
system("hostname -I > $szTmpFile");

open my $handle, '<', $szTmpFile;
chomp(my @lines = <$handle>);
close $handle;

foreach (@lines) {
	my $szLine = $_;
	if ($szLine =~ /^(.+)\s(.+)/)
	{
		print "Found IP: $1\n";
		$szExternalIP = $1;
	}
	else {
	        print "********* On dev machine probably gave 2 ips because of docker installed...\n";
	}
}

if ($szExternalIP eq "")
{
        print "External IP not found! Aborting....";
        exit;
}


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
print "New attempt to find devices.. This time from ip link\n";
foreach (@lines) {
	my $szLine = $_;
	#print "Line read:->$szLine<-\n";
	
	if ($szLine =~ /^\d:\s(\w+).+/)
	{
	        if ($1 ne "lo" && index($szLine, "docker") == -1)
	        {
		print "Found: $1\n";
		push(@devices, $1)
		}
		else {
		        print "Dropping ".$1."\n";
		}
	}
}

print "Finished reading!\n";
	
#Collect all network devices and check which setup gives access..
my $szActiveLink = "?";
my $szExternal = $szActiveLink;
my $szInternal = "";
my $szInternalIP + "?";

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

print "WAN: $szExternal\n";
print "LAN: $szInternal\n";

my $bPingSuccess = 0;

#Test if has internet access
	
my $szWorkingDir = $szSysRoot."log";	#Required for wget to save index.html
chdir $szWorkingDir; 

if ( -e $szPingTestTempFile ) {
	unlink($szPingTestTempFile) or die "$szPingTestTempFile: $!"
}
my $szPingTest2File = $szSysRoot."log/pingtest2.txt";
system("/bin/ping -c 3 google.com > $szPingTest2File");
#my $size = -s $szPingTest2File;	#Check the size of the saved file..
#print "\nPing result file size: $size\n\n";

#Check if able to ping
open my $pPing2TestHandle, '<', $szPingTest2File;
chomp(my @pingLines = <$pPing2TestHandle>);
close $pPing2TestHandle;

print "****Checking ping result (cat ".$szPingTest2File.")..\n";
 my $i=0;
 my $nLoops=0;
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
#else {
#	print "No success line: $szLine\n";
#}
}
	
if ($bPingSuccess) {
	$i = 100;	#Makes the for loop break while the network is working. 
	print "\nNetworking working with:\nWAN: $szExternal\n";
	print "LAN: $szInternal\nWill be written to DB\n";
	
#	my $szSQL = "update setup set WAN = '$szExternal', LAN = '$szInternal'";
$szInternalIP#	my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
#	$sth->execute() or die "execution failed: $dbh->errstr()";
} 


if ($bPingSuccess)
{
        print "Now check if internal interface is up and running..\n";

	system("ip a show $szInternal > $szTmpFile");
	#my $size = -s $szPingTestTempFile;	#Check the size of the saved file..
	#print "\nIndex.html file size: $size\n\n";
	open my $pHandle, '<', $szTmpFile;
	chomp(my @cLines = <$pHandle>);
	close $pHandle;

        if (scalar @cLines == 0) {
                print "********** Trying to check if ".$szInternal." is up but temp file is empty...\n"; 
        }
        
	my $szLine = @cLines[0];
	if (length($szLine)) {
		print "Checking line: $szLine\n";
		if ($szLine =~ /Device "$szInternal" does not exist./)
		{
			print "***** $szInternal does not exist (is down..) Trying to bring it up..\n";
                	system("ip link set ".$szInternal." up");
			print "And trying to give it an IP address...\n";
			system("ip addr add ".$szInternalIP."/24 dev ".$szInternal);
			print "Please run the script again to test the effect....\nABORTING!\n";
			exit;
		}
		else {
        		if ($szLine =~ /state DOWN/)
        		{
        		        print "***** Device is down: ".$szLine.".. Trying to take it up..\n";
                        	system("ip link set ".$szInternal." up");
        			print "And trying to give it an IP address...\n";
        			system("ip addr add ".$szInternalIP."/24 dev ".$szInternal);
        			system("ip a show ".$szInternal." > ".$szTmpFile);
                        	open $pHandle, '<', $szTmpFile;
                        	chomp(@cLines = <$pHandle>);
                        	close $pHandle;
                        	my $bFoundUp = 0;
                        	my $bFoundIp = 0;
                		foreach (@cLines) {
                        		my $szLine = $_;
                        		if (index($szLine, "state UP") > -1) { 
                        		        $bFoundUp = 1;
                        		}
                        		if (index($szLine, $szInternalIP) > -1) {
                        		        $bFoundIp = 1;
                        		}
	                        }
	                        
	                        if (!$bFoundUp || !$bFoundIp) {
	                                print "********* ERROR! Tried to take up the interface, but seems like it failed...\nRun this to check:  ip a show ".$szInternal." (should contain \"state UP\" and \"".$szInternal."\")\nPlease run again to check result.\n";
                			exit;
	                        } else {
	                                print "Seems like the attempt to take up the interface succeeded....\nCheck the result by running: ip a show ".$szInternal."\nResuming..\n";
	                        }
        		}
        		else 
        		{
        		        print "***** Device may be up: ".$szLine." (improve script)..\n";
        		}
			
		}
	}
	else {
	        print "******************No line in temp file... \n";
	}


} else {
		print "*** WARNING! Unable to ping. Setup not written to database!\n";
}

print "************* Note! Should run service isc-dhcp-server status and check if there's error code and (check Process: Main PID: and run \"sudo journalctl _PID=<pid>\"\n";
print "Log said: Not configured to listen on any interfaces!.... Interface was: INTERFACES=\"eth1\";\n";



print "--------------- Network setup ended ----------------\n";
print "If your 2nd interface is not showing with ifconfig:\n";
print "Enable forwarding: sudo sysctl -w net.ipv4.ip_forward=1\n";
print "          nano /etc/sysctl.conf\n";
print "   check status: sudo sysctl -p\n";
print "sudp cp dhcpd.conf /etc/dhcp\n";
print "How to take up internal interface manually:\n";
print "sudo ip link set ".$szInternal." up\n";
print "sudo ip addr add ".$szInternalIP."/24 dev ".$szInternal."\n\n";
print "Restart dhcp server: sudo service isc-dhcp-server status\n";
print "Check dhcp traffic: sudo dhcpdump -l".$szInternal."\n";
print "Restart networking: sudo systemctl restart systemd-networkd\n";
print "NOTE! Check that wifi router cable is conneced in PC port and not in WAN port..\n";
print "When testing, don't user vg.no or cnn.com (blacklisted)\n";
print "\nsudo install gcc\n";
print "sudo ufw status\n";
#sudo apt install isc-dhcp-server make gcc libmysqlclient-dev mysql-server apache2 php libapache2-mod-php php-mysql
#sudo mysql -u root  then copy paste from www/install.sql
#NOTE! Fix: when empty DB, it's spinning requesting configuration....
print "DNS-problems: \nsudo nano /etc/systemd/resolved.conf (add 8.8.8.8 8.8.4.4 to DNS= under [resolve])";
print "Check DNS setup: resolvectl status  (When problem at gaming pc, had only gateway as DNS)\nRestart: sudo systemctl restart systemd-resolved\n";
print "241022: Lost DNS.. After restart, had to take up ".$szInternal.", then assign IP. Then start DHCP, then import iptables rules..\n";
print "241024: cat /etc/dhcp/dhcpd.conf --- Interface name removed.. Became: INTERFACES=\"\"\n\n";







