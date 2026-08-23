#!/usr/bin/perl
use strict;
use warnings;
use lib ('/root/taransvar/perl');
use func;

my $KEY_FILE = '/etc/tarasec-opennds-fas.key';

sub service_exists {
    my ($name) = @_;
    return 1 if system('/bin/systemctl', 'status', $name, '--no-pager', '--quiet') == 0;
    my $listed = `/bin/systemctl list-unit-files '$name' --no-legend 2>/dev/null`;
    return $listed =~ /^\Q$name\E\s/m ? 1 : 0;
}

sub random_hex {
    open(my $fh, '<:raw', '/dev/urandom') or die "Cannot read /dev/urandom: $!\n";
    read($fh, my $buf, 32) == 32 or die "Unable to generate FAS key\n";
    close($fh);
    return unpack('H*', $buf);
}

sub read_or_create_key {
    if (-r $KEY_FILE) {
        open(my $fh, '<', $KEY_FILE) or die "Cannot read $KEY_FILE: $!\n";
        my $key = <$fh> // '';
        close($fh);
        chomp $key;
        return $key if $key ne '';
    }

    my $key = random_hex();
    open(my $fh, '>', $KEY_FILE) or die "Cannot write $KEY_FILE: $!\n";
    print $fh "$key\n";
    close($fh);
    chmod 0640, $KEY_FILE;
    system('/bin/chgrp', 'www-data', $KEY_FILE) if getgrnam('www-data');
    return $key;
}

if ($> != 0) {
    die "Run as root: sudo perl misc/opennds_configure.pl\n";
}

my $opennds = `command -v opennds 2>/dev/null`;
chomp $opennds;
if ($opennds eq '' && !service_exists('opennds.service')) {
    print "openNDS is not installed; leaving TaraSec hotspot access service enabled.\n";
    exit 0;
}

my $uci = `command -v uci 2>/dev/null`;
chomp $uci;
if ($uci eq '') {
    die "openNDS was found but UCI is unavailable. Current openNDS releases use /etc/config/opennds; install/configure UCI before enabling TaraSec FAS.\n";
}

my $dbh = getConnection();
my $sth = $dbh->prepare(q{
    SELECT CAST(hotspot AS UNSIGNED) AS hotspot,
           COALESCE(internalNic,'') AS internalNic,
           COALESCE(nickname,'TaraSec WiFi') AS nickname
      FROM setup LIMIT 1
});
$sth->execute();
my $setup = $sth->fetchrow_hashref() || {};
$sth->finish();
$dbh->disconnect();

if (!$setup->{hotspot}) {
    print "setup.hotspot is disabled; not enabling openNDS.\n";
    exit 0;
}

my $iface = $setup->{internalNic} // '';
die "Hotspot is enabled but setup.internalNic is empty\n" if $iface eq '';
die "Configured hotspot interface '$iface' does not exist\n" unless -d "/sys/class/net/$iface";

my $gatewayname = $setup->{nickname} || 'TaraSec WiFi';
$gatewayname =~ s/[^A-Za-z0-9 _.-]/_/g;
my $key = read_or_create_key();

my @sets = (
    "opennds.\@opennds[0].enabled=1",
    "opennds.\@opennds[0].gatewayinterface=$iface",
    "opennds.\@opennds[0].gatewayname=$gatewayname",
    "opennds.\@opennds[0].fasport=80",
    "opennds.\@opennds[0].faspath=/opennds/fas.php",
    "opennds.\@opennds[0].fas_secure_enabled=1",
    "opennds.\@opennds[0].faskey=$key",
);

for my $setting (@sets) {
    system($uci, 'set', $setting) == 0 or die "uci set failed for $setting\n";
}
system($uci, 'commit', 'opennds') == 0 or die "uci commit opennds failed\n";

# openNDS v10+ owns captive-portal packet filtering through nftables. Do not
# run TaraSec's iptables fallback controller at the same time.
system('/bin/systemctl', 'disable', '--now', 'tarasec-hotspot-access.service');
system('/bin/systemctl', 'enable', '--now', 'opennds.service') == 0
    or die "Unable to enable/start opennds.service\n";
system('/bin/systemctl', 'restart', 'opennds.service') == 0
    or die "Unable to restart opennds.service after configuration\n";

print "openNDS configured for TaraSec on $iface.\n";
print "FAS endpoint: /opennds/fas.php (secure level 1)\n";
print "Fallback tarasec-hotspot-access.service disabled while openNDS is active.\n";
