#!/usr/bin/perl
package lib_net;
use strict;
use warnings;
use Exporter;

our @ISA= qw( Exporter );

# these CAN be exported.
our @EXPORT_OK = qw();

# these are exported by default.
our @EXPORT = qw( getIPs takeUpDeviceOk );

use autodie;
use DBI;

use func;

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
     	system($szTakeItUp);
     	print "And trying to give it an IP address...\n";
     	system("ip addr add ".$szInternalIP."/24 dev ".$szInternal);
     	my $szTmpFile = getLogRoot()."ipaddr.txt";
     	system("ip a show ".$szInternal." > ".$szTmpFile);
     	open my $pHandle, '<', $szTmpFile;
     	chomp(my @cLines = <$pHandle>);
        close $pHandle;
        my $bFoundIp = 0;
        my $bFoundUp = 0;
        foreach (@cLines) {
        	my $szLine = $_;
                if (index($szLine, "state UP") > -1) { 
                	$bFoundUp = 1;
                }
                if (index($szLine, $szInternalIP) > -1) {
                	$bFoundIp = 1;
		}
	}
	return ($bFoundIp && $bFoundUp);
}



1;



