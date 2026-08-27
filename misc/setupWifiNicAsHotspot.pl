#!/usr/bin/perl
use strict;
use warnings;

# Configure a Wi-Fi interface as a TaraSec captive-portal hotspot without
# disturbing the currently active Internet uplink.
#
# NetworkManager owns AP/DHCP/DNS/NAT using ipv4.method shared.
# openNDS enforces captive-portal access and reads NetworkManager's dnsmasq
# lease file directly, avoiding a second competing dnsmasq instance.

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

sub install_opennds {
    return if system('command -v ndsctl >/dev/null 2>&1') == 0;
    sh('apt-get update');
    sh('DEBIAN_FRONTEND=noninteractive apt-get install -y opennds');
    die "openNDS installation completed but ndsctl is unavailable.\n"
        unless system('command -v ndsctl >/dev/null 2>&1') == 0;
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

sub configure_opennds {
    my ($ifname, $gateway_name, $leases) = @_;
    my $legacy_conf = '/etc/opennds/opennds.conf';
    my $conf = '/etc/config/opennds';

    system('systemctl stop opennds >/dev/null 2>&1');
    sh('mkdir -p /etc/config');

    if (-f $conf && !-e "$conf.tarasec-before") {
        sh("cp '$conf' '$conf.tarasec-before'");
    } elsif (!-f $conf && -f $legacy_conf && !-e "$legacy_conf.tarasec-before") {
        sh("cp '$legacy_conf' '$legacy_conf.tarasec-before'");
    }

    my $cfg = '';
    if (-f $conf) {
        open my $fh, '<', $conf or die "Unable to read $conf: $!\n";
        local $/;
        $cfg = <$fh>;
        close $fh;
    }

    if ($cfg !~ /^\s*config\s+opennds\b/m) {
        $cfg = "config opennds 'opennds'\n";
        $cfg .= "\toption enabled '1'\n";
    }

    $cfg =~ s/^\s*GatewayInterface\s+.*\n//mig;
    $cfg =~ s/^\s*GatewayName\s+.*\n//mig;

    my %opts = (
        gatewayinterface => $ifname,
        gatewayname      => $gateway_name,
        dhcp_leases_file => $leases,
    );

    for my $name (sort keys %opts) {
        my $value = $opts{$name};
        if ($cfg =~ /^\s*option\s+\Q$name\E\s+.*$/mi) {
            $cfg =~ s/^\s*option\s+\Q$name\E\s+.*$/\toption $name '$value'/mi;
        } else {
            $cfg .= "\toption $name '$value'\n";
        }
    }

    open my $out, '>', $conf or die "Unable to write $conf: $!\n";
    print {$out} $cfg;
    close $out;

    for my $check (
        [gatewayinterface => $ifname],
        [gatewayname      => $gateway_name],
        [dhcp_leases_file => $leases],
    ) {
        my ($name, $expected) = @$check;
        my $parsed = `/usr/lib/opennds/libopennds.sh get_option_from_config $name 2>/dev/null`;
        chomp $parsed;
        die "openNDS config parser did not read $name '$expected' (got '$parsed').\n"
            unless $parsed eq $expected;
    }

    sh('systemctl enable opennds');
    sh('systemctl restart opennds');

    my $status = '';
    my $nds_ok = 0;
    for (1..30) {
        $status = `ndsctl status 2>&1`;
        if ($? == 0) {
            $nds_ok = 1;
            last;
        }
        sleep 1;
    }

    my $active = system('systemctl is-active --quiet opennds') == 0;
    if (!$active || !$nds_ok) {
        print STDERR "\nopenNDS verification failed.\n";
        print STDERR $status if $status ne '';
        print STDERR "Check: journalctl -u opennds --no-pager -n 100\n";
        die "TaraSec hotspot NOT complete: captive portal is not running.\n";
    }

    print "\nopenNDS captive portal verified on $ifname.\n";
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
# Remove only the TaraSec system-dnsmasq fragment left by earlier test builds.
# Do not disable the host's dnsmasq service; it may be used by libvirt or other software.
unlink '/etc/dnsmasq.d/tarasec-hotspot.conf' if -f '/etc/dnsmasq.d/tarasec-hotspot.conf';

my $profile = "tarasec-hotspot-$wifi_if";
system("nmcli connection down '$profile' >/dev/null 2>&1");
system("nmcli connection delete '$profile' >/dev/null 2>&1");
sh("nmcli connection add type wifi ifname '$wifi_if' con-name '$profile' autoconnect yes ssid '$ssid'");
sh("nmcli connection modify '$profile' 802-11-wireless.mode ap ipv4.method shared ipv4.addresses '$addr' ipv6.method disabled connection.autoconnect-priority 100");
sh("nmcli connection up '$profile'");

install_opennds();
my $leases = wait_for_nm_lease_file($wifi_if);
print "NetworkManager DHCP lease file: $leases\n";
configure_opennds($wifi_if, $ssid, $leases);

print "\nTaraSec hotspot interface configured and captive portal enforced.\n";
print "WAN:     $wan_if (left unchanged)\n";
print "Hotspot: $wifi_if\n";
print "SSID:    $ssid\n";
print "Address: $addr\n";
print "DHCP:    NetworkManager shared mode\n";
print "Leases:  $leases\n";
print "Profile: $profile\n";
print "Portal:  openNDS active and verified\n";
