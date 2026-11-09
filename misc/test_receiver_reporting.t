use strict;
use warnings;
use Test::More;
use FindBin;
use File::Temp qw(tempdir);
use Fcntl qw(:flock);
use lib $FindBin::Bin;
use receiver_event_policy qw(is_receiver_rejection receiver_report_category);
use JSON::PP qw(decode_json encode_json);

# Exercise production parsing/delivery functions without loading cron startup,
# executing commands or connecting to a real database/router.
sub load_sub {
    my ($file, $name, $rewrite) = @_;
    open(my $fh, '<', "$FindBin::Bin/$file") or die $!;
    local $/; my $source = <$fh>;
    my ($code) = $source =~ /(^sub \Q$name\E\b.*?)(?=^sub |^\$\|)/ms;
    die "Cannot extract $name" unless $code;
    $code = $rewrite->($code) if $rewrite;
    eval $code; die $@ if $@;
}
load_sub('remote_syslog_normalizer.pl', 'ip_ok');
load_sub('remote_syslog_normalizer.pl', 'parse_event');
load_sub('crontasks.pl', 'handle_syslogThreat_record');

my @sql;
my @deliveries;
my $delivered = 1;
sub get_unit_from_conntrack { return 0; }
sub trySendWarningToRouter { push @deliveries, $_[0]; return $delivered; }
{
    package FakeDB;
    sub prepare { push @sql, $_[1]; return bless {}, 'FakeStatement'; }
    package FakeStatement;
    sub execute { return 1; }
    sub finish { return 1; }
    sub fetchrow_hashref { return undef; }
}
my $db = bless {}, 'FakeDB';
my %context = (src_ip=>'192.0.2.10', dst_ip=>'198.51.100.20', src_port=>40000, dst_port=>22, proto=>'tcp', service=>'ssh', sensor=>'tarasec-ssh-lite', session=>'test');
for my $event (qw(ssh_session_connect ssh_client_version ssh_session_closed ssh_login_failed ssh_login_success)) {
    my $parsed = parse_event('tarasec-ssh-honeypot', encode_json({%context, event=>$event, severity=>4}));
    is($parsed->{is_attack}, $event =~ /^ssh_login_/ ? 1 : 0, "$event normalization");
    my %row = (%$parsed, syslogThreatId=>1, src=>$context{src_ip}, dst=>$context{dst_ip});
    @sql=(); @deliveries=(); $delivered=1;
    handle_syslogThreat_record($db, \%row);
    is(scalar @deliveries, $event =~ /^ssh_login_/ ? 1 : 0, "$event delivery policy");
}
my %failed = (%context, src=>$context{src_ip}, dst=>$context{dst_ip}, protocol=>'tcp', description=>'ssh_login_failed test', is_attack=>1, syslogThreatId=>2);
@sql=(); @deliveries=(); $delivered=0;
handle_syslogThreat_record($db, \%failed);
is(scalar @deliveries, 1, 'failed delivery attempted');
is(scalar @sql, 0, 'failed delivery remains pending');
$delivered=1;
handle_syslogThreat_record($db, \%failed);
like($sql[-1], qr/handled = b'1'/, 'acknowledged delivery is marked handled');
# Historical normalizer output must also be filtered before conntrack/reporting.
@sql=(); @deliveries=();
handle_syslogThreat_record($db, {%failed, description=>'ssh_session_closed old', is_attack=>1});
is(scalar @deliveries, 0, 'historical lifecycle event never delivered');
like($sql[-1], qr/Informational receiver event/, 'historical event retired without deleting evidence');
my $run = tempdir(CLEANUP=>1);
load_sub('crontasks.pl', 'handle_syslogThreat_table', sub {
    my ($code) = @_;
    $code =~ s{/run/tarasec}{$run}g;
    return $code;
});
open(my $held, '>>', "$run/receiver-reports.lock") or die $!;
flock($held, LOCK_EX | LOCK_NB) or die $!;
@sql=();
handle_syslogThreat_table($db);
is(scalar @sql, 0, 'another batch owner prevents concurrent DB/delivery work');
close($held);
handle_syslogThreat_table($db);
like($sql[0], qr/order by t.syslogThreatId asc limit 100/, 'oldest pending records selected once lock is released');
my $queries = scalar @sql;
handle_syslogThreat_table($db);
cmp_ok(scalar @sql, '>', $queries, 'batch lock released after completion');
done_testing();
