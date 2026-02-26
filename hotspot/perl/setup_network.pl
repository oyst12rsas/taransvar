#!/usr/bin/perl
use strict;
use warnings;
use autodie;
use DBI;
use Data::Dumper qw(Dumper);
use File::Copy;

use lib ('.');
use func;
use lib_net;

#TO DO (fix this)!
   my $szDhcpConfFileName = "dhcpd.conf";
#   my $szDhcpConfFileName = "/home/thegurus/programming/setup/dhcpd.conf";

# /etc/default/dhcpd.conf
# INTERFACES="eth1"	#NOTE replace with LAN nic

#my $szSysRoot = "/home/setup/";
my $szSysRoot = getSysRoot();
my $szTmpIpLink = $szSysRoot."log/iplinkdmp.txt";
my $szTmpFile = $szSysRoot."log/temp.txt";
my $szPingTestTempFile = $szSysRoot."log/pingtest.txt";

print "\n\n *********** setup_network.pl **********************\n\n";

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
my $szExternalIP = "";

print "Scanning through devices.. but this is omitted (none put in array and later emptied)\n";
foreach (@lines) {
	my $szLine = $_;
	#print "Line read:->$szLine<-\n";

        #Check if this is the default route giving internet (and grab device and IP address)
        #Format: default via 192.168.100.1 dev wlp0s20f3 proto dhcp src 192.168.100.19 metric 600 
	if ($szLine =~ /^default\s.+\sdev\s(\w+)\sproto\sdhcp\ssrc\s(\S+)(\.*)/ && $szActiveLink eq "")
	{
	        print "Found external connection: $1 - $2\n";
                $szActiveLink = $1;
                $szExternalIP = $2;
	} 
	else
        {
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
	        		if ($szActiveLink eq "") {
	        			$szActiveLink = $2;
	        		}
	        	}

	        	open(my $fIproute, '>>', $szTmpIpRoute) or die "Could not open file '$szTmpIpRoute' $!";
        		say $fIproute "\n\n$szLogText\n";
        		close $fIproute;
                }
	}
}

#print "TESTING... exiting\n";
#exit;

if ($szActiveLink eq "") {
	print "WARNING! Active gateway NOT found through \"ip route\"\n";
}
else
{
	print "Active gateway found through \"ip route\": $szActiveLink\n";
}

#Find my ip address..   "hostname -I" gives:  "192.168.1.111 172.17.0.1"









system("hostname -I > $szTmpFile");
my $szHostnames = getFileContents($szTmpFile);
#open $handle, '<', $szTmpFile;
#chomp(@lines = <$handle>);
#close $handle;

print "Before checking hostname: external ip: $szExternalIP\n"; 
my @cHostnames = split(/\s/, $szHostnames);
my $nConfirmed = 0;
my $szOtherFound = "";
my $szInternalFound = "";

foreach (@cHostnames) {
	my $szIP = $_;
	#print "Found if: $szIP\n";
	if ($szIP eq $szExternalIP) {
		$nConfirmed = 1;
		last;
	} else {
		if (length($szIP) > 8 && length($szIP) <= 16) {
			#NOTE! also IPv6 addresses here...
			$szOtherFound = $szIP;
			my @cElements = split(/\./,$szIP);
			if ($cElements[3] eq "50" || $cElements[3] eq "60") {
				$szInternalFound = $szIP;
			} else {
				$szOtherFound = $szIP;
			}
			
		}
	}
}
	
if ($nConfirmed) {
	print "External ip confirmed through hostname: $szExternalIP\n";
} else {
	if (length($szOtherFound)) {
		if (length($szExternalIP)) {
			print "\n******* WARNING **** Found other IP that may be the main IP: $szOtherFound (but probably not. Keeping $szExternalIP)\n";
		} else { 
			print "\n******* WARNING **** Eternal IP was not set. This may be it: $szOtherFound. Trying.\n";
		}
	} else {
		if (!length($szExternalIP)) {	
			if (length($szInternalFound)) {
				print "\******* WARNING ***** External IP was not set but found one normally internal.. Using anyway: $szInternalFound\n";
				$szExternalIP = $szInternalFound;
			} else {
				print "Unable to find external IP... Aborting.. Please make sure the computer is online and try again.\n\n";
				exit;
			}
		} else {
		        print "************ Could not confirme external ip: $szExternalIP using hostname (but it may still be correct)\n";
		}
	}
}

my @cIPs = getIPs();
print "New routine for finding IPs: Main ip: ".($cIPs[0]?$cIPs[0]:"<none found>").", internal: ".($cIPs[1]?$cIPs[1]:"<none found>")."\n";
if (!$szExternalIP || !length($szExternalIP)) {
	$szExternalIP = $cIPs[0];
	print "Using $szExternalIP\n";
} else {
	if ($cIPs[0] ne $szExternalIP) {
		print "\n******* WARNING ******* There's a new rutine for finding main IP address and it gives different result:\nOld routine: $szExternalIP\nNew routine: ".$cIPs[0]."\n***** Please report which one is finding the correct one.\n\n";  
	} else {
		print "***** There's new routine for finding IP address, and it reports the same as the old.\n";
	}
}










if ($szExternalIP eq "")
{
        print "External IP not found! Aborting....";
        exit;
}

my $szIternalNett = "";
if ($szExternalIP =~ /192\.168\.50\./)
{
        $szIternalNett = "60";
} else {
        $szIternalNett = "50";
}
my $szInternalIP = "192.168.$szIternalNett.1";

print "Internal IP chosen: ".$szInternalIP."\n";

#my $sth = $dbh->prepare(
#        'select internalIP from setup')
#        or die "prepare statement failed: $dbh->errstr()";
#$sth->execute() or die "execution failed: $dbh->errstr()";
#if (my $cSetup = $sth->fetchrow_hashref()) 
#{
#	$szInternalIP = $cSetup->{'internalIP'};
#} else {
#    $szInternalIP = "192.168.0.1";
#}

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

my $szWrite;
my $filename;
my $fh;
my $bPingSuccess = 0;

for (my $i=0; $i <= $nLoops; $i++) 
{
        if (!defined($cTestArr[$i][0]) || !defined($cTestArr[$i][1]))
        {
                if (defined($cTestArr[$i][0]) || defined($cTestArr[$i][1]))
                {
                        print "******* Internal or external device not defined.. so skipping:\n";
                        if (defined($cTestArr[$i][0])) {
                                print "External: ".$cTestArr[$i][0]."\n";
                        } else 
                        {
                                if (defined($cTestArr[$i][1])) {
                                        print "Internal: ".$cTestArr[$i][1]."\n";
                                } else {
                                        print "None of them defined.... Shouldn't get here...\n";
                                }
                        }
                }
                next;
        }

 	$szExternal = $cTestArr[$i][0];
	$szInternal = $cTestArr[$i][1];

$szWrite = "# loopback
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
	$filename = '/etc/network/interfaces';
	open($fh, '>', $filename) or die "Could not open file '$filename' $!";
	print $fh $szWrite;
	close $fh;
        if (-e $filename) {	
	        print "\nNew contents written to $filename\n\n";
	} else {
	        print "******** ERROR! Unable to write to $filename\n\n";
	        exit;
	}
	
	#Old ubuntu: system("/bin/systemctl restart networking >> ".$szSysRoot."log/install.log");
	system("sudo systemctl restart systemd-networkd >> ".$szSysRoot."log/install.log");
	
	#Test if has internet access
	
	my $szWorkingDir = $szSysRoot."log";	#Required for wget to save index.html
	chdir $szWorkingDir; 

	if ( -e $szPingTestTempFile ) {
		unlink($szPingTestTempFile) or die "$szPingTestTempFile: $!"
	}

	if (ableToPing()) {
		print "Able to ping over: $szExternal\n";
		push(@devices, $szExternal);
		$bPingSuccess = 1;
		$i = $nLoops+1;	#To ensure that quits the loop.

		$i = 100;	#Makes the for loop break while the network is working. 
		print "\nNetworking working with:\nWAN: $szExternal\n";
		print "LAN: $szInternal\nWill be written to DB\n";

		my $szSQL = "update setup set WAN = '$szExternal', LAN = '$szInternal', internalIP = '$szInternalIP'";
		my $dbh = getConnection();
		my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
		$sth->execute() or die "execution failed: $dbh->errstr()";
	} 
}


if ($bPingSuccess)
{#asdf
        print "Now check if internal interface is up and running..\n";

	my $bFoundUp = 0;
        my $bFoundIp = 0;
	system("ip a show $szInternal > $szTmpFile");
	#my $size = -s $szPingTestTempFile;	#Check the size of the saved file..
	#print "\nIndex.html file size: $size\n\n";
	open my $pHandle, '<', $szTmpFile;
	chomp(my @cLines = <$pHandle>);
	close $pHandle;

        if (scalar @cLines == 0) {
                print "********** ERROR: Trying to check if ".$szInternal." is up but temp file is empty...\n";
                exit;
        }
        
        #*********** First check if has IP address on this device...
        my $szInternalIPFound = "";
        foreach (@cLines) {
                my $szLine = $_;
       		if ($szLine =~ /\sinet\s(\d*)\.(\d*)\.(\d*)\.(\d*).*/) {
       			$szInternalIPFound = "$1.$2.$3.$4";
       			print "IP found: $szInternalIPFound\n";
       		}
        }
        
	my $szLine = $cLines[0];
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
				my $bOk = takeUpDeviceOk ($szInternal, $szInternalIP);        		        
	                        
	                        if (!$bOk) {
	                                #Below experienced so too early to say if interface is down if "state DOWN" found.. 
	                                #2: enp3s0: <NO-CARRIER,BROADCAST,MULTICAST,UP> mtu 1500 qdisc fq_codel state DOWN group default qlen 1000
	                        
	                        
	                                print "********* WARNING! Tried to take up the interface.\nbut seems like it failed...\nRun this to check:  ip a show ".$szInternal." (should contain \"state UP\" and \"".$szInternal."\")\nPlease run again to check result.\n";
	                                print "Used to quit, but continuing.. Check the result and run again if not ok..\n\n";
                			#exit;
	                        } else {
	                                print "Seems like the attempt to take up the interface succeeded....\nCheck the result by running: ip a show ".$szInternal."\nResuming..\n";
	                        }
        		}
        		else 
        		{
        			print "\n***** Device may be up: ".$szLine." (improve script)..\n";
        			if (!$szInternalIPFound) {
        				my $bOk = takeUpDeviceOk($szInternal, $szInternalIP); 
	                        
	         	               if (!$bOk) {
	         	                       #Below experienced so too early to say if interface is down if "state DOWN" found.. 
	         	                       #2: enp3s0: <NO-CARRIER,BROADCAST,MULTICAST,UP> mtu 1500 qdisc fq_codel state DOWN group default qlen 1000
	         	                       print "********* WARNING! Tried to take up the interface,\nbut seems like it failed...\nRun this to check:  ip a show ".$szInternal." (should contain \"state UP\" and \"".$szInternal."\")\nPlease run again to check result.\n";
		                                print "Used to quit, but continuing.. Check the result and run again if not ok..\n\n";
	                			#exit;
		                        } else {
		                                print "Seems like the attempt to take up the interface succeeded....\nCheck the result by running: ip a show ".$szInternal."\nResuming..\n";
		                        }
        			}
        		}
			
		}
	}
	else {
	        print "******************No line in temp file... \n";
	}

#asdf

$szWrite = "# /etc/dhcp/dhcpd.conf
INTERFACES=\"$szInternal\";
#These should also be implemented:
ddns-update-style none;
option domain-name \"taransvar.router\";
option domain-name-servers 8.8.8.8, 8.8.4.4;
default-lease-time 600;
max-lease-time 7200;
authoritative;
log-facility local7;
subnet 192.168.$szIternalNett.0 netmask 255.255.255.0 {
    range 192.168.$szIternalNett.100 192.168.$szIternalNett.250;
    option subnet-mask 255.255.255.0;
    option broadcast-address 192.168.$szIternalNett.255; 
    option routers 192.168.$szIternalNett.1;
} ";
	
	print $szWrite;
	$filename = '/etc/dhcp/dhcpd.conf';
	open($fh, '>', $filename) or die "Could not open file '$filename' $!";
	print $fh $szWrite;
	close $fh;
	print "\nNew contents written to $filename\n\n";
	


        #print "******************* Copying dhcpd.conf not working... assuming it does.....\n";
        #print "Removing old dhcp.conf file for new to be written..\n";
	#system("sudo rm /etc/dhcp/dhcpd.conf");
        #system("sudo cp ".$szDhcpConfFileName." /etc/dhcp/dhcpd.conf");

	if (-f "/etc/dhcp/dhcpd.conf") {
		print "dhcpd.conf sucessfully copied (or error deleting??)!\n";
		system ("chown root:dhcpd /var/lib/dhcp /var/lib/dhcp/dhcpd.leases");
		system ("chmod 775 /var/lib/dhcp");
		system ("chmod 664 /var/lib/dhcp/dhcpd.leases");
	} else {
		print "******* ERROR ****** dhcpd.conf doesn't exist after attempt to copy..\n";
		exit;
	}

	if (0)
         {
            print "******* Error copying dhcpd.conf!\n\n";
            #exit;
         }


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

	#*************** Allow forwarding ************
	$szCmd = "/bin/sed -i 's/#net.ipv4.ip_forward=1/net.ipv4.ip_forward=1/' /etc/sysctl.conf";	
	system($szCmd);
	$szCmd = "/bin/sed -i 's/#net.ipv6.conf.all.forwarding=1/net.ipv6.conf.all.forwarding=1/' /etc/sysctl.conf";	
	system($szCmd);
	
	


	

	system("service isc-dhcp-server restart");

        print "************** NOTE! Run iptables rules for forwarding to work but it causes DNS to no longer work on this computer...\n";
 # NOTE! Dropped because they seem to cause DNS to no longer work...
        system("iptables -F INPUT");
        system("iptables -F OUTPUT");
        system("iptables -F FORWARD");
        system("iptables -P INPUT ACCEPT");
        system("iptables -P FORWARD ACCEPT");
        system("iptables -P OUTPUT ACCEPT");
 #       system("iptables -A FORWARD -i ".$szExternal." -o ".$szInternal." -m state --state ESTABLISHED,RELATED -j ACCEPT");
 #       system("iptables -A FORWARD -i ".$szInternal." -o ".$szExternal." -j ACCEPT");
 #       system("iptables -t nat -A POSTROUTING -j MASQUERADE");
        system("iptables -t nat -A POSTROUTING -o ".$szExternal." -j MASQUERADE");

        #Fix device in ipfm config
        print "Setting internal device in ipfm setup: $szInternal\n"; 
	$szCmd = '/bin/sed -i "s/DEVICE \w*/DEVICE '.$szInternal.'/" /etc/ipfm.conf';
	system($szCmd);

	#restart ipfm since probably had problems with wrong network
	system ('/etc/init.d/ipfm stop >> '.$szSysRoot.'log/install.log');	
	system ('/etc/init.d/ipfm start >> '.$szSysRoot.'log/install.log');
} else {
		print "*** WARNING! Unable to ping. Setup not written to database!\n";
}

print "--------------- Network setup ended ----------------\n";
print "If your 2nd interface is not showing with ifconfig:\n";
print "Enable forwarding: sudo sysctl -w net.ipv4.ip_forward=1\n";
print "          nano /etc/sysctl.conf\n";
print "   check status: sudo sysctl -p\n";
print "sudp cp dhcpd.conf /etc/dhcp\n";
print "How to take up internal interface manually:\n";
print "sudo ip link set ".$szInternal." up\n";
print "sudo ip addr add ".$szInternalIP."/24 dev ".$szInternal."\n";
print "NOTE! if ".$szInternal." has other IP than ".$szInternalIP.":\n";
print "ip addr del nnn.nnn.nn.nn/24 dev ".$szInternal."\n";
print "dhcp server status: sudo service isc-dhcp-server status\n";
print "(change status to restart to restart it)\n";
print "Check dhcp traffic: sudo dhcpdump -i ".$szInternal."\n";
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
#print "241022: Lost DNS.. After restart, had to take up ".$szInternal.", then assign IP. Then start DHCP, then import iptables rules..\n";
#print "241024: cat /etc/dhcp/dhcpd.conf --- Interface name removed.. Became: INTERFACES=\"\"\n\n";
print "If problem with dhcp:\nsudo service isc-dhcp-server status  (check Process: Main PID: and run \"sudo journalctl _PID=<pid>\"\n";

