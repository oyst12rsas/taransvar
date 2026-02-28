#!/usr/bin/perl
package lib_net;
use strict;
use warnings;
use Exporter;

our @ISA= qw( Exporter );

# these CAN be exported.
our @EXPORT_OK = qw();

# these are exported by default.
our @EXPORT = qw( getIPs takeUpDeviceOk setupFirewall startFirewall setupPortForwarding checkNetworkSetup checkStartHotspotSystem networkSetupOk getHostnameIPs dnsmasqRunning setupWifiNicAsHotspot );

use autodie;
use DBI;

use func;
use lib_dhcp;
use lib_cron;

sub getIPs {
	#NOTE! Fills with default route ip in [0] and IP of dhcp server in [1]
	my @IPs = ();
	my @cDevices = getDevices();
	
	foreach my $szDevice (@cDevices) {
		my $szIp = ipOfDevice($szDevice);
		
		if (!@IPs) {
			#First device is the one giving net
		        push (@IPs, $szIp);
		} else {
			if (@IPs == 1) {
				if (isLanAddress($szIp)) {
				        push (@IPs, $szIp);
				        last;
				}
			}
		}
	}

	return @IPs;	
}#sub getIPs

sub takeUpDeviceOk {
	my ($szInternal, $szInternalIP) = @_;
	my $szTakeItUp = "ip link set ".$szInternal." up";
     	print "Taking up $szInternal and trying to give it IP $szInternalIP\n";
     	system($szTakeItUp);
     	sleep 2;
     	system("ip addr add ".$szInternalIP."/24 dev ".$szInternal);
     	sleep 2;
     	my $szTmpFile = getLogRoot()."ipaddr.txt";
     	my $szCmd = "ip a show ".$szInternal." > ".$szTmpFile; 
     	system($szCmd);
     	open my $pHandle, '<', $szTmpFile;
     	chomp(my @cLines = <$pHandle>);
        close $pHandle;
        my $bFoundIp = 0;
        my $bFoundUp = 0;
        print "Result from $szCmd:\n";
        foreach (@cLines) {
        	my $szLine = $_;
        	print "$szLine\n";
                if (index($szLine, "state UP") > -1) { 
                	$bFoundUp = 1;
                }
                if (index($szLine, $szInternalIP) > -1) {
                	$bFoundIp = 1;
		}
	}
	print "\n";
	
	if ($bFoundIp && $bFoundUp) {
		return 1;
	} else {
		my $szMsg = "**** ERROR **** Device $szInternal is ".($bFoundUp?"UP":"STILL DOWN").(!$bFoundIp && !$bFoundUp?"and":"but")." has ".($bFoundIp?"":"NOT ")."been assigned ip $szInternalIP. Run ip a show $szInternal to check"; 	
		saveWarning($szMsg);
		print "$szMsg\n";
		return 0; #OT 250313
	}
}

sub setupUfw {
        my ($szInternalIp, $szInternalDevice) = @_;
        
        my $szSubNet = substr($szInternalIp, 0, rindex($szInternalIp,".")).".0";
        my $szRulesFile = "/etc/ufw/before.rules";
        my $szUfwBeforeRules = getFileContents($szRulesFile);
        if (length($szUfwBeforeRules) < 5) {
                print "**** WARNING: ufw was not installed.. Installing it.\n";
                system("sudo apt-get install ufw");
                my $szUfwBeforeRules = getFileContents("/etc/ufw/before.rules");
                if (length($szUfwBeforeRules) < 5) {
                        print "******** ERROR ***** Unable to install ufw. Aborting.. \n";
                        exit;
                }
        }
        my $nPosNat = index($szUfwBeforeRules, "*nat");
        my $nPosAccept = index($szUfwBeforeRules, ":POSTROUTING ACCEPT [0:0]");
        my $nPosCommit = index($szUfwBeforeRules, "COMMIT");
        
        my $szMasquerade = "-A POSTROUTING -s $szSubNet/8 -o $szInternalDevice -j MASQUERADE";
        my $nPosMasquerade = index($szUfwBeforeRules,$szMasquerade);
        #my $szNewFile = "/home/thegurus/programming/misc/test2_before.rules";
        my $szNewFile = $szRulesFile;
        my $szNewContent = "";
        if ($nPosNat < 0 && $nPosAccept < 0 && $nPosCommit < 0 && $nPosMasquerade < 0) {
                $szNewContent = "*nat\n:POSTROUTING ACCEPT [0:0]\n".$szMasquerade."\nCOMMIT\n".$szUfwBeforeRules;
        } else {
                if ($nPosNat >= 0 && $nPosAccept > $nPosNat && $nPosCommit > $nPosAccept && $nPosMasquerade < 0) {
                        #The other lines are there but not our masquerate directive. Add it just before commit
                        $szNewContent = substr($szUfwBeforeRules, 0, $nPosCommit).$szMasquerade."\n".substr($szUfwBeforeRules,$nPosCommit); 
                } else {
                        if ($nPosNat >= 0 && $nPosAccept > $nPosNat && $nPosCommit > $nPosAccept && $nPosMasquerade > $nPosAccept) {
                                print "*** ufw was already set up. Skipping.\n"; 
                        } else {
                                print "****** ERROR ****** ".$szRulesFile." seems to have *nat setup but not as expected.\nSkipping. You should check it. And add this before commit:\n".$szMasquerade."\n\n ";  
                        }
                }
        }
        
        if (length($szNewContent)) {
                #open my $fh, '>', $szNewFile;
                open (my $fh, ">", $szNewFile) or die "Could not open file '$szNewFile' $!";                
                #print {$fh} $szNewContent."\n";
                print $fh $szNewContent."\n";
                close($fh);
                #print "***** WARNING ******* Trying to insert this into ".$szRulesFile.":\n".$szMasquerade."\nBut not yet ready so put here: ".$szNewFile."\n\n\nContent:\n".$szNewContent."\n\n\n";
                print $szNewFile." updated.\n";
        }

        system ("sudo ufw enable");
        system ("sudo ufw allow https");
        system ("sudo ufw allow http");
}

sub setupIpTablesTheOldWay {
        my ($szInternalIp, $szInternalDevice, $szExternalDevice) = @_;
        print "** Setting up iptables forwarding the old way **\n";
        system("iptables -F INPUT");
        system("iptables -F OUTPUT");
        system("iptables -F FORWARD");
        system("iptables -P INPUT ACCEPT");
        system("iptables -P FORWARD ACCEPT");
        system("iptables -P OUTPUT ACCEPT");
        my $cmd = "iptables -t nat -A POSTROUTING -o ".$szExternalDevice." -j MASQUERADE";
        my $szMsg = "About to exec: $cmd";
        print "$szMsg\n";
        system($cmd);
	addWarningRecord(0, $szMsg);
}

sub setupIpTables {
        my ($szInternalIp, $szInternalDevice, $szExternalDevice) = @_;
#        print "************** NOTE! Run iptables rules for forwarding to work but it causes DNS to no longer work on this computer...\n";
        #setupIpTablesTheOldWay($szInternalIp, $szInternalDevice, $szExternalDevice);
        #enp3s0, wlp0s20f3
        
	my $szLogFileName = getIptablesLogFileName();
	if (-f $szLogFileName) {
		unlink($szLogFileName);
	}

	my $comm = getConnection();
	my @cDevices = getDevices();

	if (!defined($szInternalDevice) || $szInternalDevice eq "") {
		$szInternalDevice = $cDevices[1];
		addWarningRecord($comm, "******* Internal NIC was blank. Trying with $szInternalDevice instead.");
	}

        print "******************* Setting up iptables forwarding for subnet $szInternalDevice -> $szExternalDevice ***********************\n";

	sleep 10; #To make the network setup settle... 
        my $szCmd = "sudo iptables -t nat -A POSTROUTING -o $szExternalDevice -j MASQUERADE";
	system($szCmd);
        addToLogFile($szLogFileName, $szCmd); 
	#addWarningRecord($comm, $szCmd);
	print "$szCmd\n";
	
	#$szCmd = "sudo iptables -A FORWARD -i $szInternalDevice -o $szExternalDevice -m state --state RELATED,ESTABLISHED -j ACCEPT";
	$szCmd = "sudo iptables -A FORWARD -i $szExternalDevice -o $szInternalDevice -m state --state RELATED,ESTABLISHED -j ACCEPT";
	system($szCmd);
        addToLogFile($szLogFileName, $szCmd); 
	#addWarningRecord($comm, $szCmd);
	print "$szCmd\n";
	
	$szCmd = "sudo iptables -A FORWARD -i $szInternalDevice -o $szExternalDevice -j ACCEPT";
	system($szCmd);
        addToLogFile($szLogFileName, $szCmd); 
	#addWarningRecord($comm, $szCmd);
	print "$szCmd\n";
	#addWarningRecord($comm, "*********** iptables set up (changed) ********");
	
       	$comm->disconnect;	

	#Make permanent: sudo netfilter-persistent save
}

sub iptablesAddMoreRulesDummy {

	#250225
	#iptables -I INPUT -i $szExternalDevice -p tcp --dport 80 -m comment --comment "# web traffic #" -j ACCEPT
	#iptables -A INPUT -i $szExternalDevice -m limit --limit 100/min -j LOG --log-prefix "iptables dropped " --log-level 7
	#iptables -A INPUT -i $szExternalDevice -j DROP	


}


sub useUfw {
        return 0;
}

sub setupFirewall {
        my ($szInternalIp, $szInternalDevice, $szExternalDevice) = @_;
        if (useUfw()) {
                setupUfw($szInternalIp, $szInternalDevice);
        } else {
                setupIpTables($szInternalIp, $szInternalDevice, $szExternalDevice);
        }
} #sub setupFirewall

sub startFirewall {
        #This function is called when booting...
        if (!useUfw()) {
                setupIpTables("", "", getActiveLink());
        } else {
                #ufw is permanently enables...
        }
}

sub setupPortForwarding {
        #print "****** WARNING ***** Setting up port forwarding for internal servers is disabled (caused network problems)....\n";
        #return;
        #according to chatgpt:
        #Forward incoming traffic on port 8080 to destination address....
        #sudo iptables -t nat -A PREROUTING -p tcp --dport 8080 -j DNAT --to-destination 192.168.50.130:80
        #masquerade outbound traffic
        #sudo iptables -t nat -A POSTROUTING -p tcp --dport 80 -j MASQUERADE
        #Make rules persistant:
        #sudo sh -c 'iptables-save > /etc/iptables/rules.v4'

	my $dbh = getConnection();
	my $szSQL = "select inet_ntoa(ip) as ip, port, publicPort from internalServers";
        my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
	$sth->execute() or die "execution failed: $sth->errstr()";
	print "\nSetting up port forwarding for internal servers.....\n";
	my $nCount = 0;
	while (my $row = $sth->fetchrow_hashref()) {
	        my $szMsg = "Setting up port ".$row->{"publicPort"}." to point to ".$row->{"ip"}.":".$row->{"port"};
	        addWarningRecord(0, $szMsg);
	        print "$szMsg\n";
		system("iptables -t nat -A PREROUTING -p tcp --dport ".$row->{"publicPort"}." -j DNAT --to-destination ".$row->{"ip"}.":".$row->{"port"});
	        system("iptables -t nat -A POSTROUTING -p tcp --dport ".$row->{"port"}." -j MASQUERADE");
	        $nCount++;
	}
	if (!$nCount) {
		my $szMsg = "No port forwarding specified (se \"Servers\" in localhost setup)";
		print "$szMsg\n";
	}

        #According to ChatGPT using UFW
        #sudo nano /etc/ufw/before.rules
        #*nat
	#:PREROUTING ACCEPT [0:0]
	#:POSTROUTING ACCEPT [0:0]
	#-A PREROUTING -p tcp --dport 8080 -j DNAT --to-destination 192.168.1.10:80
	#-A POSTROUTING -p tcp --dport 80 -j MASQUERADE
	#COMMIT
        #sudo ufw reload
        
        #Use linux containers (lxc) network forwarding () FAILED WITH; Creating the instance Error: Failed instance creation: Failed creating instance record: Failed initialising instance: Failed getting root disk: No root device could be found
        #Install lxc - linux container...
        #sudo snap install lxd
        #sudo lxc launch ubuntu:24.04
        #lxc network forward list wlp0s20f3
        
        #original suggestion: iptables -t nat -A PREROUTING -p tcp -d 10.0.0.132 --dport 29418 -j DNAT --to-destination 10.0.0.133:29418
        #system ("iptables -t nat -A PREROUTING -p tcp -d 192.168.100.10 --dport 8080 -j DNAT --to-destination 192.168.50.30:80");
        #system ("iptables -t nat -A POSTROUTING ! -s 127.0.0.1 -j MASQUERADE");
        
        #Another attempt
        #system ("iptables -t nat -A PREROUTING -j DNAT -d 192.168.100.10 -p tcp --dport 8080 --to 192.168.50.30:80");

        #system ("ssh -L 192.168.100.10:8080:192.168.50.30:80 root@192.168.50.30");
        
        #wlp0s20f3 -> enp3s0
        #sudo iptables -A FORWARD -i wlp0s20f3 -o enp3s0 -p tcp --syn --dport 8080 -m conntrack --ctstate NEW -j ACCEPT        
        #sudo iptables -A FORWARD -i wlp0s20f3 -o enp3s0 -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
        #sudo iptables -A FORWARD -i eth1 -o wlp0s20f3 -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
        #sudo iptables -t nat -A PREROUTING -i wlp0s20f3 -p tcp --dport 8080 -j DNAT --to-destination 192.168.50.30
        #sudo iptables -t nat -A POSTROUTING -o enp3s0 -p tcp --dport 8080 -d 192.168.50.1 -j SNAT --to-source 192.168.100.10
        #        sudo iptables -P FORWARD DROP
      

}

sub deviceIsUp {
	my ($szDevice) = @_;
	my $szRes = `ip a | grep $szDevice`;	#OT 250313
	if (index($szRes, "state DOWN") > 0) {
		return 0;
	} else {
		if (index($szRes, "state UP") > 0) {
			return 1;
		} else {
			return 0;
		}
	}
}

sub ipIsUp {
	my ($szlIP, @cDevices) = @_;
	
	foreach (@cDevices) {
		my $szDevice = $_;
		my $szIp = ipOfDevice($szDevice);
		if ($szIp eq $szlIP) {
			print "$szlIP found for $szDevice\n";
			my $bUp = deviceIsUp($szDevice);	#NOTE! Changed OT 250313
			print "$szDevice is ".($bUp?"UP": "DOWN")."\n";
			return $bUp;
		}
	
	}
	return 0;
}

sub printAndWarn {
	my $conn = $_[0]; 
	my $szMsg = $_[1];  
	print "$szMsg\n";
	addWarningRecord($conn, $szMsg);
}

sub checkNetworkSetup {
	print "************ Checking network setup *************\n";
	my $bNetworkSetupOk = 1;
	my $conn = getConnection();
	my $cSetup = getSetup();
	my @cDevices = getDevices();
	print "Devices: @cDevices\n"; 
	
	my $szExternalNic = $cSetup->{"externalNic"};	
	my $nDeviceCount = @cDevices;
	
	if (!defined($szExternalNic) && $szExternalNic ne $cDevices[0]) {
		if ($nDeviceCount && $cDevices[0] ne "") {
			printAndWarn($conn, "External device changed from $szExternalNic to $cDevices[0]");  
			setSetupField($conn, "externalNic", $cDevices[0]);
		        $szExternalNic = $cDevices[0];
		} else {
			my $szWarn = "*** WARNING! No external network device!";
			printAndWarn($conn, $szWarn);
			setNetworkStatus($szWarn);
			$bNetworkSetupOk = 0;
			return $bNetworkSetupOk;
		}
	}

	my $szExternalIP = $cSetup->{"adminIP"};

	my $szCurrentExternalIp = ipOfDevice($szExternalNic);
	
	if ($szCurrentExternalIp ne $szExternalIP) {
		printAndWarn($conn, "External IP changed from $szExternalIP to $szCurrentExternalIp (NOTE! Should also update routine for sending notification to partners)");  
		setSetupField($conn, "adminIP", $szCurrentExternalIp);
	        $szExternalIP = $szCurrentExternalIp;
	}
	
	my $szInternalIP = $cSetup->{"internalIP"};
	
	print "IPs according to setup: External: $szExternalIP, internal: $szInternalIP\n"; 
	if (!ipIsUp($szExternalIP, @cDevices)) {
		#To find NIC: $cSetup->{"externalNic"}
		my $szWarn = "External network device (according to setup - ) is not up.. Maybe got a new IP address?";
		printAndWarn($conn, $szWarn);
		$bNetworkSetupOk = 0;
		setNetworkStatus($szWarn);
	}

	my $szInternalNic = $cSetup->{"internalNic"};

	if (defined($szInternalIP) && $szInternalIP ne "") {
		if (!defined($szInternalNic) || $szInternalNic eq "") {
			if ($nDeviceCount < 2) {
				my $szWarn = "****** WARNING *** Internal NIC not found though it's set up in the setup. Devices: @cDevices";
				printAndWarn($conn, $szWarn);
				setNetworkStatus($szWarn);
			} else {
				$szInternalNic = $cDevices[1];
				printAndWarn($conn, "Internal NIC set to $szInternalNic"); 
				setSetupField($conn, "internalNic", $szInternalNic);
			}
		}
	
		if ($nDeviceCount >= 2 && !ipIsUp($szInternalIP, @cDevices)) {
			my $szNetworkSetup = getFileContents("/etc/network/interfaces"); 
			my @cFound = split("\n",$szNetworkSetup);
			my @cMatches = grep(/$szInternalNic/, @cFound);
			my $nPresumedInternaFoundInInterfaces = @cMatches; 
			print "Found: @cMatches\n"; 
			my @cCommented = grep(/#/, @cMatches);
			my $nCommented = @cCommented;
			print "Commented out $nCommented\n"; 
			my $szMsg = "";
			if ($nPresumedInternaFoundInInterfaces == 2 && $nCommented == 0) {
				print "****** Trying to take up $szInternalNic with $szInternalIP\n"; 
				if (takeUpDeviceOk($szInternalNic, $szInternalIP)) {
					$szMsg = "Good news! Seems like the internal interface $szInternalNic was down, but was able to bring it up with IP $szInternalIP\n";
					system("service isc-dhcp-server restart");
				} else {
					$szMsg = "Internal network device (according to setup) is not up.. Should check if there's active subnet...";
					$bNetworkSetupOk = 0;
				}
			} else {
				$szMsg = "The system is set up with internal network device, but unable to take it up because the content of interfaces file is confusing. Maybe it's edited manually? Internal device $szInternalNic is found $nPresumedInternaFoundInInterfaces times, should be 2: @cMatches\n";
				
				$bNetworkSetupOk = 0;
			}
			
			if ($szMsg ne "") {
				printAndWarn($conn, $szMsg);
				setNetworkStatus($szMsg);
			}
		}
	} else {
		my $szWarn = "This system is not set up with Internal IP and can not operate as router.";
		printAndWarn($conn, $szWarn);
		setNetworkStatus($szWarn);
		print "$szWarn\n";
	}
	$conn->disconnect;
	return $bNetworkSetupOk;
}

sub checkStartHotspotSystem {
	#$szHotConn = 
	addWarningRecord(0, "Entered checkStartHotspotSystem");
	
	my $cSetup = getSetup();
	if (!$cSetup->{"hotspot"}) {
		addWarningRecord(0, "Not set up as a hotspot.");
		return;
	}
	addWarningRecord(0, "About to set up hotspot");
	system("ipfm");
	if (programRunning("ipfm")) {
		print "ipfm running after I started it\n";
	} else {
		my $szTxt = "****** ERROR: Didn't manage to start ipfm";
		print "$szTxt\n";
		addWarningRecord(0,$szTxt);
	}
}


sub networkSetupOk {
	`sudo rfkill unblock wifi`;	#OT 250313 - Just in case hotspot is delivered through wifi... 

	if (checkNetworkSetup()) {
		if (dhcpServerStatusOk()) {
			my $szSetup = getSetup();
			if (setupIpTables($szSetup->{"internalIP"}, $szSetup->{"internalNic"}, $szSetup->{"externalNic"})) {
				#addWarningRecord(0, "***** Warning! Firewall setup is excluded from boot routine. You may need to set up that manually.");
			
		#		setupPortForwarding();				
			
			
				setNetworkStatus("ok");
				resetSetup();	#Reset because ip addresses, nics, setupNetwork and more may have been changed
				return 1;
			}
		}
	}
	resetSetup();	#Reset because ip addresses, nics, setupNetwork and more may have been changed
	return 0;
}

sub getHostnameIPs {
	my $szTmpFile = getLogRoot()."hostname.txt";
	system("hostname -I > $szTmpFile");
	my $szHostnames = getFileContents($szTmpFile);
	my @cHostnames = split(/\s/, $szHostnames);
	my @IPs = ();

	foreach my $szIP (@cHostnames) {
		#print "Found if: $szIP\n";
		if (length($szIP) > 6 && length($szIP) <= 16) {
			push(@IPs, $szIP);
		}
	}
	return @IPs;
}

sub dnsmasqRunning {
	
	#****** Check if other dhcp server is running (specifically dnsmasq)
	my $szLsofLogFile = getLogRoot()."lsof.txt";
	system ("lsof -i :67 > $szLsofLogFile");
	my @cLsof = getFileLines($szLsofLogFile);
	#COMMAND    PID  USER   FD   TYPE DEVICE SIZE/OFF NODE NAME
	#NetworkMa 1119  root   29u  IPv4  19665      0t0  UDP 192.168.100.201:bootpc->192.168.100.1:bootps 
	#dhcpd     9471 dhcpd    7u  IPv4  52901      0t0  UDP *:bootps
			
	foreach my $szLsof (@cLsof) {
		if ($szLsof =~ /^COMMAND\s*PID/ ) {
			#heading
		} else {
			if ($szLsof =~ /^NetworkMa/ ) {
				#Network manager
			} else {
				if ($szLsof =~ /^dhcpd/ ) {
					#Probably the isc-dhcp-server
					print "isc-dhcp-server seems to be listening to port 67.\n";
				} else {
					if ($szLsof =~ /^dnsmasq/ ) {
						#Probably the isc-dhcp-server
						print "\n*********** ERROR ***** dnsmasq seems to be listening to port 67.\nThis means that isc-dns-server will not work.\nTo disable it:\nsudo systemctl stop dnsmasq\nsudo systemctl disable dnsmasq\n\n";
					} else {
						print "****** ERROR! Unknown process listening to port 67:\n$szLsof\nTo check: sudo lsof -i :67\n";
					}
					return 1;
				}
			}
		}
				
	}
	return 0;
}


sub run_command {
    my ($cmd) = @_;
    print "Running: $cmd\n";
    my $output = `$cmd 2>&1`;
    if ($? != 0) {
        print "Warning: Command failed - $cmd\n$output\n";
    }
    return $output;
}

#sub get_wireless_interface {
#    my $interface = run_command("iw dev | awk '\$1==\"Interface\"{print \$2}'");
#    chomp($interface);
#    die "No wireless interface found! Install 'iw' package and try again.\n" if !$interface;
#    return $interface;
#}

#sub get_internet_interface {
#    my $interface = run_command("ip route | awk '/default/ {print \$5}'");
#    chomp($interface);
#    die "No internet interface found! Check your network connection.\n" if !$interface;
#    return $interface;
#}

sub is_ip_assigned {
    my ($nic, $ip) = @_;
    my $output = `ip addr show $nic | grep "$ip"`;
    return $output ? 1 : 0;
}

sub setupWifiNicAsHotspot {
    my ($nic, $ip, $internet_interface) = @_;

    print "Using wireless interface: $nic\n";
    print "Using internet interface: $internet_interface\n";

    #print "Enter your (short) name (it will be part of the SSID for the hotspot): ";
    #chomp(my $szName = <STDIN>);
	my $random_number = int(rand(99)) + 1;
    my $ssid = "Taransvar_Hotspot$random_number";
    
    #print "Enter WPA passphrase (min 8 characters): ";
    #chomp(my $wpa_passphrase = <STDIN>);

    #if (length($wpa_passphrase) < 8) {
        #die "WPA passphrase must be at least 8 characters long.\n";
    #}

    #run_command("sudo apt update && sudo apt upgrade -y");
    run_command("sudo apt install iw");
    run_command("sudo apt install hostapd");
    run_command("sudo apt install iptables-persistent");
    
    run_command("sudo systemctl unmask hostapd");
    run_command("sudo systemctl enable hostapd");

#    if (!is_ip_assigned($nic, $ip)) {
#        run_command("sudo ip addr add $ip/24 dev $nic");
#    } else {
#        print "IP $ip/24 is already assigned to $nic. Skipping...\n";
#    }
    
 #   run_command("ip addr show $nic");

#    my $dhcpcd_conf = "/etc/dhcpcd.conf";
#    open(my $fh, '>>', $dhcpcd_conf) or die "Could not open file '$dhcpcd_conf' $!";
#    print $fh "\ninterface $nic\nstatic ip_address=$ip/24\nnohook wpa_supplicant\n";
#    close($fh);

#    my $dhcpd_conf = "/etc/dhcp/dhcpd.conf";
#    open($fh, '>', $dhcpd_conf) or die "Could not open file '$dhcpd_conf' $!";
#    print $fh <<"EOF";
#ddns-update-style none;
#option domain-name "taransvar.router";
#option domain-name-servers 8.8.8.8, 8.8.4.4;
#default-lease-time 600;
#max-lease-time 7200;
#authoritative;
#log-facility local7;
#subnet 192.168.50.0 netmask 255.255.255.0 {
#    range 192.168.50.100 192.168.50.253;
#    option subnet-mask 255.255.255.0;
#    option broadcast-address 192.168.50.255;
#    option routers 192.168.50.1;
#}
#EOF
#    close($fh);

#    my $dhcp_default = "/etc/default/isc-dhcp-server";
#    run_command("sudo sed -i '/^INTERFACESv4=/d' $dhcp_default");
#    open($fh, '>>', $dhcp_default) or die "Could not open file '$dhcp_default' $!";
#    print $fh "INTERFACESv4=\"$nic\"\n";
#    close($fh);

    my $hostapd_conf = "/etc/hostapd/hostapd.conf";
    open(my $fh, '>', $hostapd_conf) or die "Could not open file '$hostapd_conf' $!";
    print $fh <<"EOF";
interface=$nic
ssid=$ssid
hw_mode=g
channel=7
wmm_enabled=0
macaddr_acl=0
auth_algs=1
ignore_broadcast_ssid=0
wpa=0  # Ensure WPA is disabled
EOF
    close($fh);

 #   my $sysctl_conf = "/etc/sysctl.conf";
 #   open($fh, '>>', $sysctl_conf) or die "Could not open file '$sysctl_conf' $!";
 #   print $fh "net.ipv4.ip_forward=1\n";
 #   close($fh);
 #   run_command("sudo sysctl -p");

   # run_command("sudo iptables -t nat -A POSTROUTING -o $internet_interface -j MASQUERADE");
   # run_command("sudo iptables -A FORWARD -i $internet_interface -o $nic -m state --state RELATED,ESTABLISHED -j ACCEPT");
   # run_command("sudo iptables -A FORWARD -i $nic -o $internet_interface -j ACCEPT");
   # run_command("sudo sh -c 'iptables-save > /etc/iptables.ipv4.nat'");

 #   my $rc_local = "/etc/rc.local";
 #   open($fh, '>>', $rc_local) or die "Could not open file '$rc_local' $!";
 #   print $fh "iptables-restore < /etc/iptables.ipv4.nat\n";
 #   close($fh);

    #run_command("sudo systemctl restart isc-dhcp-server");
    run_command("sudo systemctl restart hostapd");
    #run_command("sudo systemctl enable isc-dhcp-server");
    run_command("sudo systemctl enable hostapd");

    print "\nAccess Point setup complete!\n";
    print "SSID: $ssid\n";
    #print "WPA Passphrase: $wpa_passphrase\n";
    print "Static IP Assigned: $ip/24\n";
}

#my $wireless_interface = get_wireless_interface();
#my $internet_interface = get_internet_interface();

1;

