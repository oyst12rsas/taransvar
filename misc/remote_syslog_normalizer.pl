#!/usr/bin/perl
use strict;
use warnings;
use JSON qw(decode_json);
use Fcntl qw(SEEK_END);
use lib ('/root/taransvar/perl');
use func;

my $logFile = $ENV{TARASEC_REMOTE_LOG} || '/var/log/tarasec/remote.log';
my $trigger = '/root/taransvar/perl/queue_ai_assessment.pl';

sub ip_ok {
    my ($ip) = @_;
    return defined($ip) && $ip =~ /^(?:\d{1,3}\.){3}\d{1,3}$/;
}

sub parse_event {
    my ($program, $msg) = @_;
    my %e = ( service => 'other', description => '' );

    if ($msg =~ /SRC=([0-9.]+).*DST=([0-9.]+)/) {
        $e{src_ip} = $1;
        $e{dst_ip} = $2;
        ($e{src_port}) = $msg =~ /SPT=(\d+)/;
        ($e{dst_port}) = $msg =~ /DPT=(\d+)/;
        ($e{protocol}) = $msg =~ /PROTO=([A-Za-z0-9_+-]+)/;
        $e{service} = 'iptables';
        $e{is_attack} = ($msg =~ /(?:DROP|REJECT|DENY)/i) ? 1 : 0;
        $e{action} = $e{is_attack} ? 'deny' : '';
        $e{description} = 'remote rsyslog firewall event';
        return \%e;
    }

    if ($program eq 'cowrie' || $msg =~ /^\s*\{/) {
        my $j = eval { decode_json($msg) };
        if ($j && ref($j) eq 'HASH' && ip_ok($j->{src_ip}) && ip_ok($j->{dst_ip})) {
            $e{src_ip} = $j->{src_ip};
            $e{dst_ip} = $j->{dst_ip};
            $e{src_port} = int($j->{src_port} || 0);
            $e{dst_port} = int($j->{dst_port} || 0);
            $e{protocol} = $j->{proto} || 'tcp';
            $e{service} = 'cowrie';
            $e{is_attack} = 1;
            $e{action} = $j->{action} || 'observe';
            $e{severity} = int($j->{severity} || 0);
            $e{description} = join(' ', grep { defined && length } ($j->{event}, $j->{sensor}, $j->{session}));
            return \%e;
        }
    }

    return \%e;
}

sub queue_ai {
    my ($reason) = @_;
    return unless -x $trigger || -f $trigger;
    system('/usr/bin/perl', $trigger, $reason);
}

sub handle_line {
    my ($line) = @_;
    chomp $line;
    return unless length $line;

    my ($sensor, $host, $program, $msg) =
        $line =~ /\bTARASEC_SENSOR_IP=([^ ]+)\s+HOST=([^ ]*)\s+PROGRAM=([^ ]*)\s+MSG=(.*)$/;
    return unless ip_ok($sensor) && defined $msg;

    my $dbh = getConnection();
    my $sth = $dbh->prepare(
        'insert into syslog (senderIp, senderPort, hostname, tag, message, rawmessage, isSyslog) '.
        'values (inet_aton(?), 5514, ?, ?, ?, ?, 1)'
    );
    $sth->execute($sensor, $host || '', $program || '', $msg, $line);
    my $syslogId = $dbh->{mysql_insertid};
    $sth->finish();

    my $e = parse_event($program || '', $msg);
    if (ip_ok($e->{src_ip}) && ip_ok($e->{dst_ip})) {
        my $threat = $dbh->prepare(
            'insert into syslogThreat '.
            '(syslogId,is_attack,action,src_ip,src_port,dst_ip,dst_port,protocol,service,description,severity) '.
            'values (?,?,?,inet_aton(?),?,inet_aton(?),?,?,?,?,?)'
        );
        $threat->execute(
            $syslogId, int($e->{is_attack} || 0), $e->{action} || '',
            $e->{src_ip}, int($e->{src_port} || 0), $e->{dst_ip}, int($e->{dst_port} || 0),
            $e->{protocol} || '', $e->{service} || 'other', $e->{description} || '', int($e->{severity} || 0)
        );
        $threat->finish();

        if (($e->{severity} || 0) >= 7 || ($e->{service} || '') eq 'cowrie') {
            queue_ai('remote_'.$e->{service});
        }
    }

    $dbh->disconnect();
}

$| = 1;
print "Watching $logFile for reliable remote telemetry...\n";

while (!-e $logFile) {
    warn "$logFile does not exist yet; retrying...\n";
    sleep 5;
}

open(my $fh, '<', $logFile) or die "Cannot open $logFile: $!\n";
seek($fh, 0, SEEK_END);

while (1) {
    my $line = <$fh>;
    if (defined $line) {
        eval { handle_line($line); 1 } or warn "remote syslog normalize failed: $@\n";
        next;
    }
    clearerr($fh);
    sleep 1;
}
