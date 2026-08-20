#!/usr/bin/perl
use strict;
use warnings;
use HTTP::Tiny;
use JSON::PP qw(decode_json encode_json);

# First TaraSec endpoint-unit agent slice.
# It deliberately works only while the machine is on its own TaraSec LAN.  The
# gateway resolves the HTTP source address through its DHCP/unit data and returns
# only this unit's status.  No manager credential is used.

sub default_gateway {
    my $route = `ip -4 route show default 2>/dev/null | head -1`;
    return $1 if $route =~ /\bvia\s+(\d+\.\d+\.\d+\.\d+)/;
    return '';
}

my $gateway = $ARGV[0] // default_gateway();
die "Usage: sudo perl tarasec_unit_agent.pl [gateway-ip-or-base-url]\nUnable to determine the default gateway automatically.\n"
    unless defined($gateway) && length($gateway);

my $base = $gateway;
$base = "http://$base" unless $base =~ m{^https?://}i;
$base =~ s{/+$}{};

my $url = "$base/script/unitSelf.php";
print "TaraSec Unit Agent (local enrollment/status test)\n";
print "Gateway: $base\n";
print "Requesting this machine's TaraSec unit identity...\n\n";

my $http = HTTP::Tiny->new(timeout => 10, agent => 'TaraSec-Unit-Agent/0.1');
my $res = $http->get($url);
my $body = $res->{content} // '';
my $json = eval { decode_json($body) };

if (!$res->{success} || !$json || !$json->{ok}) {
    my $error = ($json && $json->{error}) ? $json->{error} : "HTTP ".($res->{status}//0)." ".($res->{reason}//'');
    print "FAILED: $error\n";
    print "$body\n" if length($body) && !$json;
    exit 1;
}

my $unit = $json->{unit} || {};
my $threat = $json->{threat} || {};
print "Identified by gateway:\n";
print "  unitId:      ".($unit->{unitId}//'?')."\n";
print "  ownerId:     ".(defined($unit->{ownerId}) ? $unit->{ownerId} : '(not assigned yet)')."\n";
print "  hostname:    ".($unit->{hostname}//'')."\n";
print "  description: ".($unit->{description}//'')."\n";
print "  client IP:   ".($json->{clientIp}//'')."\n\n";

print "Threat state:\n";
print "  warning:     ".($threat->{warning} ? 'YES' : 'no')."\n";
print "  severity:    ".($threat->{severity}//0)." / 10\n";
print "  confirmed local infection: ".($threat->{confirmedLocalInfection} ? 'YES' : 'no')."\n";
print "  recent threat records (24h): ".($threat->{recentThreatRecords24h}//0)."\n";
print "  reason:      ".($threat->{why}//'')."\n";

print "\nPrivacy boundary: this response is available only from the unit's local TaraSec network and grants no manager access.\n";
print "Next development step: exchange this locally proven unit identity for an opaque pairing token that the phone can monitor remotely.\n";
