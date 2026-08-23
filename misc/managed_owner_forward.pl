#!/usr/bin/perl
use strict;
use warnings;
use lib ('/root/taransvar/perl');
use func;
use JSON::PP qw(encode_json);
use Digest::SHA qw(hmac_sha256_hex);
use HTTP::Tiny;

my $CONF = '/etc/tarasec-managed-server.conf';
exit 0 unless -r $CONF;

sub read_conf {
    my %c;
    open my $fh, '<', $CONF or die "Cannot read $CONF: $!\n";
    while (my $line = <$fh>) {
        chomp $line; $line =~ s/^\s+|\s+$//g;
        next if $line eq '' || $line =~ /^#/;
        if ($line =~ /^([A-Za-z0-9_]+)=(.*)$/) { $c{$1} = $2; }
    }
    close $fh;
    return \%c;
}

my $cfg = read_conf();
my $url = $cfg->{GLOBAL_DB_REGISTER_URL} // '';
my $secret = $cfg->{GLOBAL_DB_SHARED_SECRET} // '';
exit 0 if $url eq '' || $secret eq '';
die "GLOBAL_DB_REGISTER_URL must use HTTPS\n" unless $url =~ m{^https://}i;

my $dbh = getConnection();
my $sth = $dbh->prepare(q{
    SELECT managedInstallationId, installationUuid, ownerName, ownerEmail,
           ownerPhone, ownerAddress, siteName, siteAddress, country, hostname,
           machineId, CAST(paymentAvailable AS UNSIGNED) AS paymentAvailable
      FROM managedInstallation
     WHERE globalForwardedTime IS NULL AND disabledTime IS NULL
     ORDER BY managedInstallationId LIMIT 50
});
$sth->execute();
my $http = HTTP::Tiny->new(timeout => 20, verify_SSL => 1);

while (my $r = $sth->fetchrow_hashref()) {
    my $body = encode_json({
        installation_uuid      => $r->{installationUuid},
        source_installation_id => 0 + $r->{managedInstallationId},
        owner_name             => $r->{ownerName},
        owner_email            => $r->{ownerEmail},
        owner_phone            => $r->{ownerPhone},
        owner_address          => $r->{ownerAddress},
        site_name              => $r->{siteName},
        site_address           => $r->{siteAddress},
        country                => $r->{country},
        hostname               => $r->{hostname},
        machine_id             => $r->{machineId},
        payment_available      => $r->{paymentAvailable} ? JSON::PP::true : JSON::PP::false,
    });
    my $ts = time();
    my $sig = hmac_sha256_hex($ts . "\n" . $body, $secret);
    my $res = $http->post($url, {
        headers => {
            'content-type' => 'application/json',
            'x-tarasec-timestamp' => $ts,
            'x-tarasec-signature' => $sig,
        },
        content => $body,
    });

    if ($res->{success}) {
        my $u = $dbh->prepare('UPDATE managedInstallation SET globalForwardedTime=NOW(), globalForwardAttempts=globalForwardAttempts+1, globalForwardError=NULL WHERE managedInstallationId=?');
        $u->execute($r->{managedInstallationId});
        print "Forwarded managed installation $r->{managedInstallationId} ($r->{installationUuid})\n";
    } else {
        my $err = "HTTP " . ($res->{status} // 0) . " " . ($res->{reason} // 'unknown');
        $err .= ': ' . substr(($res->{content} // ''), 0, 300) if defined $res->{content};
        my $u = $dbh->prepare('UPDATE managedInstallation SET globalForwardAttempts=globalForwardAttempts+1, globalForwardError=? WHERE managedInstallationId=?');
        $u->execute(substr($err,0,500), $r->{managedInstallationId});
        warn "Global owner registration failed for $r->{managedInstallationId}: $err\n";
    }
}
$sth->finish();
$dbh->disconnect();
