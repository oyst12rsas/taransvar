#!/usr/bin/perl
use strict;
use warnings;
use lib ('/root/taransvar/perl');
use func;
use File::Path qw(make_path);
use File::Copy qw(move);

my $IPFM_DIR = '/var/log/ipfm/subnet/minute';
my $ARCHIVE_DIR = "$IPFM_DIR/archived";

sub valid_ipv4 {
    my ($ip) = @_;
    return 0 unless defined $ip;
    return 0 unless $ip =~ /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/;
    return ($1 <= 255 && $2 <= 255 && $3 <= 255 && $4 <= 255) ? 1 : 0;
}

sub parse_file_timestamp {
    my ($file) = @_;
    return sprintf('%04d-%02d-%02d %02d:%02d', $1, $2, $3, $4, $5)
        if $file =~ /(\d{4})-(\d{1,2})-(\d{1,2})-(\d{1,2})-(\d{1,2})$/;
    return undef;
}

my $dbh = getConnection();
my $sth_setup = $dbh->prepare(q{
    SELECT CAST(hotspot AS UNSIGNED) AS hotspot,
           INET_NTOA(internalIP) AS internalIP
      FROM setup
     LIMIT 1
});
$sth_setup->execute();
my $setup = $sth_setup->fetchrow_hashref() || {};
$sth_setup->finish();

if (!$setup->{hotspot}) {
    print "Hotspot disabled; usage accounting skipped.\n";
    $dbh->disconnect();
    exit 0;
}

if (!-d $IPFM_DIR) {
    print "IPFM directory $IPFM_DIR does not exist; usage accounting skipped.\n";
    $dbh->disconnect();
    exit 0;
}

make_path($ARCHIVE_DIR) unless -d $ARCHIVE_DIR;

my $internal_ip = $setup->{internalIP} // '';
my $broadcast = '';
if (valid_ipv4($internal_ip)) {
    my @octets = split /\./, $internal_ip;
    $broadcast = join('.', @octets[0..2], 255);
}

my $sth_session = $dbh->prepare(q{
    SELECT sessionid, username
      FROM session
     WHERE ip = ?
     ORDER BY active DESC, logouttime, sessionid DESC
     LIMIT 1
});
my $sth_touch = $dbh->prepare('UPDATE session SET lastrequest = NOW() WHERE sessionid = ?');
my $sth_usage = $dbh->prepare(q{
    INSERT INTO userusage (user, ip, yyyymmddhh, mb)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE mb = VALUES(mb)
});

opendir(my $dh, $IPFM_DIR) or die "Cannot open $IPFM_DIR: $!\n";
my @files = sort grep {
    $_ ne '.' && $_ ne '..' && $_ ne 'archived' && $_ ne 'resolved' && -f "$IPFM_DIR/$_"
} readdir($dh);
closedir($dh);

my $processed = 0;
my $rows = 0;

for my $file (@files) {
    my $stamp = parse_file_timestamp($file);
    if (!defined $stamp) {
        warn "Skipping IPFM file with unknown timestamp format: $file\n";
        next;
    }

    my $path = "$IPFM_DIR/$file";
    open(my $fh, '<', $path) or do {
        warn "Cannot read $path: $!\n";
        next;
    };

    my $file_ok = 1;
    while (my $line = <$fh>) {
        chomp $line;
        next unless $line =~ /^(\S+)\s+(\d+)\s+(\d+)\s+(\d+)\s*$/;
        my ($ip, $bytes) = ($1, $4);
        next unless valid_ipv4($ip);
        next if $internal_ip ne '' && $ip eq $internal_ip;
        next if $broadcast ne '' && $ip eq $broadcast;

        $sth_session->execute($ip);
        my $session = $sth_session->fetchrow_hashref();
        $sth_session->finish();
        my $user = $session ? ($session->{username} // '') : '';

        if ($session && defined $session->{sessionid}) {
            $sth_touch->execute($session->{sessionid});
            $sth_touch->finish();
        }

        # Preserve the historical accounting behaviour for unknown IPs while
        # keeping them visibly separate from authenticated accounts.
        $user = '???' if $user eq '';
        my $mb = $bytes / (1024 * 1024);
        eval {
            $sth_usage->execute($user, $ip, $stamp, $mb);
            $sth_usage->finish();
            1;
        } or do {
            warn "Failed storing usage for $ip from $file: $@\n";
            $file_ok = 0;
            last;
        };
        $rows++;
    }
    close($fh);

    next unless $file_ok;
    my $dest = "$ARCHIVE_DIR/$file";
    if (-e $dest) {
        my $suffix = time();
        $dest .= ".$suffix";
    }
    move($path, $dest) or do {
        warn "Unable to archive $path to $dest: $!\n";
        next;
    };
    $processed++;
}

# Recalculate quota usage from the accounting table. This is intentionally
# separate from firewall/access changes: the access controller or openNDS will
# consume mbusage on its own next refresh/authentication cycle.
$dbh->do(q{
    UPDATE radcheck r
    JOIN (
        SELECT user, SUM(mb) AS mbusage
          FROM userusage
         WHERE user <> '???'
         GROUP BY user
    ) u ON u.user = r.username
       SET r.mbusage = u.mbusage
});

$sth_session->finish();
$sth_touch->finish();
$sth_usage->finish();
$dbh->disconnect();

print "Hotspot usage accounting complete: $processed IPFM files, $rows usage rows.\n";
