#!/usr/bin/perl
use strict;
use warnings;
use lib ('/root/taransvar/perl');
use DBI;
use Digest::SHA qw(sha256_hex);
use func;

# Generates the long-lived manager credential and the separate email
# verification token as soon as a managerRequest exists. Approval does not
# control generation; it controls whether the credential becomes active.
# Intended to be called by crontasks.pl once per iteration.

sub random_hex_32 {
    my $value = `openssl rand -hex 32 2>/dev/null`;
    chomp $value;
    die "Unable to generate secure random value with openssl\n"
        unless $value =~ /^[0-9a-f]{64}$/;
    return $value;
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

# Older gateways will not have the B8 table until managerAuth.php has received
# its first request (temporary B8 test bootstrap), so simply do nothing there.
eval { $select->execute(); 1 } or do {
    print "managerRequest table not available yet; nothing to generate.\n";
    $dbh->disconnect();
    exit 0;
};

my $update = $dbh->prepare(q{
    UPDATE managerRequest
       SET credentialPlain = ?,
           credentialHash = ?,
           credentialCreatedTime = NOW(),
           emailVerifyTokenPlain = ?,
           emailVerifyTokenHash = ?,
           active = IF(emailVerifiedTime IS NOT NULL AND gatewayApprovedTime IS NOT NULL, b'1', active)
     WHERE managerRequestId = ?
       AND credentialHash IS NULL
});

while (my $row = $select->fetchrow_hashref()) {
    my $credential = random_hex_32();
    my $email_token = random_hex_32();
    my $credential_hash = sha256_hex($credential);
    my $email_hash = sha256_hex($email_token);

    $update->execute($credential, $credential_hash, $email_token, $email_hash,
                     $row->{managerRequestId});
    print "Generated manager credential and email token for request $row->{managerRequestId}.\n"
        if $update->rows > 0;
}

$update->finish();
$select->finish();
$dbh->disconnect();
