#!/usr/bin/perl
use strict;
use warnings;
use HTTP::Tiny;
use JSON::PP qw(decode_json encode_json);

my $gateway = $ARGV[0] // '';
my $label = $ARGV[1] // '';
if ($gateway eq '') {
    my $route = `ip route show default 2>/dev/null | head -1`;
    ($gateway) = $route =~ /\bvia\s+(\d+\.\d+\.\d+\.\d+)/;
}
die "Usage: sudo perl tarasec_unit_pair.pl [gateway-ip-or-url] [label]\nUnable to discover default gateway.\n" unless $gateway;
$gateway = "http://$gateway" unless $gateway =~ m{^https?://}i;
$gateway =~ s{/$}{};

my $http = HTTP::Tiny->new(timeout => 10, agent => 'TaraSec-Unit-Agent/0.2');
my $form = 'label=' . $label;
$form =~ s/ /%20/g;
my $res = $http->post("$gateway/script/unitPair.php", {
    headers => {'content-type'=>'application/x-www-form-urlencoded'},
    content => $form,
});
my $body = $res->{content} // '';
die "Pairing failed: HTTP ".($res->{status}//0)." ".($res->{reason}//'')."\n$body\n" unless $res->{success};
my $json = eval { decode_json($body) };
die "Invalid pairing response: $body\n" if $@ || ref($json) ne 'HASH' || !$json->{ok};

print "Paired local TaraSec unit\n";
print "Gateway: ".($json->{gateway}//$gateway)."\n";
print "Unit ID: ".($json->{unit}{unitId}//'?')."\n";
print "Owner ID: ".(defined $json->{unit}{ownerId} ? $json->{unit}{ownerId} : '(not assigned)')."\n";
print "Hostname: ".($json->{unit}{hostname}//'')."\n";
print "Subscription ID: ".($json->{subscriptionId}//'')."\n\n";
print "PAIRING SECRET — copy/scan this into the TaraSec App only:\n";
print encode_json({
    version => 1,
    gateway => $gateway,
    statusPath => ($json->{remoteStatusPath}//'/script/unitStatus.php'),
    token => $json->{token},
    unitId => $json->{unit}{unitId},
    ownerId => $json->{unit}{ownerId},
    label => $label,
})."\n\n";
print "The gateway stores only a SHA-256 hash of this token. Treat the printed token like a password.\n";
