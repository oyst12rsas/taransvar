#connectToTaraSecWan.perl

sub handleWg0Conf_dummy {

	my $szConfFileName = "/etc/wireguard/wg0.conf"; 
	my $szWgConf = `cat $szConfFileName`;

	print "Wireguard setup:\n";

	#print "$szWgConf\n";

	open(my $fh, '<', '/etc/wireguard/wg0.conf') or die $!;
	my @lines = <$fh>;
	close($fh);

	chomp @lines;
	my $szSection = "";
	my $cInterface = [];
	my $cPeer = [];

	foreach my $line (@lines) {

		if ($line =~ /^#/) {	
			#comment, do nothing
		} else {
		    print "$line\n";

			if ($line =~ /^[/) {	
				#New section.. check which one...
				if ($line =~ /^[Interface]/) {	
				
				}
			}
		}
	}
}




my $szPublicKeyFile = `sudo ls /home/*/publickey`;
my $szPublicKey = `cat $szPublicKeyFile`;
print "PublicKey: $szPublicKey\n";

`sudo wg set wg0 peer $szPublicKey allowed-ips 10.200.1.5/32`;
NOTE! Needs the public key of server and my private key...

my $getIpUrl = "http://81.88.19.252/taraSecWan.php?f=register&pl=1&nick=dummy&publ=ASDFTERTERYasdfNEW";

my $szIp = `curl -sS $getIpUrl`;
print "Ip: $szIp\n";