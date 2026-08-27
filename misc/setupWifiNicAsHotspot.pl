#!/usr/bin/perl
use strict;
use warnings;

# Configure a Wi-Fi interface as a TaraSec hotspot without disturbing
# the currently active Internet uplink. NetworkManager is the supported
# backend on ordinary Ubuntu systems.

my $wifi_if = shift @ARGV // '';
my $ssid    = shift @ARGV // 'TaraSec';
my $addr    = shift @ARGV // '192.168.50.1/24';

sub sh {
    my ($cmd) = @_;
    print "+ $cmd\n";
    system($cmd) == 0 or die "Command failed: $cmd\n";
}

if ($> != 0) {
    die "Run as root, for example: sudo perl setupWifiNicAsHotspot.pl wlp5s0 TaraSec\n";
}

if (!$wifi_if) {
    chomp($wifi_if = `nmcli -t -f DEVICE,TYPE device status | awk -F: '$2=="wifi" {print $1; exit}'`);
}

die "No Wi-Fi interface found. Specify it as the first argument.\n" unless $wifi_if;

die "NetworkManager is not running. TaraSec hotspot setup will not switch this host to systemd-networkd.\n"
    unless system('systemctl is-active --quiet NetworkManager') == 0;

# Never select the current default-route interface as the client-side hotspot.
chomp(my $wan_if = `ip -4 route show default | awk 'NR==1 {print $5}'`);
if ($wan_if && $wan_if eq $wifi_if) {
    die "Refusing to convert $wifi_if to hotspot because it is the active Internet uplink. Connect another uplink first.\n";
}

# Check AP support before modifying the interface.
my $iw = `iw list 2>/dev/null`;
die "Wi-Fi hardware does not advertise AP mode support.\n"
    unless $iw =~ /Supported interface modes:.*?\*\s+AP\b/s;

my $profile = "tarasec-hotspot-$wifi_if";

# Remove only our own previous profile, never arbitrary user Wi-Fi profiles.
system("nmcli connection delete '$profile' >/dev/null 2>&1");

# ipv4.method shared gives the AP a private address, DHCP/DNS service and NAT
# through NetworkManager. openNDS can then enforce captive-portal policy on
# this client-side interface without reconfiguring the WAN.
sh("nmcli connection add type wifi ifname '$wifi_if' con-name '$profile' autoconnect yes ssid '$ssid'");
sh("nmcli connection modify '$profile' 802-11-wireless.mode ap ipv4.method shared ipv4.addresses '$addr' ipv6.method disabled connection.autoconnect-priority 100");
sh("nmcli connection up '$profile'");

print "\nTaraSec hotspot interface configured.\n";
print "WAN:     ".($wan_if || '<unknown>')." (left unchanged)\n";
print "Hotspot: $wifi_if\n";
print "SSID:    $ssid\n";
print "Address: $addr\n";
print "Profile: $profile\n";
