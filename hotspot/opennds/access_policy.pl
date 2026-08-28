#!/usr/bin/env perl
use strict;
use warnings;
use DBI;

my $ip = shift // '';
if ($ip !~ /\A(?:\d{1,3}\.){3}\d{1,3}\z/) {
    print "0\t0\t0\t0\t0\t0\n";
    exit 0;
}

my $dsn = $ENV{TARASEC_DSN} // 'DBI:mysql:database=taransvar;host=localhost';
my $user = $ENV{TARASEC_DB_USER} // 'scriptUsrAces3f3';
my $pass = $ENV{TARASEC_DB_PASS} // 'rErte8Oi98e-2_#';
my $dbh = eval { DBI->connect($dsn, $user, $pass, { RaiseError => 1, PrintError => 0, AutoCommit => 1 }) };
if (!$dbh) {
    print "0\t0\t0\t0\t0\t0\n";
    exit 0;
}

my ($has, $session, $up_rate, $down_rate, $up_quota, $down_quota) = (0,0,0,0,0,0);
my $row;
eval {
    my $sth = $dbh->prepare(q{
        SELECT hasaccess,
               COALESCE(sessionMinutes,0),
               COALESCE(uploadKbit,0),
               COALESCE(downloadKbit,0),
               COALESCE(uploadQuotaKB,0),
               COALESCE(downloadQuotaKB,0)
          FROM access WHERE ip=? LIMIT 1
    });
    $sth->execute($ip);
    $row = $sth->fetchrow_arrayref();
};

if (!$@ && $row) {
    ($has, $session, $up_rate, $down_rate, $up_quota, $down_quota) = map { int($_ // 0) } @$row;
    $has = $has ? 1 : 0;
} else {
    eval {
        my $sth = $dbh->prepare('SELECT hasaccess FROM access WHERE ip=? LIMIT 1');
        $sth->execute($ip);
        my ($legacy) = $sth->fetchrow_array();
        $has = $legacy ? 1 : 0;
    };
}
$dbh->disconnect;
print join("\t", $has, $session, $up_rate, $down_rate, $up_quota, $down_quota), "\n";
