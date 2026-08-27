#!/usr/bin/perl
use strict;
use warnings;

# Configure a Wi-Fi interface as a TaraSec hotspot without disturbing
# the currently active Internet uplink. NetworkManager is the supported
# backend on ordinary Ubuntu systems.

my $wifi_if = shift @ARGV // '';
my $requested_ssid = shift @ARGV // '';
my $addr    = shift @ARGV // '192.168.50.1/24';

sub sh {
    my ($cmd) = @_;
    print "+ $cmd\n";
    system($cmd) == 0 or die "Command failed: $cmd\n";
}

sub valid_short_name {
    my ($name) = @_;
    return $name =~ /^[A-Za-z0-9][A-Za-z0-9_-]{0,15}$/;
}

if ($> != 0) {
    die "Run as root, for example: sudo perl setupWifiNicAsHotspot.pl wlp5s0\n";
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

# A fresh/previously wired-only host may have Wi-Fi software-disabled. Enable
# it before scanning. A hardware rfkill remains an explicit error.
system('rfkill unblock wifi >/dev/null 2>&1');
system('nmcli radio wifi on >/dev/null 2>&1');
sleep 1;
my $wifi_state = `nmcli -t -f DEVICE,TYPE,STATE device status | grep '^\Q$wifi_if\E:wifi:' 2>/dev/null`;
if ($wifi_state =~ /:unavailable\s*$/) {
    die "Wi-Fi interface $wifi_if is still unavailable after enabling the radio. Check rfkill/hardware Wi-Fi switch.\n";
}

# Scan before AP mode so the installer can show the owner what nearby phone
# users currently see. A failed/blocked scan is informative, not fatal.
print "\nScanning nearby Wi-Fi networks on $wifi_if...\n";
my @nearby;
my $scan = `nmcli -t -f SSID device wifi list ifname '$wifi_if' --rescan yes 2>/dev/null`;
for my $s (split /\n/, $scan) {
    $s =~ s/\\:/:/g;
    next if $s eq '';
    push @nearby, $s unless grep { $_ eq $s } @nearby;
    last if @nearby >= 8;
}

print "\nChoose the Wi-Fi name for this TaraSec hotspot.\n";
print "People nearby will see it when they open the Wi-Fi list on their phone.\n";
if (@nearby) {
    print "\nNearby Wi-Fi names currently include:\n";
    print "    $_\n" for @nearby;
} else {
    print "\n(No nearby Wi-Fi names could be read; you can still choose the hotspot name.)\n";
}

chomp(my $hostname = `hostname -s 2>/dev/null`);
$hostname ||= 'hotspot';
$hostname =~ s/[^A-Za-z0-9_-]//g;
$hostname = substr($hostname, 0, 16);
$hostname = 'hotspot' unless valid_short_name($hostname);

my $ssid;
if ($requested_ssid ne '') {
    my $short = $requested_ssid;
    $short =~ s/^TaraSec_//;
    die "Invalid hotspot name '$short'. Use 1-16 characters: letters, digits, '-' or '_', starting with a letter or digit.\n"
        unless valid_short_name($short);
    $ssid = "TaraSec_$short";
} else {
    while (1) {
        print "\nHotspot name [$hostname]: ";
        my $short = <STDIN>;
        defined $short or die "No hotspot name received.\n";
        chomp $short;
        $short = $hostname if $short eq '';

        if (!valid_short_name($short)) {
            print "Please use 1-16 characters: letters, digits, '-' or '_'. No spaces.\n";
            next;
        }

        my $candidate = "TaraSec_$short";
        if (grep { lc($_) eq lc($candidate) } @nearby) {
            print "\nNote: '$candidate' is already visible nearby. Duplicate Wi-Fi names are allowed, but may be confusing locally.\n";
        }

        print "\nYour hotspot will appear in people's Wi-Fi list as:\n\n";
        print "        $candidate\n\n";
        print "Is this what you want? [Y/n]: ";
        my $answer = <STDIN>;
        defined $answer or die "No confirmation received.\n";
        chomp $answer;
        if ($answer eq '' || $answer =~ /^y(?:es)?$/i) {
            $ssid = $candidate;
            last;
        }
    }
}

my $profile = "tarasec-hotspot-$wifi_if";
system("nmcli connection delete '$profile' >/dev/null 2>&1");
sh("nmcli connection add type wifi ifname '$wifi_if' con-name '$profile' autoconnect yes ssid '$ssid'");
sh("nmcli connection modify '$profile' 802-11-wireless.mode ap ipv4.method shared ipv4.addresses '$addr' ipv6.method disabled connection.autoconnect-priority 100");
sh("nmcli connection up '$profile'");

print "\nTaraSec hotspot interface configured.\n";
print "WAN:     ".($wan_if || '<unknown>')." (left unchanged)\n";
print "Hotspot: $wifi_if\n";
print "SSID:    $ssid\n";
print "Address: $addr\n";
print "Profile: $profile\n";
