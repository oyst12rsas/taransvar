#!/usr/bin/perl
# Read-only check of the checkout's SQL version against the connected setup DB.
use strict;
use warnings;
use FindBin;
use lib $FindBin::Bin;
use DBI;
use func;

my $sql_file = "$FindBin::Bin/install.sql";
open my $fh, '<', $sql_file or die "Cannot read $sql_file: $!\n";
my $expected = 0;
while (my $line = <$fh>) {
    $expected = $1 if $line =~ /^#version\s+(\d+)\b/ && $1 > $expected;
}
close $fh;
die "No #version entries found in $sql_file\n" unless $expected;

my $dbh = eval { getConnection() };
die "DB version check unavailable: $@\n" if $@ || !$dbh;
my ($actual) = $dbh->selectrow_array("SELECT dbVersion FROM setup LIMIT 1");
$dbh->disconnect;
die "DB version check unavailable: no setup version\n" unless defined $actual;

if ($actual != $expected) {
    print STDERR "DB schema mismatch: checkout expects $expected; database has $actual. Run diagnose.pl separately after reviewing migrations.\n";
    exit 2;
}
print "DB schema version $actual matches checkout\n";
