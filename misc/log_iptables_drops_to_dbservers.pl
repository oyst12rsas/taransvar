#!/usr/bin/perl
use strict;
use warnings;
use IO::Socket::INET;
use lib ('/root/taransvar/perl');
use func;	#NOTE! See comment above regarding lib..

#log_iptables_drops_to_dbserver.pl (started by crontab)

my @cDbServers = ("10.47.255.11","10.47.14.15");

my $remote_port = 514;

my %sockets;

foreach my $server (@cDbServers) {

    $sockets{$server} = IO::Socket::INET->new(
        PeerAddr => $server,
        PeerPort => $remote_port,
        Proto    => 'udp'
    ) or die "socket $server: $!";
}

open(my $fh, "-|", "journalctl", "-k", "-f")
	or die "Cannot start journalctl: $!";

while (my $line = <$fh>) {
    use POSIX qw(strftime);
    my $ts = strftime("%Y-%m-%d %H:%M:%S", localtime);

	next unless $line =~ /DROP_INPUT:|DROP_FORWARD:|DROP_OUTPUT:/;

	chomp $line;

	print "$line\n";

	my %f;

	while ($line =~ /\b([A-Z]+)=([^\s]+)/g) {
		$f{$1} = $2;
	}

    my $msg = "<4> $ts FROM=node14 DESC=sinkhole "
        . "SRC="   . ($f{SRC}   // '') . " "
        . "DST="   . ($f{DST}   // '') . " "
        . "PROTO=" . ($f{PROTO} // '') . " "
        . "SPT="   . ($f{SPT}   // '') . " "
        . "DPT="   . ($f{DPT}   // '') . " "
        . "IN="    . ($f{IN}    // '') . " "
        . "OUT="   . ($f{OUT}   // '');

    print "$msg\nSending UDP port $remote_port. ";
    foreach my $server (@cDbServers) {
        print "Sending to $server. ";
        $sockets{$server}->send($msg);
        #Send message to UDP 514
    }
    print "Sent\n\n";
}




