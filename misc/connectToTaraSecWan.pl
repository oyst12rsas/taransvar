#connectToTaraSecWan.perl

my $szPublicKeyFile = `sudo ls /home/*/publickey`;
my $szPublicKey = `cat $szPublicKeyFile`;
print "PublicKey: $szPublicKey\n";

my $szConfFileName = "/etc/wireguard/wg0.conf"; 
my $szWgConf = `cat $szConfFileName`;

print "Wireguard setup:\n";

#print "$szWgConf\n";

open(my $fh, '<', '/etc/wireguard/wg0.conf') or die $!;
my @lines = <$fh>;
close($fh);

chomp @lines;

foreach my $line (@lines) {

	if ($line =~ /^#/) {	
		#comment, do nothing
	} else {
	    print "$line\n";

	}

}

my $getIpUrl = "http://81.88.19.252/taraSecWan.php?f=register&pl=1&nick=dummy&publ=ASDFTERTERYasdfNEW";

my $szIp = `curl -sS $getIpUrl`;
print "Ip: $szIp\n";