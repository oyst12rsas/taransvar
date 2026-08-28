#!/usr/bin/perl
use strict;
use warnings;
use FindBin qw($Bin);

# Configure a Wi-Fi interface as a TaraSec captive-portal hotspot without
# disturbing the currently active Internet uplink. NetworkManager owns
# AP/DHCP/DNS/NAT; misc/install_opennds.sh owns all captive-portal integration.

my $wifi_if = shift @ARGV // '';
my $requested_ssid = shift @ARGV // '';
my $addr = shift @ARGV // '192.168.50.1/24';

sub sh {
    my ($cmd) = @_;
    print "+ $cmd\n";
    system($cmd) == 0 or die "Command failed: $cmd\n";
}

sub valid_short_name {
    my ($name) = @_;
    return $name =~ /^[A-Za-z0-9][A-Za-z0-9_-]{0,15}$/;
}

sub wait_for_nm_lease_file {
    my ($ifname) = @_;
    my $leases = "/var/lib/NetworkManager/dnsmasq-$ifname.leases";
    for (1..20) {
        return $leases if -e $leases;
        sleep 1;
    }
    die "NetworkManager hotspot is up, but expected DHCP lease file '$leases' was not created.\n";
}

if ($> != 0) {
    die "Run as root, for example: sudo perl misc/setupWifiNicAsHotspot.pl wlp5s0\n";
}

if (!$wifi_if) {
    chomp($wifi_if = `nmcli -t -f DEVICE,TYPE device status | awk -F: '\$2=="wifi" {print \$1; exit}'`);
}
die "No Wi-Fi interface found. Specify it as the first argument.\n" unless $wifi_if;
die "NetworkManager is not running.\n"
    unless system('systemctl is-active --quiet NetworkManager') == 0;

chomp(my $wan_if = `ip -4 route show default | awk 'NR==1 {print \$5}'`);
die "No IPv4 default-route interface found. Keep an Internet uplink connected before enabling the hotspot.\n"
    unless $wan_if;
die "Refusing to convert $wifi_if to hotspot because it is the active Internet uplink. Connect another uplink first.\n"
    if $wan_if eq $wifi_if;

my $iw = `iw list 2>/dev/null`;
die "Wi-Fi hardware does not advertise AP mode support.\n"
    unless $iw =~ /Supported interface modes:.*?\*\s+AP\b/s;

system('rfkill unblock wifi >/dev/null 2>&1');
system('nmcli radio wifi on >/dev/null 2>&1');
sleep 1;

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
    die "Invalid hotspot name '$short'. Use 1-16 characters: letters, digits, '-' or '_'.\n"
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
        print "\nNote: '$candidate' is already visible nearby. Duplicate Wi-Fi names are allowed, but may be confusing locally.\n"
            if grep { lc($_) eq lc($candidate) } @nearby;
        print "\nYour hotspot will appear in people's Wi-Fi list as:\n\n        $candidate\n\n";
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

system('systemctl stop opennds >/dev/null 2>&1');
unlink '/etc/dnsmasq.d/tarasec-hotspot.conf' if -f '/etc/dnsmasq.d/tarasec-hotspot.conf';

my $profile = "tarasec-hotspot-$wifi_if";
system("nmcli connection down '$profile' >/dev/null 2>&1");
system("nmcli connection delete '$profile' >/dev/null 2>&1");
sh("nmcli connection add type wifi ifname '$wifi_if' con-name '$profile' autoconnect yes ssid '$ssid'");
sh("nmcli connection modify '$profile' 802-11-wireless.mode ap ipv4.method shared ipv4.addresses '$addr' ipv6.method disabled connection.autoconnect-priority 100");
sh("nmcli connection up '$profile'");

my $leases = wait_for_nm_lease_file($wifi_if);
print "NetworkManager DHCP lease file: $leases\n";

# A fresh database may contain no hotspot subscribers yet. The account helper
# owns the migration/bootstrap logic and creates exactly one temporary setup
# subscriber when none exists. ThemeSpec then displays that sole login on the
# captive portal until another enabled subscriber is added.
my $users_helper = "$Bin/tarasec-users.pl";
die "Missing TaraSec account bootstrap helper: $users_helper\n" unless -f $users_helper;
install_users_helper($users_helper);
sh("perl '$users_helper'");

my $helper = "$Bin/install_opennds.sh";
die "Missing TaraSec openNDS installer: $helper\n" unless -f $helper;
$ENV{TARASEC_HOTSPOT_IF} = $wifi_if;
$ENV{TARASEC_HOTSPOT_ADDR} = $addr;
$ENV{TARASEC_HOTSPOT_NAME} = $ssid;
sh("bash '$helper'");

# Install the disconnect watcher only after the AP and openNDS are active.
# This ordering is shared by Ubuntu and Raspberry Pi OS and avoids starting the
# watcher against an interface that has not yet entered AP mode.
my $watch_helper = "$Bin/install_wifi_session_watch.sh";
die "Missing TaraSec Wi-Fi session watcher installer: $watch_helper\n" unless -f $watch_helper;
sh("bash '$watch_helper'");

my $status = `ndsctl status 2>&1`;
die "TaraSec hotspot NOT complete: ndsctl status failed after portal installation.\n$status\n" if $? != 0;
die "TaraSec hotspot NOT complete: custom ThemeSpec is not configured.\n"
    unless system("grep -q \"option themespec_path '/usr/lib/opennds/theme_tarasec.sh'\" /etc/config/opennds") == 0;
die "TaraSec hotspot NOT complete: Apache captive login is not listening on 8080.\n"
    unless system("ss -lnt | grep -q ':8080 '") == 0;
die "TaraSec hotspot NOT complete: Wi-Fi session watcher is not active.\n"
    unless system("systemctl is-active --quiet tarasec-wifi-session-watch.service") == 0;

print "\nTaraSec hotspot interface configured and custom captive portal enforced.\n";
print "WAN:     $wan_if (left unchanged)\n";
print "Hotspot: $wifi_if\n";
print "SSID:    $ssid\n";
print "Address: $addr\n";
print "DHCP:    NetworkManager shared mode\n";
print "Leases:  $leases\n";
print "Profile: $profile\n";
print "Portal:  TaraSec ThemeSpec active on openNDS\n";
print "Sessions: disconnect watcher active\n";

sub install_users_helper {
    my ($src) = @_;
    sh("install -m 0755 '$src' /usr/local/sbin/tarasec-users");
}
