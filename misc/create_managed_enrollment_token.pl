#!/usr/bin/perl
use strict;
use warnings;
use Digest::SHA qw(sha256_hex);
use MIME::Base64 qw(encode_base64);
use lib ('/root/taransvar/perl');
use func;

my $email = $ARGV[0] // '';
my $hours = $ARGV[1] // 24;
die "Usage: sudo perl create_managed_enrollment_token.pl owner\@example.com [hours]\n" unless $email =~ /\@/;
die "hours must be 1..168\n" unless $hours =~ /^\d+$/ && $hours >= 1 && $hours <= 168;

open(my $ur, '<:raw', '/dev/urandom') or die "Cannot read /dev/urandom: $!\n";
read($ur, my $bytes, 32) == 32 or die "Unable to generate token\n";
close($ur);
my $token = encode_base64($bytes, '');
$token =~ tr!+/=!-_!d;
my $hash = sha256_hex($token);

my $dbh = getConnection();
my $sth = $dbh->prepare(q{
    INSERT INTO managedEnrollmentToken(created,tokenHash,ownerEmail,expires)
    VALUES (NOW(),?,?,DATE_ADD(NOW(), INTERVAL ? HOUR))
});
$sth->execute($hash, $email, $hours);
$dbh->disconnect();

print "One-time TaraSec managed enrollment token (shown only now):\n$token\n";
print "Owner: $email\nExpires in: $hours hour(s)\n";
