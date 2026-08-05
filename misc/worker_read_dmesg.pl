#!/usr/bin/perl 
use strict; 
use warnings; 
use IO::Socket::INET; 
use DBI; 
use lib ('/root/taransvar/perl'); 
use func; #NOTE! See comment above regarding lib..

sub connect_db
{
    my $dbh = getConnection();

    $dbh->{RaiseError} = 1;
    $dbh->{PrintError} = 0;
    $dbh->{AutoCommit} = 1;

    my $sth = $dbh->prepare(
        "INSERT INTO dmesg (txt) VALUES (?)"
    );

    return ($dbh, $sth);
}

my ($dbh, $sth) = connect_db();

open(my $fh, "-|", "dmesg", "-w")
    or die "Cannot start dmesg: $!";

while (my $line = <$fh>) {
    my $ndx = index($line, "tarakernel:");
    next if $ndx < 0;

    my $szTxt = substr($line, $ndx + length("tarakernel:"));
    $szTxt =~ s/^\s+//;
    chomp $szTxt;
    $szTxt = substr($szTxt, 0, 254);

    my $inserted = 0;

    for my $attempt (1 .. 2) {
        my $ok = eval {
            # Detect a stale connection before executing.
            if (!$dbh->ping()) {
                die "Database ping failed";
            }

            $sth->execute($szTxt);
            1;
        };

        if ($ok) {
            $inserted = 1;
            last;
        }

        my $error = $@ || $dbh->errstr || "Unknown database error";
        warn "Database insert failed, attempt $attempt: $error\n";

        eval { $sth->finish() if $sth };
        eval { $dbh->disconnect() if $dbh };

        sleep 2;

        eval {
            ($dbh, $sth) = connect_db();
            1;
        } or do {
            warn "Database reconnect failed: $@\n";
            sleep 5;
        };
    }

    warn "Could not store dmesg line after reconnect\n"
        unless $inserted;
}

$sth->finish() if $sth;
$dbh->disconnect() if $dbh;