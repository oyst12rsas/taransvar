#!/usr/bin/perl
use strict;
use warnings;
use DBI;
use Sys::Syslog qw(:standard :macros);
use Sys::Hostname qw(hostname);
use lib ('/root/taransvar/perl');
use func;

# Emit enriched TaraSec security events after the gateway has correlated a
# hackReport with a local unit. Wazuh/SIEM receives only the pseudonymous
# unitId plus the external tuple used for global correlation; no owner PII or
# internal LAN address is included.

my $state_file = '/var/lib/tarasec/soc_last_report_id';
my $poll_seconds = 2;

sub read_last_id {
    return 0 unless -r $state_file;
    open my $fh, '<', $state_file or return 0;
    my $id = <$fh> // 0;
    close $fh;
    $id =~ s/\D//g;
    return $id || 0;
}

sub write_last_id {
    my ($id) = @_;
    my $dir = '/var/lib/tarasec';
    mkdir $dir unless -d $dir;
    open my $fh, '>', $state_file or die "Cannot write $state_file: $!";
    print {$fh} "$id\n";
    close $fh;
}

sub safe_value {
    my ($value) = @_;
    $value //= '';
    $value =~ s/[\r\n\t]/ /g;
    $value =~ s/\s+/ /g;
    $value =~ s/"/'/g;
    return $value;
}

openlog('tarasec', 'pid,nofatal', LOG_AUTHPRIV);

my $last_id = read_last_id();
my $dbh;

while (1) {
    eval {
        $dbh = getConnection() unless $dbh && eval { $dbh->ping };

        my $gateway = hostname();
        eval {
            my $sth = $dbh->prepare('SELECT nickname FROM setup LIMIT 1');
            $sth->execute();
            if (my ($nickname) = $sth->fetchrow_array()) {
                $gateway = $nickname if defined $nickname && $nickname ne '';
            }
            $sth->finish();
        };

        my $sth = $dbh->prepare(q{
            SELECT
                H.reportId,
                INET_NTOA(H.ip) AS public_ip,
                H.port AS public_port,
                H.unitId,
                H.why,
                COALESCE((
                    SELECT I.severity
                    FROM internalInfections I
                    WHERE I.unitId = H.unitId
                    ORDER BY I.infectionId DESC
                    LIMIT 1
                ), 7) AS severity
            FROM hackReport H
            WHERE H.reportId > ?
              AND H.unitId IS NOT NULL
              AND H.unitId > 0
            ORDER BY H.reportId
        });
        $sth->execute($last_id);

        while (my $row = $sth->fetchrow_hashref()) {
            my $why = safe_value($row->{why});
            my $event = ($why =~ /sinkhole/i) ? 'sinkhole_hit' : 'threat_report';
            my $message = sprintf(
                'TARASEC event=%s unit_id=%u public_ip=%s public_port=%u gateway="%s" severity=%u report_id=%u reason="%s"',
                $event,
                $row->{unitId} || 0,
                safe_value($row->{public_ip}),
                $row->{public_port} || 0,
                safe_value($gateway),
                $row->{severity} || 0,
                $row->{reportId} || 0,
                $why
            );

            syslog(LOG_WARNING, '%s', $message);
            print "$message\n";

            $last_id = $row->{reportId} if $row->{reportId} > $last_id;
            write_last_id($last_id);
        }
        $sth->finish();
        1;
    } or do {
        my $err = $@ || 'unknown SOC worker error';
        chomp $err;
        syslog(LOG_ERR, 'TARASEC event=soc_worker_error message="%s"', safe_value($err));
        eval { $dbh->disconnect() if $dbh };
        undef $dbh;
    };

    sleep $poll_seconds;
}

closelog();
