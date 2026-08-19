#!/usr/bin/perl
use strict;
use warnings;
use lib ('/root/taransvar/perl');
use DBI;
use Digest::SHA qw(sha256_hex);
use HTTP::Tiny;
use JSON::PP qw(encode_json);
use func;

# Generates manager credentials/tokens locally. If MAIL_SERVICE_URL is set in
# /etc/tarasec.conf, unsent email-verification requests are submitted to the
# TaraSec back-office template mail service. Gateway approval remains local.

sub random_hex_32 {
    my $value = `openssl rand -hex 32 2>/dev/null`;
    chomp $value;
    die "Unable to generate secure random value with openssl\n"
        unless $value =~ /^[0-9a-f]{64}$/;
    return $value;
}

sub read_tarasec_config {
    my %cfg;
    my $file = '/etc/tarasec.conf';
    return \%cfg unless -r $file;
    open(my $fh, '<', $file) or return \%cfg;
    while (my $line = <$fh>) {
        chomp $line;
        $line =~ s/^\s+|\s+$//g;
        next if $line eq '' || $line =~ /^#/;
        my ($k, $v) = split(/\s*=\s*/, $line, 2);
        $cfg{$k} = $v if defined($k) && defined($v);
    }
    close($fh);
    return \%cfg;
}

my $dbh = getConnection();
my $select = $dbh->prepare(q{
    SELECT managerRequestId
    FROM managerRequest
    WHERE credentialHash IS NULL
      AND rejectedTime IS NULL
      AND (expires IS NULL OR expires > NOW())
    ORDER BY managerRequestId
    LIMIT 20
});

eval { $select->execute(); 1 } or do {
    print "managerRequest table not available yet; nothing to generate.\n";
    $dbh->disconnect();
    exit 0;
};

my $update = $dbh->prepare(q{
    UPDATE managerRequest
       SET credentialPlain = ?, credentialHash = ?, credentialCreatedTime = NOW(),
           emailVerifyTokenPlain = ?, emailVerifyTokenHash = ?,
           active = IF(emailVerifiedTime IS NOT NULL AND gatewayApprovedTime IS NOT NULL, b'1', active)
     WHERE managerRequestId = ? AND credentialHash IS NULL
});

while (my $row = $select->fetchrow_hashref()) {
    my $credential = random_hex_32();
    my $email_token = random_hex_32();
    $update->execute($credential, sha256_hex($credential), $email_token,
                     sha256_hex($email_token), $row->{managerRequestId});
    print "Generated manager credential and email token for request $row->{managerRequestId}.\n"
        if $update->rows > 0;
}
$update->finish();
$select->finish();

my $cfg = read_tarasec_config();
my $mail_url = $cfg->{MAIL_SERVICE_URL} // '';
if ($mail_url ne '') {
    my $nickname = '';
    eval {
        my $sth = $dbh->prepare('SELECT nickname FROM setup LIMIT 1');
        $sth->execute();
        my $r = $sth->fetchrow_hashref();
        $nickname = $r->{nickname} // '' if $r;
        $sth->finish();
    };
    $nickname = 'TaraSec gateway' if $nickname eq '';
    my $owner = $cfg->{MAIL_ROUTER_OWNER} // $nickname;

    my $pending = $dbh->prepare(q{
        SELECT managerRequestId, email, emailVerifyTokenPlain
          FROM managerRequest
         WHERE emailSentTime IS NULL
           AND emailVerifyTokenPlain IS NOT NULL
           AND rejectedTime IS NULL
           AND (expires IS NULL OR expires > NOW())
         ORDER BY managerRequestId
         LIMIT 20
    });
    $pending->execute();
    my $mark = $dbh->prepare(q{
        UPDATE managerRequest
           SET emailSentTime = NOW(), emailVerifyTokenPlain = NULL
         WHERE managerRequestId = ? AND emailSentTime IS NULL
    });
    my $http = HTTP::Tiny->new(timeout => 10);

    while (my $row = $pending->fetchrow_hashref()) {
        my $payload = encode_json({
            template => 'manager_activation',
            to => $row->{email},
            variables => {
                name => $row->{email},
                router_name => $nickname,
                router_owner => $owner,
                activation_code => $row->{emailVerifyTokenPlain},
            },
        });
        my $res = $http->post($mail_url, {
            headers => {'content-type' => 'application/json'},
            content => $payload,
        });
        if ($res->{success}) {
            $mark->execute($row->{managerRequestId});
            print "Queued manager verification email for request $row->{managerRequestId}.\n";
        } else {
            my $status = $res->{status} // 0;
            my $reason = $res->{reason} // 'unknown error';
            warn "Mail service failed for manager request $row->{managerRequestId}: HTTP $status $reason\n";
        }
    }
    $mark->finish();
    $pending->finish();
} else {
    print "MAIL_SERVICE_URL not configured; verification email delivery skipped.\n";
}

$dbh->disconnect();
