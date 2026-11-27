#!/bin/bash
# Install or refresh the TaraSec manager verification worker
# (tarasec-manager-requests.timer, every minute). Used by the managed daily
# upgrade ("manager-requests" component) and by setup_node_app_services.sh.
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUNTIME_DIR=/root/taransvar/perl
SYSTEMD_DIR=/etc/systemd/system
[[ $EUID -eq 0 ]] || { echo "Run with sudo: sudo bash misc/setup_manager_requests.sh" >&2; exit 1; }
[[ -f $RUNTIME_DIR/func.pm ]] || { echo "TaraSec runtime ($RUNTIME_DIR/func.pm) is not installed." >&2; exit 1; }
perl -c "$ROOT_DIR/manager_requests.pl" >/dev/null
install -m 0755 "$ROOT_DIR/manager_requests.pl" "$RUNTIME_DIR/manager_requests.pl"
install -m 0644 "$ROOT_DIR/systemd/tarasec-manager-requests.service" "$SYSTEMD_DIR/tarasec-manager-requests.service"
install -m 0644 "$ROOT_DIR/systemd/tarasec-manager-requests.timer" "$SYSTEMD_DIR/tarasec-manager-requests.timer"
# Read the effective setting the same way as manager_requests.pl.
(
    umask 077
    perl - /etc/tarasec.conf <<'PERL'
use strict;
use warnings;
use Fcntl qw(LOCK_EX SEEK_SET);

my ($path) = @ARGV;
open(my $config, '+>>', $path) or die "Cannot open $path: $!\n";
flock($config, LOCK_EX) or die "Cannot lock $path: $!\n";
seek($config, 0, SEEK_SET) or die "Cannot read $path: $!\n";

my ($url, $last_line) = ('', '');
while (my $line = <$config>) {
    $last_line = $line;
    $line =~ s/^\s+|\s+$//g;
    next unless $line =~ /^MAIL_SERVICE_URL\s*=\s*(.*)$/;
    $url = $1;
    $url =~ s/^['"]|['"]$//g;
}

if ($url eq '') {
    my $separator = $last_line ne '' && $last_line !~ /\n$/ ? "\n" : '';
    print {$config} "${separator}MAIL_SERVICE_URL=http://100.68.164.58:8088/send.php\n"
        or die "Cannot write $path: $!\n";
}
close($config) or die "Cannot close $path: $!\n";
PERL
)
systemctl daemon-reload
systemctl enable --now tarasec-manager-requests.timer
echo "Manager verification worker installed: tarasec-manager-requests.timer"
