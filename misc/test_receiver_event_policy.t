use strict;
use warnings;
use Test::More;
use FindBin;
use lib $FindBin::Bin;
use receiver_event_policy qw(is_receiver_rejection receiver_report_category);

for my $event (qw(ssh_session_connect ssh_client_version ssh_session_closed ssh_demo_validation_error ssh_honeypot_start cowrie.session.connect cowrie.client.version cowrie.session.closed)) {
    ok(!is_receiver_rejection('ssh', "$event sensor session", 1), "$event is informational even in historical rows");
}
ok(is_receiver_rejection('ssh', 'ssh_login_failed sensor session', 1), 'failed login is reportable');
ok(is_receiver_rejection('cowrie', 'cowrie.login.failed session', 1), 'cowrie rejection retained');
ok(is_receiver_rejection('ssh', 'ssh_login_success sensor session', 1), 'honeypot acceptance remains reportable');
ok(!is_receiver_rejection('iptables', 'firewall observation', 0), 'non-attack firewall record is not reported');
ok(is_receiver_rejection('iptables', 'SSH access policy denial', 1), 'firewall denial retained');
ok(is_receiver_rejection('ssh', 'legacy rejection', undef), 'legacy unspecified attack flag remains compatible');
is(receiver_report_category('ssh', 'ssh_login_failed sensor'), 'ssh_fail', 'failed login category');
is(receiver_report_category('ssh', 'ssh_login_success sensor'), 'attack_severity_7', 'success is not mislabeled as failure');
is(receiver_report_category('cowrie', 'cowrie.login.success sensor'), 'attack_severity_7', 'cowrie success category');
is(receiver_report_category('iptables', 'denied'), 'iptables', 'firewall category retained');
done_testing();
