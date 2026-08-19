#!/usr/bin/perl
use strict;
use warnings;
use POSIX qw(strftime);

my $pending = '/run/tarasec-ai.pending';
my $reason = join(' ', @ARGV);
$reason = 'unspecified' unless length $reason;
$reason =~ s/[\r\n]+/ /g;

open(my $fh, '>>', $pending) or die "Cannot write $pending: $!\n";
print $fh strftime('%Y-%m-%dT%H:%M:%SZ', gmtime())."\t$reason\n";
close($fh);

print "Queued TaraSec AI assessment: $reason\n";
