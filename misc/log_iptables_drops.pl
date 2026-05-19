#!/usr/bin/perl
use strict;
use warnings;
use DBI;

#log_iptables_drops.pl (started by crontab)

my $dbh = getConnection();

#my $dbh = DBI->connect("DBI:mysql:database=taransvar;host=127.0.0.1",
#    "dbuser",     "dbpass",    { RaiseError => 1, AutoCommit => 1, mysql_enable_utf8mb4 => 1 } );

my $insert = $dbh->prepare(q{
    INSERT INTO syslogThreat
    (src_ip, src_port, dst_ip, dst_port, protocol)
    VALUES (inet_aton(?), ?, inet_aton(?), ?, ?)
});

open(my $fh, "-|", "journalctl", "-k", "-f")
    or die "Cannot start journalctl: $!";

while (my $line = <$fh>) {

    next unless $line =~ /DROP_INPUT:|DROP_FORWARD:|DROP_OUTPUT:/;

    chomp $line;

    print "$line\n";

    my %f;

    while ($line =~ /\b([A-Z]+)=([^\s]+)/g) {
        $f{$1} = $2;
    }

    print "SRC=$f{SRC} "
        . "DST=$f{DST} "
        . "PROTO=$f{PROTO} "
        . "SPT=$f{SPT} "
        . "DPT=$f{DPT} "
        . "IN=$f{IN} "
        . "OUT=$f{OUT}\n";

    $insert->execute(
        $f{SRC},
        $f{SPT},
        $f{DST},
        $f{DPT},
        $f{PROTO}
        );

}




