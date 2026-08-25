#!/usr/bin/env perl
use strict;
use warnings;
use DBI;
use lib '/usr/local/lib/tarasec';
use func;

# Print a single shell-safe, tab separated policy line:
# hasaccess session_minutes upload_kbit download_kbit upload_kb download_kb
#
# The access table is the enforcement source of truth.  Business logic that
# decides entitlement belongs elsewhere and writes the final decision here.

my $ip = shift // '';
if ($ip !~ /\A(?:\d{1,3}\.){3}\d{1,3}\z/) {
    print "0\t0\t0\t0\t0\t0\n";
    exit 0;
}

my $dbh = eval { getConnection() };
if (!$dbh) {
    print "0\t0\t0\t0\t0\t0\n";
    exit 0;
}

my ($has, $session, $up_rate, $down_rate, $up_quota, $down_quota) = (0,0,0,0,0,0);

# New installations carry the quota/rate columns. During rolling upgrades,
# gracefully fall back to the original three-column access table.
my $sql = q{
    SELECT hasaccess,
           COALESCE(sessionMinutes,0) AS sessionMinutes,
           COALESCE(uploadKbit,0) AS uploadKbit,
           COALESCE(downloadKbit,0) AS downloadKbit,
           COALESCE(uploadQuotaKB,0) AS uploadQuotaKB,
           COALESCE(downloadQuotaKB,0) AS downloadQuotaKB
      FROM access
     WHERE ip = ?
     LIMIT 1
};

my $row;
eval {
    my $sth = $dbh->prepare($sql);
    $sth->execute($ip);
    $row = $sth->fetchrow_hashref();
};

if (!$@ && $row) {
    $has        = $row->{hasaccess}       ? 1 : 0;
    $session    = int($row->{sessionMinutes}  // 0);
    $up_rate    = int($row->{uploadKbit}      // 0);
    $down_rate  = int($row->{downloadKbit}    // 0);
    $up_quota   = int($row->{uploadQuotaKB}   // 0);
    $down_quota = int($row->{downloadQuotaKB} // 0);
} else {
    eval {
        my $sth = $dbh->prepare('SELECT hasaccess FROM access WHERE ip = ? LIMIT 1');
        $sth->execute($ip);
        my $legacy = $sth->fetchrow_hashref();
        $has = ($legacy && $legacy->{hasaccess}) ? 1 : 0;
    };
}

$dbh->disconnect if $dbh;
print join("\t", $has, $session, $up_rate, $down_rate, $up_quota, $down_quota), "\n";
