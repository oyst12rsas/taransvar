#!/usr/bin/perl
use strict;
use warnings;
use HTTP::Tiny;
use JSON::PP qw(decode_json);

my ($gateway,$token) = @ARGV;
die "Usage: perl tarasec_unit_status.pl <gateway-url> <64-hex-token>\n" unless $gateway && $token;
$gateway = "http://$gateway" unless $gateway =~ m{^https?://}i;
$gateway =~ s{/$}{};
die "Token must be 64 hex characters\n" unless $token =~ /^[A-Fa-f0-9]{64}$/;

my $http = HTTP::Tiny->new(timeout=>10,agent=>'TaraSec-Unit-Agent/0.2');
my $res = $http->get("$gateway/script/unitStatus.php", {
    headers => {Authorization => "Bearer $token"},
});
my $body = $res->{content} // '';
die "Status failed: HTTP ".($res->{status}//0)." ".($res->{reason}//'')."\n$body\n" unless $res->{success};
my $j = eval { decode_json($body) };
die "Invalid JSON response: $body\n" if $@ || ref($j) ne 'HASH' || !$j->{ok};

my $u = $j->{unit} || {};
my $t = $j->{threat} || {};
print "Unit ".($u->{unitId}//'?')." owner ".(defined $u->{ownerId}?$u->{ownerId}:'?')." ".($u->{hostname}//'')."\n";
print "Warning: ".($t->{warning}?'YES':'no')."  severity=".($t->{severity}//0)."\n";
print "Confirmed local infection: ".($t->{confirmedLocalInfection}?'YES':'no')."\n";
print "Recent threat records (24h): ".($t->{recentThreatRecords24h}//0)."\n";
print "AI severity: ".($t->{aiSeverity}//0)."\n";
print "AI category: ".($t->{aiCategory}//'')."\n";
print "AI summary: ".($t->{aiSummary}//'')."\n";
