use strict;
use warnings;
use FindBin;
use lib "$FindBin::Bin/../misc";
use lib_retention qw(read_policy retention_plan);
use Test::More;
use File::Temp qw(tempfile);

my $cfg = read_policy(undef);
is($cfg->{TELEMETRY_DAYS}, 30, 'recent telemetry retained');
is($cfg->{THREAT_DAYS}, 90, 'longer threat history');
for my $text ("TELEMETRY_DAYS=0\n", "THREAT_DAYS=2\n", "BATCH_ROWS=999999\n",
    "ENABLED=yes\n", "TYPO=1\n") {
    my ($fh, $path) = tempfile();
    print $fh $text; close $fh;
    eval { read_policy($path) };
    ok($@, "reject invalid policy $text");
    unlink $path;
}
my %columns = (
    traffic => {trafficId => 1, created => 1, lastSeen => 1},
    syslog => {syslogId => 1, created => 1},
    syslogThreat => {syslogThreatId => 1, syslogId => 1, created => 1, handled => 1, demoSshSessionId => 1},
    demoSshSession => {nodeAEvidenceId => 1, nodeBEvidenceId => 1},
    demoSshEvent => {syslogThreatId => 1},
    partnerObservationLocalEvidence => {syslogId => 1},
    managerRequest => {created => 1}, unit => {created => 1}, hackReport => {created => 1},
);
my $plan = retention_plan(\%columns, $cfg, [{schema=>'taransvar',table=>'customEvidence',
    target=>'traffic',pairs=>[['flow','trafficId']]}]);
is(scalar @$plan, 3, 'identity and incident tables excluded');
my %p = map { $_->{table} => $_ } @$plan;
like($p{traffic}{where}, qr/lastSeen.*IS NULL/, 'active flows protected');
like($p{traffic}{where}, qr/customEvidence/, 'owner-added references protected');
like($p{syslogThreat}{where}, qr/handled.*b'1'/, 'pending threats protected');
like($p{syslogThreat}{where}, qr/demoSshSessionId.*IS NULL/, 'demo membership protected');
like($p{syslog}{where}, qr/partnerObservationLocalEvidence/, 'observation evidence protected');
delete $columns{demoSshEvent}{syslogThreatId};
eval { retention_plan(\%columns, $cfg, []) };
ok($@, 'malformed known evidence table fails closed');
done_testing;
