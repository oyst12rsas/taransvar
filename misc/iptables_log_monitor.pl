#!/usr/bin/perl
#Receives iptables log messages to UDP port 5551
use strict;
use warnings;
use IO::Socket::INET;

use lib ('/root/taransvar/perl');
#use lib ('.');
		
use DBI;
use func;	#NOTE! See comment above regarding lib..


sub log_monitor
{
    my $LOG_PREFIX = "IPTABLES_";
    my $UDP_TARGET = "127.0.0.1";
    my $UDP_PORT   = 5551;

    my $sock = IO::Socket::INET->new(
        PeerAddr => $UDP_TARGET,
        PeerPort => $UDP_PORT,
        Proto    => 'udp'
    ) or die "Could not create UDP socket: $!";

    $| = 1;   # autoflush STDOUT

    print "Starting iptables log monitor...\n";

    while (1)
    {
        print "Opening journalctl...\n";

        open(my $fh, "-|", "journalctl", "-kf")
            or do {
                warn "Cannot read journal: $!\n";
                sleep 5;
                next;
            };

        print "Listening for iptables drops...\n";

        while (my $line = <$fh>)
        {
            chomp $line;

            next unless $line =~ /\Q$LOG_PREFIX\E/;

            my ($src)   = $line =~ /SRC=([0-9.]+)/;
            my ($dst)   = $line =~ /DST=([0-9.]+)/;
            my ($spt)   = $line =~ /SPT=(\d+)/;
            my ($dpt)   = $line =~ /DPT=(\d+)/;
            my ($proto) = $line =~ /PROTO=(\w+)/;

            next unless defined $src && defined $dst;

            my $msg = sprintf(
                "DROP %s %s:%s -> %s:%s",
                defined $proto ? $proto : '?',
                $src,
                defined $spt ? $spt : '?',
                $dst,
                defined $dpt ? $dpt : '?'
            );

            print "LOG: $msg\n";

            #my $ok = $sock->send($msg);
            #warn "UDP send failed: $!\n" unless defined $ok;

            my $dbh = getConnection();
        	my $szSQL = "insert into syslog (senderIp, senderPort, rawmessage) values (0,0,?)";
        	my $sth = $dbh->prepare($szSQL);
	        $sth->execute($msg) or die "execution failed: $sth->errstr()";
        	$sth->finish;
            my $id = $dbh->last_insert_id(undef, undef, undef, undef);            

            $szSQL = "insert into syslogThreat (syslogId, src_ip, src_port, dst_ip, dst_port, protocol) values (?, inet_aton(?), ?, inet_aton(?), ?, ?)";
            $sth = $dbh->prepare($szSQL);
            $sth->execute($id, $src, defined $spt ? $spt : 0, $dst, defined $dpt ? $dpt : 0, defined $proto ? $proto : '?');
            print "Record added in syslog and syslogThreat\n";
        	$sth->finish;
            $dbh->disconnect;

        }

        close($fh);
        warn "journalctl ended; retrying in 2 seconds...\n";
        sleep 2;
    }
}

log_monitor();