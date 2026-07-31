#!/usr/bin/perl
#Reads new entris to syslog (through rsyslog) or local syslog (journalctl)
#call with perl iptables_log_monitor.pl [local|remote]

#Used to be started by crontasks.pl. Now running as service... 
#sudo systemctl status iptables-log-monitor.service --no-pager

use strict;
use warnings;
#use IO::Socket::INET;

use lib ('/root/taransvar/perl');
#use lib ('.');
		
use DBI;
use func;	#NOTE! See comment above regarding lib..

my $b_local_syslog = 0; #rsyslog by default
#my $b_local_syslog = 1; #syslog by default

#if (#!$ARGV[0] || $ARGV[0] eq "local") 
if (0)
{
	$b_local_syslog = 1;
}

sub log_monitor
{
	my $LOG_PREFIX = "IPTABLES_";
	$LOG_PREFIX="TARASEC_";

# Better use rsyslog to transfer
#	my $UDP_TARGET = "127.0.0.1";
#	my $UDP_PORT   = 5551;
#	my $sock = IO::Socket::INET->new(
#		PeerAddr => $UDP_TARGET,
#		PeerPort => $UDP_PORT,
#		Proto    => 'udp'
#	) or die "Could not create UDP socket: $!";

	$| = 1;   # autoflush STDOUT

	print "Starting iptables log monitor...\n";

	while (1)
	{
		my $fh;

		if ($b_local_syslog)  {
			print "Opening journalctl...\n";
			open($fh, "-|", "journalctl", "-kf")
				or do {
					warn "Cannot read journal: $!\n";
					sleep 5;
					next;
				};

        } else {
			#Read rsyslog entries.
			print "Reading /var/log/syslog...\n";
			open($fh, "-|", "tail", "-F", "/var/log/syslog")
				or do {
				warn "Cannot read syslog: $!\n";
				sleep 5;
				next;
				};            
			open($fh, "-|", "tail", "-F", "/var/log/syslog");
		}

		print "Listening for iptables drops...\n";

		my $dbh = getConnection();
		my $szSQL = "insert into syslog (senderIp, senderPort, rawmessage) values (inet_aton(?),?,?)";
		my $sthSyslog = $dbh->prepare($szSQL);

		$szSQL = "insert into syslogThreat (syslogId, src_ip, src_port, dst_ip, dst_port, protocol) values (?, inet_aton(?), ?, inet_aton(?), ?, ?)";
		my $sthSyslogThreat = $dbh->prepare($szSQL);

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

			#You may insert the full (original) line instead
			$sthSyslog->execute($src, defined $spt ? $spt : 0, $msg) or die "execution failed: $sthSyslog->errstr()";
			#$sthSyslog->execute($line) or die "execution failed: $sthSyslog->errstr()";

			my $id = $dbh->last_insert_id(undef, undef, undef, undef);            

			$sthSyslogThreat->execute($id, $src, defined $spt ? $spt : 0, $dst, defined $dpt ? $dpt : 0, defined $proto ? $proto : '?');
			print "Record added in syslog and syslogThreat\n";
        }

		$sthSyslog->finish;
		$sthSyslogThreat->finish;
		$dbh->disconnect;

		close($fh);
		warn "journalctl ended; retrying in 2 seconds...\n";
		sleep 2;
    }
}

log_monitor();