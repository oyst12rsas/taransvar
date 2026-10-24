package receiver_event_policy;
use strict;
use warnings;
use Exporter 'import';
our @EXPORT_OK = qw(is_receiver_rejection receiver_report_category);

sub is_receiver_rejection {
    my ($service, $description, $is_attack) = @_;
    # Older normalized rows have is_attack=1 even for lifecycle events.
    # Keep their evidence in the archive/DB, but never notify a source router.
    if (($service // '') =~ /^(?:ssh|cowrie)$/) {
        return 0 if ($description // '') =~ /^(?:ssh_session_connect|ssh_client_version|ssh_session_closed|ssh_demo_validation_error|ssh_honeypot_start|cowrie\.session\.connect|cowrie\.client\.version|cowrie\.session\.closed)(?:\s|$)/;
    }
    return !defined($is_attack) || $is_attack != 0;
}

sub receiver_report_category {
    my ($service, $description) = @_;
    return 'iptables' if ($service // '') =~ /^iptables?$/;
    # Accepted honeypot credentials are suspicious evidence, but not a rejection.
    return 'attack_severity_7' if ($description // '') =~ /^(?:ssh_login_success|cowrie\.login\.success)(?:\s|$)/;
    return 'ssh_fail';
}

1;
