#!/usr/bin/perl
use strict;
use warnings;
use lib ('/root/taransvar/perl');
use DBI;
use JSON::PP qw(decode_json encode_json);
use File::Path qw(make_path);
use func;

my $state_dir = '/var/lib/tarasec';
my $state_file = "$state_dir/ai-evidence-watch.json";
my $queue_script = '/root/taransvar/perl/queue_ai_assessment.pl';

make_path($state_dir, { mode => 0750 }) unless -d $state_dir;

my $state = {
    owner_confirmed_epoch => 0,
    severe_threat_id => 0,
    correlation_threat_id => 0,
};

if (-r $state_file) {
    eval {
        open(my $fh, '<', $state_file) or die $!;
        local $/;
        my $raw = <$fh> // '';
        close($fh);
        my $saved = decode_json($raw);
        $state->{$_} = $saved->{$_} // $state->{$_} for keys %$state;
        1;
    } or warn "Could not read $state_file: $@\n";
}

sub queue_ai {
    my ($reason) = @_;
    $reason =~ s/[\r\n]+/ /g;
    if (-x $queue_script || -f $queue_script) {
        my $rc = system('/usr/bin/perl', $queue_script, $reason);
        warn "Could not queue AI assessment for: $reason\n" if $rc != 0;
    } else {
        warn "AI queue script missing: $queue_script\n";
    }
}

my $dbh = getConnection();

my $confirmed = $dbh->prepare(q{
    SELECT reportId, UNIX_TIMESTAMP(ownerConfirmedTime) AS confirmed_epoch,
           remoteUnitId, hrCategory, severity
      FROM hackReport
     WHERE ownerConfirmedTime IS NOT NULL
       AND UNIX_TIMESTAMP(ownerConfirmedTime) > ?
     ORDER BY ownerConfirmedTime, reportId
     LIMIT 100
});
$confirmed->execute($state->{owner_confirmed_epoch});
while (my $row = $confirmed->fetchrow_hashref()) {
    my $unit = defined $row->{remoteUnitId} ? $row->{remoteUnitId} : 'unknown';
    my $category = $row->{hrCategory} // 'unknown';
    my $severity = $row->{severity} // 0;
    queue_ai("owner-confirmed hack report=$row->{reportId} unit=$unit category=$category severity=$severity");
    $state->{owner_confirmed_epoch} = $row->{confirmed_epoch}
        if ($row->{confirmed_epoch} // 0) > $state->{owner_confirmed_epoch};
}
$confirmed->finish();

my $severe = $dbh->prepare(q{
    SELECT syslogThreatId, owner_id,
           COALESCE(confirmed_unit_id, unit_id) AS effective_unit_id,
           severity, service, INET_NTOA(dst_ip) AS dst, dst_port
      FROM syslogThreat
     WHERE syslogThreatId > ?
       AND severity >= 7
     ORDER BY syslogThreatId
     LIMIT 100
});
$severe->execute($state->{severe_threat_id});
while (my $row = $severe->fetchrow_hashref()) {
    my $owner = defined $row->{owner_id} ? $row->{owner_id} : 'unknown';
    my $unit = defined $row->{effective_unit_id} ? $row->{effective_unit_id} : 'unknown';
    my $service = $row->{service} // 'unknown';
    queue_ai("high-severity telemetry threat=$row->{syslogThreatId} owner=$owner unit=$unit severity=$row->{severity} service=$service dst=".($row->{dst}//'?').":".($row->{dst_port}//0));
    $state->{severe_threat_id} = $row->{syslogThreatId}
        if $row->{syslogThreatId} > $state->{severe_threat_id};
}
$severe->finish();

my $corr = $dbh->prepare(q{
    SELECT INET_NTOA(dst_ip) AS dst, dst_port, protocol,
           COUNT(DISTINCT CONCAT(owner_id, ':', COALESCE(confirmed_unit_id, unit_id))) AS unit_count,
           COUNT(DISTINCT owner_id) AS owner_count,
           MAX(syslogThreatId) AS max_threat_id
      FROM syslogThreat
     WHERE created >= NOW() - INTERVAL 5 MINUTE
       AND owner_id IS NOT NULL
       AND COALESCE(confirmed_unit_id, unit_id) IS NOT NULL
     GROUP BY dst_ip, dst_port, protocol
    HAVING unit_count >= 2
       AND owner_count >= 2
       AND max_threat_id > ?
     ORDER BY max_threat_id
     LIMIT 100
});
$corr->execute($state->{correlation_threat_id});
while (my $row = $corr->fetchrow_hashref()) {
    my $proto = $row->{protocol} // '?';
    queue_ai("cross-owner correlation owners=$row->{owner_count} units=$row->{unit_count} dst=".($row->{dst}//'?').":".($row->{dst_port}//0)." protocol=$proto newestThreat=$row->{max_threat_id}");
    $state->{correlation_threat_id} = $row->{max_threat_id}
        if $row->{max_threat_id} > $state->{correlation_threat_id};
}
$corr->finish();

$dbh->disconnect();

my $tmp = "$state_file.$$";
open(my $out, '>', $tmp) or die "Cannot write $tmp: $!\n";
print $out encode_json($state);
close($out);
chmod 0640, $tmp;
rename($tmp, $state_file) or die "Cannot replace $state_file: $!\n";
