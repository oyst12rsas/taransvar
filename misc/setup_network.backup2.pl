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

my $szSetupHome = $szSysRoot;
my $szFullPath;
$szFullPath = $szSetupHome."temp"; mkdir $szFullPath unless -d $szFullPath;
$szFullPath = $szSetupHome."log"; mkdir $szFullPath unless -d $szFullPath;

my $szIndexHtml = "/var/www/html/index.html"; 
if (-e $szIndexHtml) {
	system ("rm $szIndexHtml"); #Default apache file prevents dashboard from showing if exists.
}

system("/sbin/iptables -P INPUT ACCEPT");
system("/sbin/iptables -P OUTPUT ACCEPT");



my @cDevices = getDevices();

my $szActiveLink = $cDevices[0];
my $szExternalIP = ipOfDevice($szActiveLink);

my @cHostnames = getHostnameIPs();
my $bConfirmed = 0;
my $szOtherFound = "";

foreach my $szIp (@cHostnames) {
	if ($szIp eq $szExternalIP) {
		$bConfirmed = 1;
	} else {
		$szOtherFound = $szIp; 
	}
}

if ($bConfirmed) {
	#print "External ip confirmed through hostname: $szExternalIP\n";
} else {
	if (length($szOtherFound)) {
		if (length($szExternalIP)) {
			print "\n******* WARNING **** Found other IP that may be the main IP: $szOtherFound (but probably not. Keeping $szExternalIP)\n";
		} else { 
			print "\n******* WARNING **** External IP was not set. This may be it: $szOtherFound. Trying.\n";
		}
	} else {
		if (!length($szExternalIP)) {	
			print "Unable to find external IP... Aborting.. Please make sure the computer is online and try again.\n\n";
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

#$> ip link
#1: lo: <LOOPBACK,UP,LOWER_UP> ... 
#   link/loopback 00:00:00:00:00:00 brd 00:00:00:00:00:00
#2: eth0: <BROADCAST,MULTICAST,UP,LOWER_UP> ...
#    link/ether 11:22:33:44:55:66 brd ff:ff:ff:ff:ff:ff
#3: enx0050b617c34f: <BROADCAST,MULTICAST,UP,LOWER_UP> ...
#    link/ether 11:22:33:44:55:66 brd ff:ff:ff:ff:ff:ff


print "Finished reading!\n";
	
my $szExternal = $szActiveLink;
my $szInternal = "";

foreach my $szDevice (@cDevices) {
	print "Checking $szDevice\n";
	if ($szExternal eq "") {
		$szExternal = $szDevice;
	} else {
		if ($szInternal eq "" && $szDevice ne $szExternal) {
			#if (substr($szDevice, 0, 1) ne "w") {
				$szInternal = $szDevice;

			#NOTE No longer treat wireless device as presumed internal net differently....
			#else {
			#	print "NOTE! Wireless device ($szDevice) found and WAN is already set! This could be because your Linux box is connected through bluetooth or another wireless device (which is ok). It could also be because because your Linux box is connected to Internet through cable. This NOT ok, because the cable connection should be reseverd for your wifi router that your users will connect to. Please fix this and run this script again.\n";
			#}
		} else {
			print "Skipping $szDevice (probably default route that is already identified as WAN\n";
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
		#push(@cDevices, $szExternal);
		$bPingSuccess = 1;
		$i = $nLoops+1;	#To ensure that quits the loop.

		$i = 100;	#Makes the for loop break while the network is working. 
		print "\nNetworking working with:\nWAN: $szExternal\n";
		print "LAN: $szInternal\nWill be written to DB\n";
		
#		my $szSQL = "update setup set WAN = '$szExternal', LAN = '$szInternal'";
#		my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
#		$sth->execute() or die "execution failed: $dbh->errstr()";
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

	my $szFirstChar = uc(substr($szInternal, 0, 1)); 
	if ($szFirstChar eq "W") {
		#print "\nWireless interface identified as provider of wifi (not external wifi router). Setting up..\n";  
		print"\n****************Setting up wireless nic as wifi hotspot: $szInternal, $szInternalIP, $szExternal\n";
		setupWifiNicAsHotspot($szInternal, $szInternalIP, $szExternal);
	} else {
		print "\n******** Setting up hotpot on cabled nic: $szInternal (and external wifi router with disables dhcp server - hopefully)\n";
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
	
	#*************** Ensure is isc-dhcp-server listening to correct interface ********
	$szCmd = '/bin/sed -i "s/INTERFACESv4=\"\w*\"/INTERFACESv4=\"'.$szInternal.'\"/" /etc/default/isc-dhcp-server';	
	system($szCmd);
        
	system("service isc-dhcp-server restart");

        setupFirewall($szInternalIP, $szInternal, $szExternal);
	
	my $conn = getConnection();
	print "********* About to save in db..\n";
	setSetupField($conn, "externalNic", $szExternal);
	setSetupField($conn, "internalNic", $szInternal);
	setSetupField($conn, "adminIP", $szExternalIP);
	setSetupField($conn, "internalIP", $szInternalIP);
	$conn->disconnect;
	
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







