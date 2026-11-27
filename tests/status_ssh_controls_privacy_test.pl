#!/usr/bin/perl
use strict;
use warnings;
use FindBin;
use File::Temp qw(tempfile);
open my $source, '<', "$FindBin::Bin/../misc/crontasks.pl" or die $!;
local $/; my $code = <$source>;
my ($routine) = $code =~ /(sub statusDeploymentMode\s*\{.*?\n\})/s;
die 'Cannot locate statusDeploymentMode' unless $routine;
eval $routine; die $@ if $@;
for my $case (["demo\n", 'demo'], [" Production \n", 'production'], ['', '']) {
	my ($fh, $path) = tempfile(); print $fh $case->[0]; close $fh;
	die "Mode mismatch for '$case->[0]'" unless statusDeploymentMode($path) eq $case->[1];
}
die 'Missing mode file must not count as demo' if statusDeploymentMode('/nonexistent/tarasec-mode') eq 'demo';
# The report must store the full local JSON but send sshCtl only in demo mode.
die 'Local status must keep sshCtl' unless $code =~ /my \$cLocalJson = encode_json\(\\%json\);\s*delete \$json\{"sshCtl"\} unless statusDeploymentMode\(\) eq "demo";\s*my \$cJson = encode_json/;
die 'Local store must use the unfiltered JSON' unless $code =~ /networkStatus = \?.*?execute\(\$cLocalJson\)/s;
print "SSH control status privacy checks passed\n";
