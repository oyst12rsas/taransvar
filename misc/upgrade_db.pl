#!/usr/bin/perl
# Apply reviewed additive TaraSec schema migrations on the local database.
use strict;
use warnings;
use FindBin;
use DBI;

my $sql_file = "$FindBin::Bin/install.sql";
open my $fh, '<', $sql_file or die "Cannot read $sql_file: $!\n";
my (%migration, $version);
while (my $line = <$fh>) {
    if ($line =~ /^#version\s+(\d+)\b/) {
        $version = $1 + 0;
        next;
    }
    next unless defined $version;
    next if $line =~ /^\s*(?:#|--|$)/;
    $migration{$version} .= $line;
}
close $fh;

my $latest = (sort { $b <=> $a } keys %migration)[0]
    or die "No numbered SQL migrations found in $sql_file\n";

# The reviewed range is additive, widens types, or adds demo metadata.
# Future versions must be reviewed before enabling unattended execution.
my $reviewed_through = 98;
my $dbh = DBI->connect(
    'DBI:mysql:database=taransvar;host=localhost', 'root', '',
    { RaiseError => 1, PrintError => 0, AutoCommit => 1 }
) or die "Cannot connect to local taransvar DB as root\n";

my $lock = 'taransvar:schema-upgrade';
my ($acquired) = $dbh->selectrow_array('SELECT GET_LOCK(?, 15)', undef, $lock);
die "Could not acquire DB migration lock\n" unless $acquired && $acquired == 1;

my $ok = eval {
    my $rows = $dbh->selectall_arrayref('SELECT dbVersion FROM setup');
    die "Expected one setup row, found " . scalar(@$rows) . "\n"
        unless @$rows == 1 && defined $rows->[0][0];
    my $current = $rows->[0][0] + 0;
    die "DB version $current is newer than checkout version $latest\n"
        if $current > $latest;
    die "DB version $current predates the reviewed migration range (90-98)\n"
        if $current < 90 && $current != $latest;
    die "Checkout version $latest exceeds reviewed version $reviewed_through\n"
        if $latest > $reviewed_through && $current < $latest;

    for my $target (($current + 1) .. $latest) {
        my $sql = $migration{$target};
        die "Missing migration $target; database remains at $current\n"
            unless defined $sql;
        my @statements = grep { /\S/ } split /;/, $sql;
        die "Empty migration $target\n" unless @statements;
        my $has_version_marker = 0;
        for my $statement (@statements) {
            $statement =~ s/^\s+|\s+$//g;
            if ($statement =~ /^UPDATE\s+setup\s+SET\s+dbVersion\s*=\s*\Q$target\E\s*$/i) {
                $has_version_marker++;
                next;
            }
            my $guard_sql = $statement;
            $guard_sql =~ s/\bON\s+DELETE\s+CASCADE\b//ig; # Foreign-key action is not a DELETE statement.
            die "Unsafe SQL in migration $target: DROP/DELETE/TRUNCATE/RENAME\n"
                if $guard_sql =~ /\b(?:DROP|DELETE|TRUNCATE|RENAME)\b/i;
            $dbh->do($statement);
        }
        die "Expected one version marker for migration $target\n"
            unless $has_version_marker == 1;
        my $updated = $dbh->do(
            'UPDATE setup SET dbVersion = ? WHERE dbVersion = ?',
            undef, $target, $target - 1
        );
        die "Migration $target completed but version marker not updated\n"
            unless $updated == 1;
        print "DB migration $target applied\n";
        $current = $target;
    }
    print "DB schema version $current matches checkout\n";
    1;
};
my $error = $@;
$dbh->selectrow_array('SELECT RELEASE_LOCK(?)', undef, $lock);
$dbh->disconnect;
die "DB migration stopped: $error" unless $ok;
