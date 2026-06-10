#!/usr/bin/perl
use strict;
use warnings;
use IO::Socket::INET;
use DBI;
use lib ('/root/taransvar/perl');
use func;	#NOTE! See comment above regarding lib..

use Fcntl qw(:flock);
use File::Basename;
my $script = basename($0);
my $lockfile = "/tmp/$script.lock";

open(my $lockfh, ">", $lockfile)
    or die "Cannot open lock file: $!";

unless (flock($lockfh, LOCK_EX | LOCK_NB)) {
    print "Already running. Aborting.\n";
    exit 0;
}

print $lockfh "$$\n";
$lockfh->flush() if $lockfh->can("flush");


#log_iptables_drops_to_dbserver.pl (started by crontab)

my $dbh = getConnection();
my $sth = $dbh->prepare("insert into dmesg (txt) value (?)");

open(my $fh, "-|", "dmesg", "-w")
	or die "Cannot start dmesg: $!";

while (my $line = <$fh>) {
    my $ndx = index($line, "tarakernel:");

    if ($ndx >= 0) {
        my $szTxt = substr($line, $ndx + length("tarakernel:"));
        $szTxt =~ s/^\s+//;
        chomp $szTxt;

        #print "$szTxt\n";
        $sth->execute($szTxt) or die "execution failed: " . $sth->errstr;
    }
}

print "Script ending\n";
$sth->finish;
