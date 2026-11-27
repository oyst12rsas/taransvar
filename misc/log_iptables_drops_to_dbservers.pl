#!/usr/bin/perl
# log_iptables_drops_to_dbservers.pl (started by cron_sinkhole.pl)
#
# Reads kernel DROP_INPUT/DROP_FORWARD/DROP_OUTPUT lines and writes one
# TARASEC_SINKHOLE line per drop to the local syslog. The rsyslog forwarder
# (misc/rsyslog/30-tarasec-forward.conf.example, installed by
# misc/configure_global_db.sh) sends every TARASEC_ line to the DB server over
# TCP/5514, where remote_syslog_normalizer.pl turns it into a syslogThreat row
# with service "iptables". The DB server identifies this node by its real peer
# address, so no node name or server address is configured here.
#
# This script no longer sends UDP/514 itself: UDP senders can be spoofed and
# taralink's UDP parser does not record the real sender.
use strict;
use warnings;
use Sys::Syslog qw(openlog syslog closelog);

# --stdin: read kernel lines from STDIN and only print (used by tests).
my $test_mode = (@ARGV && $ARGV[0] eq '--stdin');

my $fh;
if ($test_mode) {
	$fh = \*STDIN;
} else {
	openlog('tarasec-sinkhole', 'pid', 'local0');
	open($fh, '-|', 'journalctl', '-k', '-f', '-n', '0')
		or die "Cannot start journalctl: $!";
}

$| = 1;

while (my $line = <$fh>) {
	my ($chain) = $line =~ /\b(DROP_INPUT|DROP_FORWARD|DROP_OUTPUT):/;
	next unless $chain;

	my %f;
	while ($line =~ /\b([A-Z]+)=([^\s]*)/g) {
		$f{$1} = $2;
	}
	next unless ($f{SRC} // '') =~ /^[0-9.]+$/ && ($f{DST} // '') =~ /^[0-9.]+$/;

	my $msg = "TARASEC_SINKHOLE $chain"
		. " SRC="   . $f{SRC}
		. " DST="   . $f{DST}
		. " PROTO=" . ($f{PROTO} // '')
		. " SPT="   . ($f{SPT}   // '')
		. " DPT="   . ($f{DPT}   // '')
		. " IN="    . ($f{IN}    // '')
		. " OUT="   . ($f{OUT}   // '');
	$msg =~ s/[^\x20-\x7e]//g;

	syslog('warning', '%s', $msg) unless $test_mode;
	print "$msg\n";
}

closelog() unless $test_mode;
