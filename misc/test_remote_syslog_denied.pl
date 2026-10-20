#!/usr/bin/perl
use strict;
use warnings;
use Test::More;
use FindBin;
open my $fh, '<', "$FindBin::Bin/remote_syslog_normalizer.pl" or die $!;
local $/;
my $source = <$fh>;
my ($routine) = $source =~ /(sub parse_event\s*\{.*?)(?=\nsub queue_ai)/s;
die 'Cannot locate parser' unless $routine;
eval $routine;
die $@ if $@;
for my $label (qw(DROP REJECT DENY DENIED)) {
    my $event = parse_event('kernel', "TARASEC_SSH_$label: SRC=100.68.176.110 DST=100.68.165.190 PROTO=TCP SPT=60414 DPT=5822");
    is($event->{is_attack}, 1, "$label classified as rejection");
    is($event->{action}, 'deny', "$label records deny action");
}
my $probe = parse_event('kernel', 'TARASEC_PROBE: SRC=100.68.10.7 DST=100.68.108.143 PROTO=TCP SPT=55936 DPT=4200');
is($probe->{is_attack}, 0, 'Probe alone is not classified as rejection');
done_testing();
