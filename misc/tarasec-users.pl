#!/usr/bin/perl
use strict;
use warnings;
use DBI;
use Getopt::Long qw(GetOptions);

my $set_admin_password;
my $help;
GetOptions(
    'set-admin-password:s' => \$set_admin_password,
    'help'                 => \$help,
) or usage(1);

usage(0) if $help;

if ($> != 0) {
    die "This command must be run as root. Use: sudo tarasec-users\n";
}

my $dbh = DBI->connect(
    'DBI:mysql:database=taransvar',
    'root',
    '',
    {
        RaiseError => 1,
        PrintError => 0,
        AutoCommit => 1,
        mysql_enable_utf8mb4 => 1,
    }
) or die "Unable to connect to TaraSec database.\n";

if (defined $set_admin_password) {
    set_admin_password($dbh, $set_admin_password);
    exit 0;
}

print "=== TARASEC ACCESS ACCOUNTS ===\n\n";
show_admins($dbh);
print "\n";
show_hotspot_users($dbh);

print <<'TXT';

Admin passwords are intentionally not displayed.
To set or reset an administrator password:
  sudo tarasec-users --set-admin-password

To target a specific administrator:
  sudo tarasec-users --set-admin-password USERNAME
TXT

$dbh->disconnect;
exit 0;

sub show_admins {
    my ($dbh) = @_;
    print "Back-office administrators\n";
    print "--------------------------\n";

    my $sth = $dbh->prepare(q{
        SELECT userId, username,
               CAST(isAdmin AS UNSIGNED) AS isAdmin,
               suspendedUntil,
               lastLogin
          FROM user
         WHERE CAST(isAdmin AS UNSIGNED) = 1
         ORDER BY username
    });
    $sth->execute;

    my $count = 0;
    while (my $row = $sth->fetchrow_hashref) {
        $count++;
        my $status = 'enabled';
        if (defined $row->{suspendedUntil} && $row->{suspendedUntil} ne '') {
            $status = "suspended until $row->{suspendedUntil}";
        }
        my $last = $row->{lastLogin} // 'never';
        printf "  %-24s  %-28s  last login: %s\n", $row->{username}, $status, $last;
    }
    print "  (none configured)\n" if !$count;
    $sth->finish;
}

sub show_hotspot_users {
    my ($dbh) = @_;
    print "Valid hotspot subscriber logins\n";
    print "-------------------------------\n";

    my $sql = q{
        SELECT username, value, subscriptionType, expirytime,
               giveHoursAfterLogin, mbquota, COALESCE(mbusage,0) AS mbusage,
               attribute, op, confirmedTime, last_login
          FROM radcheck
         WHERE ((op=':=' AND attribute='Cleartext-Password')
             OR (op='==' AND COALESCE(attribute,'')=''))
         ORDER BY username
    };

    my $sth;
    eval {
        $sth = $dbh->prepare($sql);
        $sth->execute;
    };
    if ($@) {
        print "  Unable to read current hotspot subscriber schema.\n";
        return;
    }

    my $shown = 0;
    my $now = time();
    while (my $row = $sth->fetchrow_hashref) {
        my $type = $row->{subscriptionType} // '';
        my $quota_ok = 1;
        if ($type eq 'quota' || $type eq 'limited') {
            my $quota = 0 + ($row->{mbquota} // 0);
            my $used  = 0 + ($row->{mbusage} // 0);
            $quota_ok = $quota > $used;
        }

        my $expiry_ok = 1;
        if ($type eq 'expiry' || $type eq 'limited') {
            if (!defined($row->{expirytime}) || $row->{expirytime} eq '') {
                # A deferred expiry account is valid before first login when
                # giveHoursAfterLogin will establish the expiry time.
                $expiry_ok = (0 + ($row->{giveHoursAfterLogin} // 0)) > 0;
            } else {
                my ($date, $time) = split / /, $row->{expirytime}, 2;
                if ($date && $time && $row->{expirytime} lt sql_now()) {
                    $expiry_ok = 0;
                }
            }
        }

        my $legacy = (($row->{op} // '') eq '==' && ($row->{attribute} // '') eq '');
        my $confirmed = $legacy || (defined($row->{confirmedTime}) && $row->{confirmedTime} ne '');
        next unless $quota_ok && $expiry_ok && $confirmed;

        $shown++;
        my $validity = describe_validity($row);
        # Subscriber credentials are deliberately shown: these are captive-
        # portal access credentials, not privileged administrator credentials.
        printf "  %-18s password: %-18s %s\n",
            $row->{username}, ($row->{value} // ''), $validity;
    }
    print "  (no currently valid hotspot subscriber logins)\n" if !$shown;
    $sth->finish;
}

sub describe_validity {
    my ($row) = @_;
    my $type = $row->{subscriptionType} // '';
    my @parts;

    if (($type eq 'expiry' || $type eq 'limited')) {
        if (defined($row->{expirytime}) && $row->{expirytime} ne '') {
            push @parts, "expires $row->{expirytime}";
        } elsif ((0 + ($row->{giveHoursAfterLogin} // 0)) > 0) {
            push @parts, ($row->{giveHoursAfterLogin} + 0) . " hour(s) from first login";
        }
    }

    if ($type eq 'quota' || $type eq 'limited') {
        my $quota = 0 + ($row->{mbquota} // 0);
        my $used  = 0 + ($row->{mbusage} // 0);
        my $left  = $quota - $used;
        $left = 0 if $left < 0;
        push @parts, sprintf('%.0f MB remaining', $left);
    }

    return @parts ? '(' . join(', ', @parts) . ')' : '';
}

sub set_admin_password {
    my ($dbh, $requested_user) = @_;

    my @admins;
    my $sth = $dbh->prepare(q{
        SELECT username
          FROM user
         WHERE CAST(isAdmin AS UNSIGNED) = 1
         ORDER BY username
    });
    $sth->execute;
    while (my ($u) = $sth->fetchrow_array) {
        push @admins, $u;
    }
    $sth->finish;

    die "No administrator account exists.\n" if !@admins;

    my $username = defined($requested_user) && $requested_user ne ''
        ? $requested_user
        : (@admins == 1 ? $admins[0] : choose_admin(\@admins));

    my %is_admin = map { $_ => 1 } @admins;
    die "'$username' is not an administrator account.\n" if !$is_admin{$username};

    print "Setting password for administrator '$username'.\n";
    my $p1 = read_hidden('New password: ');
    my $p2 = read_hidden('Repeat password: ');

    die "Passwords did not match.\n" if $p1 ne $p2;
    die "Password must be at least 10 characters.\n" if length($p1) < 10;

    my $upd = $dbh->prepare(q{
        UPDATE user
           SET password = ?,
               loginFailsSinceSuccess = 0,
               loginFailReportedTime = NULL,
               suspendedUntil = NULL
         WHERE username = ?
           AND CAST(isAdmin AS UNSIGNED) = 1
    });
    $upd->execute($p1, $username);
    my $rows = $upd->rows;
    $upd->finish;

    die "Administrator password was not changed.\n" if $rows < 1;
    print "Administrator password updated for '$username'.\n";
    print "The password was not printed or logged by this helper.\n";
}

sub choose_admin {
    my ($admins) = @_;
    print "Administrators:\n";
    for my $i (0 .. $#$admins) {
        printf "  %d) %s\n", $i + 1, $admins->[$i];
    }
    print "Select administrator: ";
    chomp(my $answer = <STDIN> // '');
    die "Invalid selection.\n" if $answer !~ /^\d+$/ || $answer < 1 || $answer > @$admins;
    return $admins->[$answer - 1];
}

sub read_hidden {
    my ($prompt) = @_;
    print $prompt;
    system('stty', '-echo') == 0 or die "Unable to disable terminal echo.\n";
    my $value = <STDIN>;
    my $status = system('stty', 'echo');
    print "\n";
    die "Unable to restore terminal echo.\n" if $status != 0;
    defined $value or die "No password entered.\n";
    chomp $value;
    return $value;
}

sub sql_now {
    my @t = localtime();
    return sprintf('%04d-%02d-%02d %02d:%02d:%02d',
        $t[5] + 1900, $t[4] + 1, $t[3], $t[2], $t[1], $t[0]);
}

sub usage {
    my ($exit) = @_;
    print <<'TXT';
Usage:
  sudo tarasec-users
  sudo tarasec-users --set-admin-password
  sudo tarasec-users --set-admin-password USERNAME

Without options, lists administrator usernames/status and currently valid hotspot
subscriber credentials. Administrator passwords are never displayed.
TXT
    exit $exit;
}
