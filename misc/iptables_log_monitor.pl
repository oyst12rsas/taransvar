#!/usr/bin/perl
# Monitor local TaraSec firewall messages already written to the local journal/syslog.
# Remote security telemetry should arrive through rsyslog TCP/5514 on the DB server
# and be relayed to taralink UDP/514 for semantic parsing.

use strict;
use warnings;
use lib ('/root/taransvar/perl');
use DBI;
use func;

my $b_local_syslog = ($ARGV[0] && $ARGV[0] eq 'local') ? 1 : 0;
my $LOG_PREFIX = 'TARASEC_';
my $LOCAL_COLLECTOR_IP = '127.0.0.1';

sub log_monitor
{
    $| = 1;
    print "Starting local TaraSec iptables log monitor...\n";

    while (1)
    {
        my $fh;
        if ($b_local_syslog) {
            print "Opening journalctl...\n";
            open($fh, '-|', 'journalctl', '-kf') or do {
                warn "Cannot read journal: $!\n";
                sleep 5;
                next;
            };
        } else {
            print "Reading /var/log/syslog...\n";
            open($fh, '-|', 'tail', '-F', '/var/log/syslog') or do {
                warn "Cannot read syslog: $!\n";
                sleep 5;
                next;
            };
        }

        my $dbh = getConnection();
        my $sthSyslog = $dbh->prepare(
            "insert into syslog (senderIp, senderPort, rawmessage) values (inet_aton(?),0,?)"
        );
        my $sthThreat = $dbh->prepare(
            "insert into syslogThreat (syslogId, src_ip, src_port, dst_ip, dst_port, protocol, service) " .
            "values (?, inet_aton(?), ?, inet_aton(?), ?, ?, 'iptables')"
        );

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

            # senderIp means the system/sensor that supplied the log record.
            # Do not put SRC= here: SRC= is the network actor and belongs in syslogThreat.src_ip.
            $sthSyslog->execute($LOCAL_COLLECTOR_IP, $line)
                or die "execution failed: ".$sthSyslog->errstr()."\n";
            my $id = $dbh->last_insert_id(undef, undef, undef, undef);

            $sthThreat->execute(
                $id,
                $src,
                defined $spt ? $spt : 0,
                $dst,
                defined $dpt ? $dpt : 0,
                defined $proto ? $proto : '?'
            ) or die "execution failed: ".$sthThreat->errstr()."\n";

            print "Local TaraSec event added to syslog/syslogThreat\n";
        }

        $sthSyslog->finish;
        $sthThreat->finish;
        $dbh->disconnect;
        close($fh);
        warn "Log stream ended; retrying in 2 seconds...\n";
        sleep 2;
    }
}

log_monitor();
