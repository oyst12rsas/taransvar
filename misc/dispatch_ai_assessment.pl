#!/usr/bin/perl
use strict;
use warnings;
use Fcntl qw(:flock);

my $pending = '/run/tarasec-ai.pending';
my $lastRun = '/run/tarasec-ai.last';
my $lockFile = '/run/tarasec-ai-dispatch.lock';
my $cooldown = $ENV{TARASEC_AI_COOLDOWN_SEC} || 300;

exit 0 unless -s $pending;

open(my $lock, '>', $lockFile) or die "Cannot open $lockFile: $!\n";
exit 0 unless flock($lock, LOCK_EX | LOCK_NB);

my $now = time();
my $last = 0;
if (-e $lastRun) {
    open(my $lf, '<', $lastRun);
    my $v = <$lf>;
    close($lf);
    $last = int($v || 0);
}

if ($last && ($now - $last) < $cooldown) {
    print "AI assessment pending; cooldown has ".($cooldown - ($now - $last))." seconds left.\n";
    exit 0;
}

my $queued = '';
if (open(my $pf, '<', $pending)) {
    local $/;
    $queued = <$pf> || '';
    close($pf);
}

my $rc = system('/usr/bin/systemctl', 'start', 'tarasec-ai.service');
if ($rc == 0) {
    unlink $pending;
    open(my $lf, '>', $lastRun) or die "Cannot write $lastRun: $!\n";
    print $lf $now."\n";
    close($lf);
    print "Triggered TaraSec AI assessment for queued events:\n$queued";
} else {
    warn "Could not start tarasec-ai.service; pending trigger retained.\n";
}
